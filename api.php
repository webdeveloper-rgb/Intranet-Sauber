<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($d, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}
function fail(string $m, int $code = 400): void
{
    out(['error' => $m], $code);
}

const MIN_PALABRAS = 15;
const MIN_PASSWORD = 10;

function exigir_admin(array $u): void
{
    if (($u['rol'] ?? '') !== 'admin') fail('Solo los administradores pueden hacer esto', 403);
}

function palabras(string $t): int
{
    return count(preg_split('/\s+/u', trim($t), -1, PREG_SPLIT_NO_EMPTY));
}

function txt($v, int $max): string
{
    return mb_substr(trim((string)$v), 0, $max);
}

function fila(array $r): array
{
    foreach (['id', 'revisado'] as $k) $r[$k] = (int)$r[$k];
    $r['intentos'] = $r['intentos'] !== null ? (int)$r['intentos'] : null;
    $r['notas'] = [];
    return $r;
}

const SQL_COPIA = "SELECT id, DATE_FORMAT(fecha,'%Y-%m-%d %H:%i') AS fecha, cliente, equipo, estado, desc_error,
    codigo, software, destino, tamano, duracion, intentos, revisado, revisado_por,
    DATE_FORMAT(revisado_fecha,'%Y-%m-%d %H:%i') AS revisado_fecha, revision_desc, seg,
    DATE_FORMAT(recordar,'%Y-%m-%d') AS recordar FROM copias";

function notas_de(array $ids): array
{
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT copia_id, usuario, texto, DATE_FORMAT(fecha,'%Y-%m-%d %H:%i') AS fecha
                         FROM notas WHERE copia_id IN ($in) ORDER BY fecha, id");
    $st->execute($ids);
    $m = [];
    foreach ($st as $n) {
        $m[(int)$n['copia_id']][] = ['usuario' => $n['usuario'], 'texto' => $n['texto'], 'fecha' => $n['fecha']];
    }
    return $m;
}

function copia(int $id): ?array
{
    $st = db()->prepare(SQL_COPIA . " WHERE id = ?");
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) return null;
    $r = fila($r);
    $r['notas'] = notas_de([$id])[$id] ?? [];
    return $r;
}

function copia_o_404($id): array
{
    $c = copia((int)$id);
    if (!$c) fail('Copia no encontrada', 404);
    return $c;
}

$action = $_GET['action'] ?? '';
$input  = json_decode((string)file_get_contents('php://input'), true);
$input  = is_array($input) ? $input : [];

try {
    // ---------- API DE ENTRADA DE COPIAS (pendiente) ----------
    // Aquí irá (o se hará en Node JS / otra API propia) la recepción de copias
    // desde los equipos. Debe insertar en la tabla `copias` (ver database/schema.sql).
    // Por ahora no existe ningún endpoint público de escritura.

    // ---------- Resto: requieren sesión ----------
    $user = usuario_actual();
    if (!$user) fail('Sesión caducada', 401);

    // Se revalida en cada petición: si desactivan o borran al usuario, pierde el acceso al momento
    $st = db()->prepare('SELECT nombre, rol, activo FROM usuarios WHERE id = ?');
    $st->execute([$user['id']]);
    $vivo = $st->fetch();
    if (!$vivo || (int)$vivo['activo'] !== 1) {
        $_SESSION = [];
        fail('Tu usuario está desactivado', 401);
    }
    $user['rol'] = $vivo['rol'];
    $user['nombre'] = $vivo['nombre'];

    if ($action === 'sesion') {
        out(['usuario' => $user['usuario'], 'nombre' => $user['nombre'] ?: $user['usuario'], 'rol' => $user['rol'], 'csrf' => csrf_token()]);
    }

    if ($action === 'list') {
        $rows = db()->query(SQL_COPIA . " ORDER BY fecha DESC, id DESC LIMIT 3000")->fetchAll();
        $rows = array_map('fila', $rows);
        $notas = notas_de(array_column($rows, 'id'));
        foreach ($rows as &$r) $r['notas'] = $notas[$r['id']] ?? [];
        unset($r);
        out(['copias' => $rows, 'usuario' => $user['usuario']]);
    }

    if ($action === 'usuarios') {
        exigir_admin($user);
        $rows = db()->query("SELECT id, usuario, nombre, rol, activo, DATE_FORMAT(creado,'%Y-%m-%d %H:%i') AS creado
                             FROM usuarios ORDER BY usuario")->fetchAll();
        foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['activo'] = (int)$r['activo']; }
        unset($r);
        out(['usuarios' => $rows]);
    }

    // Acciones que modifican datos
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido', 405);
    if (!csrf_ok($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) fail('Token de seguridad no válido, recarga la página', 403);

    // ---------- Cuenta propia y gestión de usuarios ----------
    if ($action === 'mi_password') {
        $st = db()->prepare('SELECT password_hash FROM usuarios WHERE id = ?');
        $st->execute([$user['id']]);
        $h = (string)$st->fetchColumn();
        if (!password_verify((string)($input['actual'] ?? ''), $h)) fail('La contraseña actual no es correcta');
        $nueva = (string)($input['nueva'] ?? '');
        if (mb_strlen($nueva) < MIN_PASSWORD) fail('La nueva contraseña debe tener al menos ' . MIN_PASSWORD . ' caracteres');
        db()->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($nueva, PASSWORD_DEFAULT), $user['id']]);
        out(['ok' => true]);
    }

    if ($action === 'usuario_crear') {
        exigir_admin($user);
        $login = txt($input['usuario'] ?? '', 50);
        $nombre = txt($input['nombre'] ?? '', 100);
        $pass = (string)($input['password'] ?? '');
        $rol = ($input['rol'] ?? 'usuario') === 'admin' ? 'admin' : 'usuario';
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $login)) fail('Usuario: 3-50 caracteres (letras, números, . _ -)');
        if (mb_strlen($pass) < MIN_PASSWORD) fail('La contraseña debe tener al menos ' . MIN_PASSWORD . ' caracteres');
        try {
            db()->prepare('INSERT INTO usuarios (usuario, nombre, rol, password_hash) VALUES (?,?,?,?)')
                ->execute([$login, $nombre !== '' ? $nombre : $login, $rol, password_hash($pass, PASSWORD_DEFAULT)]);
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') fail('Ese nombre de usuario ya existe');
            throw $ex;
        }
        out(['ok' => true], 201);
    }

    if ($action === 'usuario_editar') {
        exigir_admin($user);
        $uid = (int)($input['id'] ?? 0);
        $st = db()->prepare('SELECT id, rol, activo FROM usuarios WHERE id = ?');
        $st->execute([$uid]);
        $t = $st->fetch();
        if (!$t) fail('Usuario no encontrado', 404);
        $propio = $uid === (int)$user['id'];
        $set = [];
        $val = [];
        if (array_key_exists('nombre', $input)) {
            $set[] = 'nombre = ?'; $val[] = txt($input['nombre'], 100);
        }
        if (array_key_exists('rol', $input)) {
            if (!in_array($input['rol'], ['admin', 'usuario'], true)) fail('Rol no válido');
            if ($propio && $input['rol'] !== 'admin') fail('No puedes quitarte a ti mismo el rol de administrador');
            $set[] = 'rol = ?'; $val[] = $input['rol'];
        }
        if (array_key_exists('activo', $input)) {
            if ($propio && !$input['activo']) fail('No puedes desactivarte a ti mismo');
            $set[] = 'activo = ?'; $val[] = $input['activo'] ? 1 : 0;
        }
        if (!empty($input['password'])) {
            if (mb_strlen((string)$input['password']) < MIN_PASSWORD) fail('La contraseña debe tener al menos ' . MIN_PASSWORD . ' caracteres');
            $set[] = 'password_hash = ?'; $val[] = password_hash((string)$input['password'], PASSWORD_DEFAULT);
        }
        if (!$set) fail('Nada que cambiar');
        $val[] = $uid;
        db()->prepare('UPDATE usuarios SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($val);
        out(['ok' => true]);
    }

    $c = copia_o_404($input['id'] ?? 0);
    $id = $c['id'];

    switch ($action) {
        case 'revisar':
            if ($c['estado'] !== 'ERROR') fail('Solo se pueden revisar copias con error');
            if ($c['revisado']) fail('Esta copia ya está revisada');
            $d = txt($input['desc'] ?? '', 2000);
            if (palabras($d) < MIN_PALABRAS) fail('La descripción debe tener al menos ' . MIN_PALABRAS . ' palabras');
            db()->prepare("UPDATE copias SET revisado=1, revisado_por=?, revisado_fecha=NOW(), revision_desc=?, seg='vigilancia', recordar=NULL WHERE id=?")
                ->execute([$user['usuario'], $d, $id]);
            break;

        case 'reabrir':
            if ($c['estado'] !== 'ERROR') fail('Solo se pueden reabrir copias con error');
            db()->prepare("UPDATE copias SET revisado=0, revisado_por=NULL, revisado_fecha=NULL, revision_desc=NULL, seg=NULL, recordar=NULL WHERE id=?")
                ->execute([$id]);
            break;

        case 'seguimiento':
            if ($c['estado'] !== 'ERROR' || !$c['revisado']) fail('La copia debe estar revisada');
            $seg = $c['seg'];
            $rec = $c['recordar'];
            if (array_key_exists('seg', $input)) {
                if (!in_array($input['seg'], ['vigilancia', 'cerrado'], true)) fail('Seguimiento no válido');
                $seg = $input['seg'];
            }
            if (array_key_exists('recordar', $input)) {
                $r = $input['recordar'];
                if ($r === null || $r === '') {
                    $rec = null;
                } else {
                    $dt = DateTime::createFromFormat('Y-m-d', (string)$r);
                    if (!$dt || $dt->format('Y-m-d') !== $r) fail('Fecha no válida');
                    $rec = $r;
                }
            }
            if ($seg === 'cerrado') $rec = null;
            db()->prepare("UPDATE copias SET seg=?, recordar=? WHERE id=?")->execute([$seg, $rec, $id]);
            break;

        case 'nota':
            if ($c['estado'] !== 'ERROR' || !$c['revisado']) fail('La copia debe estar revisada');
            $t = txt($input['texto'] ?? '', 2000);
            if ($t === '') fail('La nota está vacía');
            db()->prepare("INSERT INTO notas (copia_id, usuario, texto) VALUES (?,?,?)")->execute([$id, $user['usuario'], $t]);
            break;

        default:
            fail('Acción desconocida', 404);
    }

    out(['copia' => copia($id)]);
} catch (Throwable $ex) {
    error_log('[copias] ' . $ex->getMessage());
    fail(cfg('debug') ? $ex->getMessage() : 'Error interno del servidor', 500);
}

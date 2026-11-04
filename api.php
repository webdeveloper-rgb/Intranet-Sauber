<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/api_utilities.php';
require __DIR__ . '/src/mail_import.php';
require __DIR__ . '/src/copy_service.php';

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
    if (($u['rol'] ?? '') !== 'admin')
        fail('Solo los administradores pueden hacer esto', 403);
}

function palabras(string $t): int
{
    return count(preg_split('/\s+/u', trim($t), -1, PREG_SPLIT_NO_EMPTY));
}

function txt($v, int $max): string
{
    return mb_substr(trim((string) $v), 0, $max);
}

$action = $_GET['action'] ?? '';
$input = json_decode((string) file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];

try {
    $user = usuario_actual();
    if (!$user)
        fail('Sesión caducada', 401);

    $st = db()->prepare('SELECT nombre, rol, activo FROM usuarios WHERE id = ?');
    $st->execute([$user['id']]);
    $vivo = $st->fetch();
    if (!$vivo || (int) $vivo['activo'] !== 1) {
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
        $rows = array_map('copia_fila', $rows);
        $notas = copia_notas_de(array_column($rows, 'id'));
        foreach ($rows as &$r)
            $r['notas'] = $notas[$r['id']] ?? [];
        unset($r);

        $body = json_encode(['copias' => $rows, 'usuario' => $user['usuario']], JSON_UNESCAPED_UNICODE);
        http_response_code(200);
        header('Content-Length: ' . strlen($body));
        header('Connection: close');
        ignore_user_abort(true);
        echo $body;
        session_write_close();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            while (ob_get_level() > 0)
                @ob_end_flush();
            flush();
        }
        api_sync_auto();
        correo_backup_auto();
        exit;
    }

    if ($action === 'usuarios') {
        exigir_admin($user);
        $rows = db()->query("SELECT id, usuario, nombre, rol, activo, DATE_FORMAT(creado,'%Y-%m-%d %H:%i') AS creado
                             FROM usuarios ORDER BY usuario")->fetchAll();
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['activo'] = (int) $r['activo'];
        }
        unset($r);
        out(['usuarios' => $rows]);
    }

    if ($action === 'catalogo') {
        out(api_sync_catalogo_inicial());
    }

    if ($action === 'equipos') {
        out(equipos_listado());
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        fail('Método no permitido', 405);
    if (!csrf_ok($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null))
        fail('Token de seguridad no válido, recarga la página', 403);

    if ($action === 'sync') {
        exigir_admin($user);
        try {
            out(['ok' => true] + (api_sync('web') ?? []));
        } catch (ApiError $ex) {
            fail($ex->getMessage(), 502);
        }
    }

    if ($action === 'correo_sync') {
        exigir_admin($user);
        asegurar_tablas_sync(db());
        try { out(['ok'=>true] + correo_importar()); }
        catch (CorreoError $ex) { fail($ex->getMessage(), 502); }
    }

    if ($action === 'equipo_asignar') {
        try {
            $importadas = equipos_asignar_cliente((int)($input['id'] ?? 0), (int)($input['cliente_id'] ?? 0));
            out(['ok' => true, 'copias_importadas' => $importadas]);
        } catch (ApiError $ex) { fail($ex->getMessage()); }
    }

    if ($action === 'mi_password') {
        $st = db()->prepare('SELECT password_hash FROM usuarios WHERE id = ?');
        $st->execute([$user['id']]);
        $h = (string) $st->fetchColumn();
        if (!password_verify((string) ($input['actual'] ?? ''), $h))
            fail('La contraseña actual no es correcta');
        $nueva = (string) ($input['nueva'] ?? '');
        if (mb_strlen($nueva) < MIN_PASSWORD)
            fail('La nueva contraseña debe tener al menos ' . MIN_PASSWORD . ' caracteres');
        db()->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($nueva, PASSWORD_DEFAULT), $user['id']]);
        out(['ok' => true]);
    }

    if ($action === 'usuario_crear') {
        exigir_admin($user);
        $login = txt($input['usuario'] ?? '', 50);
        $nombre = txt($input['nombre'] ?? '', 100);
        $pass = (string) ($input['password'] ?? '');
        $rol = ($input['rol'] ?? 'usuario') === 'admin' ? 'admin' : 'usuario';
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $login))
            fail('Usuario: 3-50 caracteres (letras, números, . _ -)');
        if (mb_strlen($pass) < MIN_PASSWORD)
            fail('La contraseña debe tener al menos ' . MIN_PASSWORD . ' caracteres');
        try {
            db()->prepare('INSERT INTO usuarios (usuario, nombre, rol, password_hash) VALUES (?,?,?,?)')
                ->execute([$login, $nombre !== '' ? $nombre : $login, $rol, password_hash($pass, PASSWORD_DEFAULT)]);
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000')
                fail('Ese nombre de usuario ya existe');
            throw $ex;
        }
        out(['ok' => true], 201);
    }

    if ($action === 'usuario_editar') {
        exigir_admin($user);
        $uid = (int) ($input['id'] ?? 0);
        $st = db()->prepare('SELECT id, rol, activo FROM usuarios WHERE id = ?');
        $st->execute([$uid]);
        $t = $st->fetch();
        if (!$t)
            fail('Usuario no encontrado', 404);
        $propio = $uid === (int) $user['id'];
        $set = [];
        $val = [];
        if (array_key_exists('nombre', $input)) {
            $set[] = 'nombre = ?';
            $val[] = txt($input['nombre'], 100);
        }
        if (array_key_exists('rol', $input)) {
            if (!in_array($input['rol'], ['admin', 'usuario'], true))
                fail('Rol no válido');
            if ($propio && $input['rol'] !== 'admin')
                fail('No puedes quitarte a ti mismo el rol de administrador');
            $set[] = 'rol = ?';
            $val[] = $input['rol'];
        }
        if (array_key_exists('activo', $input)) {
            if ($propio && !$input['activo'])
                fail('No puedes desactivarte a ti mismo');
            $set[] = 'activo = ?';
            $val[] = $input['activo'] ? 1 : 0;
        }
        if (!empty($input['password'])) {
            if (mb_strlen((string) $input['password']) < MIN_PASSWORD)
                fail('La contraseña debe tener al menos ' . MIN_PASSWORD . ' caracteres');
            $set[] = 'password_hash = ?';
            $val[] = password_hash((string) $input['password'], PASSWORD_DEFAULT);
        }
        if (!$set)
            fail('Nada que cambiar');
        $val[] = $uid;
        db()->prepare('UPDATE usuarios SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($val);
        out(['ok' => true]);
    }

    $c = copia_obtener((int) ($input['id'] ?? 0));
    if (!$c) fail('Copia no encontrada',404);
    $id = $c['id'];

    switch ($action) {
        case 'revisar':
            if ($c['estado'] !== 'ERROR')
                fail('Solo se pueden revisar copias con error');
            if ($c['revisado'])
                fail('Esta copia ya está revisada');
            $d = txt($input['desc'] ?? '', 2000);
            if (palabras($d) < MIN_PALABRAS)
                fail('La descripción debe tener al menos ' . MIN_PALABRAS . ' palabras');
            db()->prepare("UPDATE copias SET revisado=1, revisado_por=?, revisado_fecha=NOW(), revision_desc=?, seg='vigilancia', recordar=NULL WHERE id=?")
                ->execute([$user['usuario'], $d, $id]);
            break;

        case 'reabrir':
            if ($c['estado'] !== 'ERROR')
                fail('Solo se pueden reabrir copias con error');
            db()->prepare("UPDATE copias SET revisado=0, revisado_por=NULL, revisado_fecha=NULL, revision_desc=NULL, seg=NULL, recordar=NULL WHERE id=?")
                ->execute([$id]);
            break;

        case 'seguimiento':
            if ($c['estado'] !== 'ERROR' || !$c['revisado'])
                fail('La copia debe estar revisada');
            $seg = $c['seg'];
            $rec = $c['recordar'];
            if (array_key_exists('seg', $input)) {
                if (!in_array($input['seg'], ['vigilancia', 'cerrado'], true))
                    fail('Seguimiento no válido');
                $seg = $input['seg'];
            }
            if (array_key_exists('recordar', $input)) {
                $r = $input['recordar'];
                if ($r === null || $r === '') {
                    $rec = null;
                } else {
                    $dt = DateTime::createFromFormat('Y-m-d', (string) $r);
                    if (!$dt || $dt->format('Y-m-d') !== $r)
                        fail('Fecha no válida');
                    $rec = $r;
                }
            }
            if ($seg === 'cerrado')
                $rec = null;
            db()->prepare("UPDATE copias SET seg=?, recordar=? WHERE id=?")->execute([$seg, $rec, $id]);
            break;

        case 'nota':
            if ($c['estado'] !== 'ERROR' || !$c['revisado'])
                fail('La copia debe estar revisada');
            $t = txt($input['texto'] ?? '', 2000);
            if ($t === '')
                fail('La nota está vacía');
            db()->prepare("INSERT INTO notas (copia_id, usuario, texto) VALUES (?,?,?)")->execute([$id, $user['usuario'], $t]);
            break;

        default:
            fail('Acción desconocida', 404);
    }

    out(['copia' => copia_obtener($id)]);
} catch (Throwable $ex) {
    error_log('[copias] ' . $ex->getMessage());
    fail(cfg('debug') ? $ex->getMessage() : 'Error interno del servidor', 500);
}

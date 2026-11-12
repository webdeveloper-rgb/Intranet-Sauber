<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../Integration/ClientApi.php';
require __DIR__ . '/../Backup/EquipmentService.php';
require __DIR__ . '/../Security/ApiTokens.php';
require __DIR__ . '/../Backup/CopyRepository.php';
require __DIR__ . '/JsonResponse.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($d, int $code = 200): void
{
    JsonResponse::send($d, $code);
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

    // Detecta copias nuevas y cambios en las alertas de equipos sin copia.
    if ($action === 'novedades') {
        $r = db()->query('SELECT COALESCE(MAX(id), 0) AS m FROM copias')->fetch();
        if (empty($_SESSION['mig_tipos']) || empty($_SESSION['mig_dias_copia'])) {
            asegurar_tablas_equipos(db());
            $_SESSION['mig_tipos'] = 1;
            $_SESSION['mig_dias_copia'] = 1;
        }
        $sinCopia = copias_sin_recibir_cache(db());
        $ausentes = array_map(
            static fn($c) => [$c['id'], $c['cliente'], $c['equipo'], $c['fecha_esperada'], $c['fecha'], $c['ultima_copia']],
            $sinCopia
        );
        out([
            'm' => (int) $r['m'],
            'ausentes' => hash('sha256', json_encode($ausentes, JSON_UNESCAPED_UNICODE)),
        ]);
    }

    if ($action === 'list') {
        if (empty($_SESSION['mig_tipos']) || empty($_SESSION['mig_dias_copia'])) { // migraciones de equipos
            asegurar_tablas_equipos(db());
            $_SESSION['mig_tipos'] = 1;
            $_SESSION['mig_dias_copia'] = 1;
        }
        $rows = db()->query(SQL_COPIA . " WHERE fecha >= DATE_SUB(NOW(), INTERVAL 60 DAY)
            ORDER BY cursor_fecha DESC, id DESC LIMIT 3001")->fetchAll();
        $hasMore = count($rows) > 3000;
        if ($hasMore) {
            array_pop($rows);
        }
        if (!$hasMore) {
            $hasMore = (bool) db()->query('SELECT 1 FROM copias WHERE fecha < DATE_SUB(NOW(), INTERVAL 60 DAY) LIMIT 1')->fetchColumn();
        }
        $rows = array_map('copia_fila', $rows);
        $rows = array_merge(copias_sin_recibir_cache(db()), $rows);
        $notas = copia_notas_de(array_column(
            array_filter($rows, static fn($row) => ($row['codigo'] ?? null) !== 'SIN_COPIA'),
            'id'
        ));
        foreach ($rows as &$r)
            $r['notas'] = $notas[$r['id']] ?? [];
        unset($r);

        $body = json_encode([
            'copias' => $rows,
            'usuario' => $user['usuario'],
            'hay_mas_historial' => $hasMore,
        ], JSON_UNESCAPED_UNICODE);
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

    if ($action === 'tokens') {
        exigir_admin($user);
        tokens_asegurar_tabla(db());
        $rows = db()->query("SELECT id, nombre, prefijo, creado_por, activo,
                DATE_FORMAT(creado,'%Y-%m-%d %H:%i') AS creado,
                DATE_FORMAT(ultimo_uso,'%Y-%m-%d %H:%i') AS ultimo_uso, ultima_ip,
                DATE_FORMAT(caduca,'%Y-%m-%d') AS caduca
                FROM api_tokens ORDER BY id DESC")->fetchAll();
        foreach ($rows as &$r)
            $r['activo'] = (int) $r['activo'];
        unset($r);
        out(['tokens' => $rows]);
    }

    if ($action === 'catalogo') {
        out(api_sync_catalogo());
    }

    if ($action === 'equipos') {
        $listado = equipos_listado();
        $_SESSION['mig_tipos'] = 1;
        $_SESSION['mig_dias_copia'] = 1;
        out($listado);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        fail('Método no permitido', 405);
    if (!csrf_ok($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null))
        fail('Token de seguridad no válido, recarga la página', 403);

    if ($action === 'history') {
        $cursorFecha = $input['cursor_fecha'] ?? null;
        $cursorId = filter_var($input['cursor_id'] ?? null, FILTER_VALIDATE_INT);
        $cursorFechaValida = is_string($cursorFecha)
            ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $cursorFecha)
            : false;
        if ($cursorFecha === null) {
            $where = 'fecha < DATE_SUB(NOW(), INTERVAL 60 DAY)';
            $params = [];
        } elseif (
            !$cursorFechaValida
            || $cursorFechaValida->format('Y-m-d H:i:s') !== $cursorFecha
            || $cursorId === false
            || $cursorId < 1
        ) {
            fail('Cursor de historial no válido');
        } else {
            $where = '(fecha < ? OR (fecha = ? AND id < ?))';
            $params = [$cursorFecha, $cursorFecha, $cursorId];
        }
        $st = db()->prepare(SQL_COPIA . " WHERE $where ORDER BY cursor_fecha DESC,id DESC LIMIT 501");
        $st->execute($params);
        $rows = $st->fetchAll();
        $hasMore = count($rows) > 500;
        if ($hasMore) {
            array_pop($rows);
        }
        $rows = array_map('copia_fila', $rows);
        $notas = copia_notas_de(array_column($rows, 'id'));
        foreach ($rows as &$row) {
            $row['notas'] = $notas[$row['id']] ?? [];
        }
        unset($row);
        out(['copias' => $rows, 'hay_mas_historial' => $hasMore]);
    }

    if ($action === 'sync') {
        exigir_admin($user);
        try {
            out(['ok' => true] + (api_sync('web') ?? []));
        } catch (ApiError $ex) {
            fail($ex->getMessage(), 502);
        }
    }

    // Alias para el botón "Leer avisos" del frontend: ya no hay nada que leer, los avisos llegan por ingest.php.
    if ($action === 'copias_sync' || $action === 'correo_sync') {
        exigir_admin($user);
        out([
            'ok' => true,
            'leidas' => 0,
            'ignoradas' => 0,
            'omitidas' => 0,
            'errores' => 0,
            'mensaje' => 'Los avisos llegan solos desde n8n.'
        ]);
    }

    if ($action === 'token_crear') {
        exigir_admin($user);
        $nombre = txt($input['nombre'] ?? '', 100);
        if ($nombre === '')
            fail('Pon un nombre al token (por ejemplo: n8n producción)');
        $dias = (int) ($input['dias'] ?? 0);
        // El token en claro solo se devuelve en esta respuesta; en BD queda su hash.
        out(['ok' => true] + token_crear($nombre, $user['usuario'], $dias > 0 ? min(3650, $dias) : null), 201);
    }

    if ($action === 'token_revocar') {
        exigir_admin($user);
        tokens_asegurar_tabla(db());
        db()->prepare('UPDATE api_tokens SET activo = 0 WHERE id = ?')->execute([(int) ($input['id'] ?? 0)]);
        out(['ok' => true]);
    }

    if ($action === 'equipo_asignar') {
        exigir_admin($user);
        try {
            $importadas = equipos_asignar_cliente((int) ($input['id'] ?? 0), (int) ($input['cliente_id'] ?? 0));
            out(['ok' => true, 'copias_importadas' => $importadas]);
        } catch (ApiError $ex) {
            fail($ex->getMessage());
        }
    }

    if ($action === 'equipo_vigilar') {
        exigir_admin($user);
        try {
            equipos_vigilar((int) ($input['id'] ?? 0), !empty($input['vigilar']));
            out(['ok' => true]);
        } catch (ApiError $ex) {
            fail($ex->getMessage());
        }
    }

    if ($action === 'equipo_horario') {
        exigir_admin($user);
        try {
            equipos_guardar_dias_copia((int) ($input['id'] ?? 0), $input['dias_copia'] ?? null);
            out(['ok' => true]);
        } catch (ApiError $ex) {
            fail($ex->getMessage());
        }
    }

    if ($action === 'equipo_desasignar') {
        exigir_admin($user);
        try {
            $actualizadas = equipos_desasignar_cliente((int) ($input['id'] ?? 0));
            out(['ok' => true, 'copias_desasignadas' => $actualizadas]);
        } catch (ApiError $ex) {
            fail($ex->getMessage());
        }
    }

    if (in_array($action, ['mi_password', 'usuario_crear'], true))
        fail('Las credenciales se gestionan en la API; los usuarios autorizados se aprovisionan al iniciar sesión.');

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

    if (!in_array($action, ['revisar', 'reabrir', 'seguimiento', 'nota'], true))
        fail('Acción desconocida', 404);
    $c = copia_obtener((int) ($input['id'] ?? 0));
    if (!$c)
        fail('Copia no encontrada', 404);
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
            $update = db()->prepare("UPDATE copias SET revisado=1, revisado_por=?, revisado_fecha=NOW(), revision_desc=?, seg='vigilancia', recordar=NULL WHERE id=? AND estado='ERROR' AND revisado=0");
            $update->execute([$user['usuario'], $d, $id]);
            if ($update->rowCount() !== 1)
                fail('La copia ya fue revisada por otro usuario. Actualiza la lista.', 409);
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

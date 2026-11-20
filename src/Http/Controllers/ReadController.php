<?php
declare(strict_types=1);


function dashboard_inicio_listar(PDO $pdo): array
{
    $estadisticas = [];
    $copias = $pdo->query("SELECT equipo_id,SUM(total) AS total FROM (
            SELECT equipo_id,COUNT(*) AS total FROM copias WHERE equipo_id IS NOT NULL AND estado='ERROR' GROUP BY equipo_id
            UNION ALL
            SELECT equipo_id,COUNT(*) AS total FROM correo_copias
                WHERE equipo_id IS NOT NULL AND importada=0 AND estado='ERROR' GROUP BY equipo_id
        ) errores GROUP BY equipo_id")->fetchAll();
    foreach ($copias as $row) {
        $id = (int) $row['equipo_id'];
        $estadisticas[$id] = ['copias' => (int) $row['total'], 'eventos' => 0, 'ultimo' => null, 'en_curso' => 0, 'productos_copia' => []];
    }

    $nivelEvento = monitor_evento_nivel_sql();
    $bugCheck = "(ev.log_name='System' AND ev.event_id=1001
        AND (LOWER(COALESCE(ev.source,'')) LIKE '%bugcheck%'
            OR LOWER(COALESCE(ev.source,'')) LIKE '%wer-systemerrorreporting%'))";
    $condicionEventos = "(({$nivelEvento}) IN ('Critical','Error') OR {$bugCheck})
        AND (e.monitor_event_mode='full' OR ev.event_time >= UTC_TIMESTAMP() - INTERVAL 30 DAY)";
    $ordenEvento = "CONCAT(DATE_FORMAT(ev.event_time,'%Y%m%d%H%i%s'),LPAD(ev.id,20,'0'))";
    $eventos = $pdo->query("SELECT ev.equipo_id,COUNT(*) AS total,
            CAST(RIGHT(MAX({$ordenEvento}),20) AS UNSIGNED) AS ultimo_id
        FROM monitor_eventos ev JOIN equipos e ON e.id=ev.equipo_id
        WHERE {$condicionEventos} GROUP BY ev.equipo_id")->fetchAll();
    $ultimosIds = [];
    foreach ($eventos as $row) {
        $id = (int) $row['equipo_id'];
        $estadisticas[$id] ??= ['copias' => 0, 'eventos' => 0, 'ultimo' => null, 'en_curso' => 0, 'productos_copia' => []];
        $estadisticas[$id]['eventos'] += (int) $row['total'];
        $ultimosIds[] = (int) $row['ultimo_id'];
    }
    if ($ultimosIds) {
        $placeholders = implode(',', array_fill(0, count($ultimosIds), '?'));
        $st = $pdo->prepare("SELECT id,equipo_id,event_time,log_name,event_id,source FROM monitor_eventos WHERE id IN ({$placeholders})");
        $st->execute($ultimosIds);
        foreach ($st->fetchAll() as $row) {
            $source = trim((string) ($row['source'] ?? ''));
            $isBugCheck = $row['log_name'] === 'System' && (int) $row['event_id'] === 1001
                && (stripos($source, 'bugcheck') !== false || stripos($source, 'wer-systemerrorreporting') !== false);
            if ($isBugCheck) $title = 'Fallo critico de Windows';
            elseif ($row['log_name'] === 'Application' && (int) $row['event_id'] === 1000) $title = 'Cierre inesperado de aplicacion';
            elseif ($row['log_name'] === 'Application' && (int) $row['event_id'] === 1002) $title = 'Aplicacion bloqueada';
            elseif ($row['log_name'] === 'System' && (int) $row['event_id'] === 41) $title = 'Apagado inesperado';
            else $title = 'Evento de Windows: ' . ($source !== '' ? $source : 'sin origen');
            $id = (int) $row['equipo_id'];
            $estadisticas[$id]['ultimo'] = [
                'fecha' => $row['event_time'], 'titulo' => $title,
            ];
        }
    }

    $alertas = $pdo->query("SELECT equipo_id,titulo,ultima_vez FROM monitor_alertas
        WHERE activo=1 AND severidad='critical' ORDER BY ultima_vez DESC,id DESC")->fetchAll();
    foreach ($alertas as $alerta) {
        $id = (int) $alerta['equipo_id'];
        $estadisticas[$id] ??= ['copias' => 0, 'eventos' => 0, 'ultimo' => null, 'en_curso' => 0, 'productos_copia' => []];
        $estadisticas[$id]['eventos']++;
        $current = $estadisticas[$id]['ultimo'];
        if ($current === null || strcmp((string) $alerta['ultima_vez'], (string) $current['fecha']) > 0) {
            $estadisticas[$id]['ultimo'] = [
                'fecha' => $alerta['ultima_vez'], 'titulo' => $alerta['titulo'],
            ];
        }
    }

    $enCurso = $pdo->query("SELECT equipo_id,COUNT(*) AS total,GROUP_CONCAT(DISTINCT product ORDER BY product SEPARATOR ', ') AS productos
        FROM monitor_backup_activity
        WHERE observed_at >= UTC_TIMESTAMP() - INTERVAL 5 MINUTE
        GROUP BY equipo_id")->fetchAll();
    foreach ($enCurso as $row) {
        $id = (int) $row['equipo_id'];
        $estadisticas[$id] ??= ['copias' => 0, 'eventos' => 0, 'ultimo' => null, 'en_curso' => 0, 'productos_copia' => []];
        $estadisticas[$id]['en_curso'] = (int) $row['total'];
        $estadisticas[$id]['productos_copia'] = $row['productos'] ? explode(', ', (string) $row['productos']) : [];
    }

    $ids = array_keys(array_filter($estadisticas, static fn(array $row): bool => $row['copias'] > 0 || $row['eventos'] > 0 || $row['en_curso'] > 0));
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT e.id,e.nombre,e.monitor_alias,e.software,e.tipo,e.programa,e.cliente_id,c.nombre AS cliente
        FROM equipos e LEFT JOIN clientes c ON c.id=e.cliente_id WHERE e.id IN ({$placeholders})");
    $st->execute($ids);
    $result = [];
    foreach ($st->fetchAll() as $equipo) {
        $id = (int) $equipo['id'];
        $equipo['id'] = $id;
        $equipo['errores_pendientes'] = $estadisticas[$id]['copias'];
        $equipo['total_errores_evento'] = $estadisticas[$id]['eventos'];
        $equipo['errores_evento'] = $estadisticas[$id]['ultimo'] !== null ? [$estadisticas[$id]['ultimo']] : [];
        $equipo['copias_en_curso'] = $estadisticas[$id]['en_curso'];
        $equipo['productos_copia_en_curso'] = $estadisticas[$id]['productos_copia'];
        $result[] = $equipo;
    }
    usort($result, static function (array $a, array $b): int {
        return ($b['errores_pendientes'] + $b['total_errores_evento'])
            <=> ($a['errores_pendientes'] + $a['total_errores_evento'])
            ?: strcasecmp($a['nombre'], $b['nombre']);
    });
    return $result;
}

/**
 * Acciones de lectura del panel: sesion, novedades, copias recientes, equipos, catalogo y tokens.
 */
final class ReadController
{
    public static function handle(string $action, array $user): bool
    {
        if ($action === 'sesion') {
            out([
                'usuario' => $user['usuario'],
                'nombre' => $user['nombre'] ?: $user['usuario'],
                'rol' => $user['rol'],
                'csrf' => csrf_token(),
            ]);
        }

        if ($action === 'novedades') {
            $r = db()->query('SELECT COALESCE(MAX(id), 0) AS m FROM copias')->fetch();
            $staging = (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM correo_copias')->fetchColumn();
            $ingesta = (int) db()->query("SELECT COALESCE(MAX(id), 0) FROM correo_sync_log WHERE origen='n8n'")->fetchColumn();
            $eventos = (int) db()->query('SELECT COALESCE(MAX(id),0) FROM monitor_eventos')->fetchColumn();
            $alertasActivas = db()->query('SELECT id,severidad,titulo,detalle,ultima_vez FROM monitor_alertas WHERE activo=1 ORDER BY id')->fetchAll();
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
                'staging' => $staging,
                'ingesta' => $ingesta,
                'ausentes' => hash('sha256', json_encode($ausentes, JSON_UNESCAPED_UNICODE)),
                'eventos' => $eventos,
                'alertas' => hash('sha256', json_encode($alertasActivas, JSON_UNESCAPED_UNICODE)),
            ]);
        }

        if ($action === 'list') {
            if (empty($_SESSION['mig_tipos']) || empty($_SESSION['mig_dias_copia'])) {
                asegurar_tablas_equipos(db());
                $_SESSION['mig_tipos'] = 1;
                $_SESSION['mig_dias_copia'] = 1;
            }
            $dateFrom = $_GET['desde'] ?? '';
            $dateTo = $_GET['hasta'] ?? '';
            $dateIsValid = static function (string $value): bool {
                return $value === '' || (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1
                    && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)));
            };
            if (!is_string($dateFrom) || !is_string($dateTo)
                || !$dateIsValid($dateFrom) || !$dateIsValid($dateTo)
                || ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo)) {
                fail('El intervalo de fechas no es válido.');
            }
            $where = [];
            $params = [];
            if ($dateFrom !== '') {
                $where[] = 'fecha >= ?';
                $params[] = $dateFrom . ' 00:00:00';
            }
            if ($dateTo !== '') {
                $where[] = 'fecha < DATE_ADD(?, INTERVAL 1 DAY)';
                $params[] = $dateTo . ' 00:00:00';
            }
            $sql = SQL_COPIA;
            if ($where) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            $pdo = db();
            // Materializa primero los fallos de copia esperada para que aparezcan
            // en esta misma primera respuesta del historial.
            copias_sin_recibir_cache($pdo);
            $query = $pdo->prepare($sql . ' ORDER BY cursor_fecha DESC, id DESC LIMIT 3001');
            $query->execute($params);
            $rows = $query->fetchAll();
            $truncado = count($rows) > 3000;
            if (count($rows) > 3000) {
                array_pop($rows);
            }
            $rows = array_map('copia_fila', $rows);
            $notas = copia_notas_de(array_column(
                array_filter($rows, static fn($row) => ($row['codigo'] ?? null) !== 'SIN_COPIA'),
                'id'
            ));
            foreach ($rows as &$r) {
                $r['notas'] = $notas[$r['id']] ?? [];
            }
            unset($r);

            $body = json_encode([
                'copias' => $rows,
                'truncado' => $truncado,
                'usuario' => $user['usuario'],
                'hay_mas_historial' => false,
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
                while (ob_get_level() > 0) {
                    @ob_end_flush();
                }
                flush();
            }
            exit;
        }

        if ($action === 'informe_copias') {
            $dateFrom = $_GET['desde'] ?? '';
            $dateTo = $_GET['hasta'] ?? '';
            $status = $_GET['estado'] ?? 'all';
            $equipmentId = $_GET['equipo_id'] ?? '';
            $search = $_GET['q'] ?? '';
            $page = filter_var($_GET['page'] ?? '1', FILTER_VALIDATE_INT);
            $validDate = static fn($value): bool => is_string($value) && ($value === '' ||
                (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1
                    && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))));
            if (!$validDate($dateFrom) || !$validDate($dateTo)
                || ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo)
                || !in_array($status, ['all', 'OK', 'ERROR', 'WARNING', 'RUNNING', 'REVISION'], true)
                || !is_string($equipmentId) || ($equipmentId !== '' && (!ctype_digit($equipmentId) || (int) $equipmentId < 1))
                || !is_string($search) || mb_strlen($search) > 150
                || $page === false || $page < 1) {
                fail('Los filtros del informe no son válidos.');
            }
            $where = [];
            $params = [];
            $dateWhere = [];
            $dateParams = [];
            if ($dateFrom !== '') {
                $dateWhere[] = 'informe.fecha >= ?'; $dateParams[] = $dateFrom . ' 00:00:00';
            }
            if ($dateTo !== '') {
                $dateWhere[] = 'informe.fecha < DATE_ADD(?, INTERVAL 1 DAY)'; $dateParams[] = $dateTo . ' 00:00:00';
            }
            $where = $dateWhere;
            $params = $dateParams;
            if ($status !== 'all') { $where[] = 'informe.estado = ?'; $params[] = $status; }
            if ($equipmentId !== '') { $where[] = 'informe.equipo_id = ?'; $params[] = (int) $equipmentId; }
            if (trim($search) !== '') {
                $where[] = '(informe.equipo LIKE ? OR informe.cliente LIKE ? OR informe.desc_error LIKE ? OR informe.codigo LIKE ?)';
                $term = '%' . trim($search) . '%';
                array_push($params, $term, $term, $term, $term);
            }
            $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
            $pdo = db();
            copias_sin_recibir_cache($pdo);
            $union = "SELECT CONVERT(CAST(id AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS id,fecha,
                    CONVERT(cliente USING utf8mb4) COLLATE utf8mb4_unicode_ci AS cliente,
                    CONVERT(COALESCE((SELECT nombre FROM equipos WHERE id=copias.equipo_id),equipo) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS equipo,equipo_id,
                    (SELECT cliente_id FROM equipos WHERE id=copias.equipo_id) AS cliente_id,
                    CONVERT(estado USING utf8mb4) COLLATE utf8mb4_unicode_ci AS estado,
                    CONVERT(severidad USING utf8mb4) COLLATE utf8mb4_unicode_ci AS severidad,
                    CONVERT(desc_error USING utf8mb4) COLLATE utf8mb4_unicode_ci AS desc_error,
                    CONVERT(codigo USING utf8mb4) COLLATE utf8mb4_unicode_ci AS codigo,
                    CONVERT(resultado_original USING utf8mb4) COLLATE utf8mb4_unicode_ci AS resultado_original,
                    CONVERT(clasificacion_fuente USING utf8mb4) COLLATE utf8mb4_unicode_ci AS clasificacion_fuente,
                    CONVERT(clasificacion_evidencia USING utf8mb4) COLLATE utf8mb4_unicode_ci AS clasificacion_evidencia,
                    CONVERT(software USING utf8mb4) COLLATE utf8mb4_unicode_ci AS software,
                    CONVERT(destino USING utf8mb4) COLLATE utf8mb4_unicode_ci AS destino,
                    CONVERT(CAST(tamano AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS tamano,
                    CONVERT(CAST(duracion AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS duracion,0 AS staging FROM copias
                UNION ALL
                SELECT CONVERT(CONCAT('staging-',cc.id) USING utf8mb4) COLLATE utf8mb4_unicode_ci,cc.fecha,
                    CONVERT(COALESCE(c.nombre,'Sin asignar') USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                    CONVERT(e.nombre USING utf8mb4) COLLATE utf8mb4_unicode_ci,cc.equipo_id,e.cliente_id,
                    CONVERT(cc.estado USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                    CONVERT(cc.severidad USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                    CONVERT(cc.desc_error USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                    CONVERT(cc.codigo USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                    CONVERT(cc.resultado_original USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                    CONVERT(cc.clasificacion_fuente USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                    CONVERT(cc.clasificacion_evidencia USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                    CONVERT(cc.software USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                    CONVERT(cc.destino USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                    CONVERT(CAST(cc.tamano AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                    CONVERT(CAST(cc.duracion AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci,1 AS staging
                FROM correo_copias cc JOIN equipos e ON e.id=cc.equipo_id
                LEFT JOIN clientes c ON c.id=e.cliente_id WHERE cc.importada=0";
            $count = $pdo->prepare('SELECT COUNT(*) FROM (' . $union . ') informe' . $whereSql);
            $count->execute($params);
            $total = (int) $count->fetchColumn();
            $summaryQuery = $pdo->prepare('SELECT informe.estado,COUNT(*) AS total FROM (' . $union . ') informe'
                . $whereSql . ' GROUP BY estado');
            $summaryQuery->execute($params);
            $summary = ['OK' => 0, 'ERROR' => 0, 'WARNING' => 0, 'RUNNING' => 0, 'REVISION' => 0];
            foreach ($summaryQuery->fetchAll() as $row) $summary[$row['estado']] = (int) $row['total'];
            $pageSize = 100;
            $query = $pdo->prepare('SELECT informe.id,DATE_FORMAT(informe.fecha,\'%Y-%m-%d %H:%i\') AS fecha,
                informe.cliente,informe.equipo,informe.equipo_id,informe.cliente_id,informe.estado,informe.severidad,informe.desc_error,informe.codigo,informe.resultado_original,
                informe.clasificacion_fuente,informe.clasificacion_evidencia,informe.software,informe.destino,
                informe.tamano,informe.duracion,informe.staging FROM (' . $union . ') informe' . $whereSql
                . " ORDER BY informe.fecha DESC,informe.id DESC LIMIT {$pageSize} OFFSET " . (($page - 1) * $pageSize));
            $query->execute($params);
            $rows = $query->fetchAll();
            out(['copias' => $rows, 'total' => $total, 'resumen' => $summary, 'pagina' => $page, 'por_pagina' => $pageSize]);
        }

        if ($action === 'usuarios') {
            fail('La gestión de accesos se realiza en la API de usuarios autorizados.', 403);
        }

        if ($action === 'tokens') {
            exigir_admin($user);
            tokens_asegurar_tabla(db());
            $rows = db()->query("SELECT id, nombre, prefijo, creado_por, activo,
                    DATE_FORMAT(creado,'%Y-%m-%d %H:%i') AS creado,
                    DATE_FORMAT(ultimo_uso,'%Y-%m-%d %H:%i') AS ultimo_uso, ultima_ip,
                    DATE_FORMAT(caduca,'%Y-%m-%d') AS caduca
                    FROM api_tokens ORDER BY id DESC")->fetchAll();
            foreach ($rows as &$r) {
                $r['activo'] = (int) $r['activo'];
            }
            unset($r);
            out(['tokens' => $rows]);
        }

        if ($action === 'monitor_enrollments') {
            exigir_admin($user);
            out(['enrollments' => monitor_enrollment_listar(db())]);
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

        if ($action === 'monitor_equipo') {
            $detalle = monitor_detalle_equipo(db(), (int) ($_GET['id'] ?? 0));
            if ($detalle === null) {
                fail('No hay un informe de Monitor-Equipos para este equipo', 404);
            }
            out($detalle);
        }

        if ($action === 'monitor_backup_activity') {
            $equipmentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
            if ($equipmentId === false || $equipmentId === null || $equipmentId < 1) {
                fail('El identificador de equipo no es válido.');
            }
            out(['actividades' => monitor_equipo_copias_en_curso(db(), (int) $equipmentId)]);
        }

        if ($action === 'monitor_eventos') {
            $page = filter_var($_GET['page'] ?? '1', FILTER_VALIDATE_INT);
            $search = $_GET['q'] ?? '';
            $level = $_GET['nivel'] ?? 'all';
            $dateFrom = $_GET['desde'] ?? '';
            $dateTo = $_GET['hasta'] ?? '';
            $equipoId = array_key_exists('equipo_id', $_GET)
                ? filter_var($_GET['equipo_id'], FILTER_VALIDATE_INT)
                : null;
            $fechaValida = static function (string $value): bool {
                return $value === '' || (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1
                    && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)));
            };
            if ($page === false || $page < 1 || !is_string($search)
                || !is_string($level) || !in_array($level, ['all', 'Critical', 'Error', 'Warning', 'Information'], true)
                || !is_string($dateFrom) || !is_string($dateTo)
                || !$fechaValida($dateFrom) || !$fechaValida($dateTo)
                || ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo)
                || ($equipoId !== null && ($equipoId === false || $equipoId < 1))) {
                fail('Los filtros de eventos no son válidos.');
            }
            out(monitor_eventos_listar(db(), $page, $search, $level, $equipoId, $dateFrom ?: null, $dateTo ?: null));
        }

        if ($action === 'dashboard_resumen') {
            $pdo = db();
            asegurar_tablas_equipos($pdo);
            asegurar_tablas_sync($pdo);
            $parque = $pdo->query("SELECT e.id,e.nombre,e.monitor_alias,e.tipo,e.cliente_id,c.nombre AS cliente,
                (e.monitor_report IS NOT NULL) AS tiene_monitor,
                (e.monitor_report IS NOT NULL AND (e.monitor_last_seen IS NULL
                    OR e.monitor_last_seen < UTC_TIMESTAMP() - INTERVAL 24 HOUR)) AS sin_informe
                FROM equipos e LEFT JOIN clientes c ON c.id=e.cliente_id")->fetchAll();
            $cola = [];
            foreach (dashboard_inicio_listar($pdo) as $equipo) $cola[(int) $equipo['id']] = $equipo;
            $sinCopia = [];
            foreach (copias_sin_recibir_cache($pdo) as $copia) {
                $id = (int) str_replace('sin-copia-', '', (string) $copia['id']);
                $sinCopia[$id] = true;
            }
            $criticos = [];
            foreach ($pdo->query("SELECT equipo_id,COUNT(*) AS total FROM monitor_alertas WHERE activo=1 AND severidad='critical' GROUP BY equipo_id")->fetchAll() as $alerta) {
                $criticos[(int) $alerta['equipo_id']] = (int) $alerta['total'];
            }
            $sinAsignar = 0;
            $sinInforme = 0;
            $agentes = 0;
            foreach ($parque as $equipo) {
                $id = (int) $equipo['id'];
                $sinAsignar += $equipo['cliente_id'] === null ? 1 : 0;
                $sinInforme += (int) $equipo['sin_informe'];
                $agentes += (int) $equipo['tiene_monitor'];
                if (!isset($cola[$id]) && (isset($sinCopia[$id]) || $equipo['sin_informe'])) {
                    $cola[$id] = $equipo + ['errores_pendientes' => 0, 'total_errores_evento' => 0, 'copias_en_curso' => 0];
                }
                if (isset($cola[$id])) {
                    $cola[$id]['sin_copia'] = isset($sinCopia[$id]);
                    $cola[$id]['sin_informe'] = (bool) $equipo['sin_informe'];
                    $cola[$id]['critico'] = isset($criticos[$id]);
                    $cola[$id]['alertas_hardware'] = $criticos[$id] ?? 0;
                    $cola[$id]['eventos_windows'] = max(0, (int) $cola[$id]['total_errores_evento'] - ($criticos[$id] ?? 0));
                }
            }
            // Normaliza la colación como en informe_copias: instalaciones antiguas pueden
            // tener distintas colaciones en ambas tablas y MySQL rechaza su UNION.
            // Cuenta el historial y solo el staging pendiente para evitar duplicar avisos importados.
            $copias = $pdo->query("SELECT estado,COUNT(*) AS total FROM (
                SELECT CONVERT(estado USING utf8mb4) COLLATE utf8mb4_unicode_ci AS estado
                    FROM copias WHERE fecha >= NOW() - INTERVAL 24 HOUR
                UNION ALL
                SELECT CONVERT(estado USING utf8mb4) COLLATE utf8mb4_unicode_ci AS estado
                    FROM correo_copias WHERE importada=0 AND fecha >= NOW() - INTERVAL 24 HOUR
                ) ultimas GROUP BY estado")->fetchAll();
            $ingesta = $pdo->query("SELECT DATE_FORMAT(inicio,'%Y-%m-%d %H:%i') AS fecha,ok,
                TIMESTAMPDIFF(HOUR,inicio,NOW()) AS horas FROM correo_sync_log WHERE origen='n8n' ORDER BY id DESC LIMIT 1")->fetch() ?: null;
            $sync = $pdo->query("SELECT DATE_FORMAT(inicio,'%Y-%m-%d %H:%i') AS fecha,ok FROM sync_log ORDER BY id DESC LIMIT 1")->fetch() ?: null;
            if ($ingesta) $ingesta['retrasada'] = (int) $ingesta['horas'] >= max(1, (int) cfg('ingest_alert_hours', 24));
            out(['equipos' => array_values($cola), 'resumen' => [
                'total_equipos' => count($parque), 'sin_asignar' => $sinAsignar,
                'sin_informe' => $sinInforme, 'agentes' => $agentes, 'sin_copia' => count($sinCopia),
            ], 'copias' => $copias, 'integraciones' => ['ingesta' => $ingesta, 'clientes' => $sync],
                'actualizado' => gmdate('c')]);
        }

        if ($action === 'panel_inicio') {
            out(['equipos' => dashboard_inicio_listar(db())]);
        }

        if ($action === 'notificaciones') {
            $page = filter_var($_GET['page'] ?? '1', FILTER_VALIDATE_INT);
            $search = $_GET['q'] ?? '';
            $type = $_GET['tipo'] ?? 'all';
            $severity = $_GET['nivel'] ?? 'all';
            $pageSize = filter_var($_GET['por_pagina'] ?? '25', FILTER_VALIDATE_INT);
            $equipmentId = array_key_exists('equipo_id', $_GET)
                ? filter_var($_GET['equipo_id'], FILTER_VALIDATE_INT)
                : null;
            if ($page === false || $page < 1 || !is_string($search)
                || !is_string($type) || !is_string($severity)
                || $pageSize === false || $pageSize < 1 || $pageSize > 500
                || ($equipmentId !== null && ($equipmentId === false || $equipmentId < 1))) {
                fail('Los filtros de notificaciones no son válidos.');
            }
            out(monitor_notificaciones_listar(
                db(), $page, $search, $type, $severity, $pageSize,
                $equipmentId !== null ? (int) $equipmentId : null
            ));
        }

        return false;
    }
}

<?php
declare(strict_types=1);

function monitor_event_mode_obtener(PDO $pdo, int $equipmentId): string
{
    $st = $pdo->prepare('SELECT monitor_event_mode FROM equipos WHERE id=?');
    $st->execute([$equipmentId]);
    $mode = $st->fetchColumn();
    if (!is_string($mode) || !in_array($mode, ['standard', 'full'], true)) {
        throw new RuntimeException('El modo de extracción de eventos guardado no es válido.');
    }
    return $mode;
}

function monitor_event_mode_guardar(PDO $pdo, int $equipmentId, string $mode): void
{
    if ($equipmentId < 1 || !in_array($mode, ['standard', 'full'], true)) {
        throw new ApiError('El equipo o el modo de extracción no es válido.');
    }
    $st = $pdo->prepare('UPDATE equipos SET monitor_event_mode=? WHERE id=?');
    $st->execute([$mode, $equipmentId]);
    if (!$st->rowCount()) {
        $st = $pdo->prepare('SELECT id FROM equipos WHERE id=?');
        $st->execute([$equipmentId]);
        if (!$st->fetchColumn()) {
            throw new ApiError('Equipo no encontrado.');
        }
    }
}

function monitor_alias_guardar(PDO $pdo, int $equipmentId, $alias): ?string
{
    if ($equipmentId < 1 || (!is_string($alias) && $alias !== null)) {
        throw new ApiError('El equipo o el alias no es válido.');
    }
    $normalized = monitor_texto($alias, 100);
    $st = $pdo->prepare('UPDATE equipos SET monitor_alias=? WHERE id=?');
    $st->execute([$normalized, $equipmentId]);
    if (!$st->rowCount()) {
        $st = $pdo->prepare('SELECT id FROM equipos WHERE id=?');
        $st->execute([$equipmentId]);
        if (!$st->fetchColumn()) {
            throw new ApiError('Equipo no encontrado.');
        }
    }
    return $normalized;
}

function equipo_nombre_guardar(PDO $pdo, int $equipmentId, $nombre): string
{
    if ($equipmentId < 1 || !is_string($nombre)) {
        throw new ApiError('El equipo o el nombre no es válido.');
    }
    $nombre = trim(preg_replace('/\\s+/u', ' ', $nombre) ?? '');
    if ($nombre === '' || mb_strlen($nombre) > 200) {
        throw new ApiError('El nombre debe tener entre 1 y 200 caracteres.');
    }

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT nombre,software,monitor_machine_id FROM equipos WHERE id=? FOR UPDATE');
        $st->execute([$equipmentId]);
        $equipment = $st->fetch(PDO::FETCH_ASSOC);
        if (!$equipment) {
            throw new ApiError('Equipo no encontrado.');
        }
        $duplicate = $pdo->prepare('SELECT id FROM equipos WHERE software=? AND nombre=? AND id<>?');
        $duplicate->execute([$equipment['software'], $nombre, $equipmentId]);
        if ($duplicate->fetchColumn()) {
            throw new ApiError('Ya existe otro equipo con ese nombre y software.');
        }

        if ($equipment['nombre'] !== $nombre) {
            $products = [(string) $equipment['software']];
            if (!empty($equipment['monitor_machine_id'])) $products[] = 'MonitorAgent';
            $find = $pdo->prepare('SELECT equipo_id FROM equipo_nombres WHERE software=? AND nombre=? FOR UPDATE');
            $insert = $pdo->prepare('INSERT IGNORE INTO equipo_nombres (software,nombre,equipo_id) VALUES (?,?,?)');
            foreach (array_unique($products) as $product) {
                if ($product === '') continue;
                $find->execute([$product, $equipment['nombre']]);
                $owner = $find->fetchColumn();
                if ($owner === false || (int) $owner === $equipmentId) {
                    $insert->execute([$product, $equipment['nombre'], $equipmentId]);
                }
            }
            $update = $pdo->prepare('UPDATE equipos SET nombre=?,nombre_manual=1 WHERE id=?');
            $update->execute([$nombre, $equipmentId]);
        } else {
            $pdo->prepare('UPDATE equipos SET nombre_manual=1 WHERE id=?')->execute([$equipmentId]);
        }
        $pdo->commit();
        return $nombre;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function monitor_detalle_equipo(PDO $pdo, int $equipmentId): ?array
{
    $st = $pdo->prepare('SELECT monitor_report,monitor_last_seen,monitor_event_mode,monitor_alias FROM equipos WHERE id=? AND monitor_report IS NOT NULL');
    $st->execute([$equipmentId]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    $report = json_decode((string) $row['monitor_report'], true);
    if (!is_array($report)) {
        throw new RuntimeException('El informe de Monitor-Equipos guardado no es JSON válido.');
    }
    $st = $pdo->prepare("SELECT id,equipo_id,alert_key,severidad,titulo,detalle,primera_vez,ultima_vez
        FROM monitor_alertas WHERE equipo_id=? AND activo=1
        ORDER BY CASE severidad WHEN 'critical' THEN 0 ELSE 1 END,primera_vez DESC");
    $st->execute([$equipmentId]);
    $alerts = $st->fetchAll();
    foreach ($alerts as &$alert) {
        $alert['id'] = (int) $alert['id'];
        $alert['equipo_id'] = (int) $alert['equipo_id'];
    }
    unset($alert);
    $st = $pdo->prepare("SELECT COUNT(*) FROM monitor_eventos ev
        WHERE ev.equipo_id=? AND (" . monitor_evento_nivel_sql() . " IN ('Critical','Error'))
            AND (?='full' OR ev.event_time >= UTC_TIMESTAMP() - INTERVAL 30 DAY)");
    $st->execute([$equipmentId, $row['monitor_event_mode']]);
    $eventosWindows = (int) $st->fetchColumn();
    return [
        'monitor' => $report,
        'monitor_alias' => $row['monitor_alias'],
        'monitor_last_seen' => $row['monitor_last_seen'],
        'event_extraction_mode' => $row['monitor_event_mode'],
        'alertas' => $alerts,
        'eventos_windows' => $eventosWindows,
        'copias_en_curso' => monitor_equipo_copias_en_curso($pdo, $equipmentId),
    ];
}

function monitor_evento_nivel_sql(string $alias = 'ev'): string
{
    if (!in_array($alias, ['ev'], true)) {
        throw new InvalidArgumentException('Alias de evento no válido.');
    }
    return "CASE
        WHEN ev.level='Critical' OR ev.level='Level 1' THEN 'Critical'
        WHEN ev.level='Warning' OR ev.level='Level 3' THEN 'Warning'
        WHEN ev.level IN ('Information','Verbose','LogAlways','Level 4','Level 5') THEN 'Information'
        WHEN ev.level='Unknown' THEN 'Warning'
        WHEN ev.level='Level 2' THEN 'Error'
        WHEN ev.level='Error' AND (
            (LOWER(COALESCE(ev.source,'')) LIKE '%distributedcom%' AND ev.event_id=10016)
            OR (LOWER(COALESCE(ev.source,'')) LIKE '%kernel-eventtracing%' AND ev.event_id IN (2,3,4,5,6,15,28))
        ) THEN 'Information'
        WHEN ev.level='Error' AND (
            (LOWER(COALESCE(ev.source,'')) LIKE '%windowsupdateclient%' AND ev.event_id IN (20,21,31,34))
            OR (LOWER(COALESCE(ev.source,'')) LIKE '%distributedcom%' AND ev.event_id=10010)
            OR (LOWER(COALESCE(ev.source,'')) LIKE '%devicesetupmanager%' AND ev.event_id=131)
            OR (LOWER(COALESCE(ev.source,'')) LIKE '%kernel-pnp%' AND ev.event_id=219)
            OR (LOWER(COALESCE(ev.source,'')) LIKE '%appmodel-runtime%' AND ev.event_id IN (65,69))
            OR (ev.log_name='Application' AND ev.event_id=1002)
            OR (ev.log_name='System' AND ev.event_id=6008)
        ) THEN 'Warning'
        ELSE 'Error' END";
}

function monitor_eventos_listar(PDO $pdo, int $page, ?string $search, string $level, ?int $equipoId = null, ?string $dateFrom = null, ?string $dateTo = null): array
{
    if (
        !in_array($level, [
            'all',
            'Critical',
            'Error',
            'Warning',
            'Information',
        ], true) && preg_match('/^Level \d+$/D', $level) !== 1
    ) {
        throw new InvalidArgumentException('El nivel de evento no es válido.');
    }

    $page = max(1, min($page, 100000));
    $pageSize = 10;
    $search = monitor_texto($search, 150) ?? '';
    // El mismo nivel clasificado se usa en el visor, notificaciones e inicio.
    $classifiedLevel = monitor_evento_nivel_sql();
    // El visor recorre todo el historial almacenado. El modo de extracción solo controla
    // qué eventos nuevos recoge el agente; no debe ocultar registros antiguos ya guardados.
    $where = ['1=1'];
    $params = [];
    if ($equipoId !== null) {
        $where[] = 'ev.equipo_id = ?';
        $params[] = $equipoId;
    }
    if ($dateFrom !== null && $dateFrom !== '') {
        $where[] = 'ev.event_time >= ?';
        $params[] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== null && $dateTo !== '') {
        $where[] = 'ev.event_time < DATE_ADD(?, INTERVAL 1 DAY)';
        $params[] = $dateTo . ' 00:00:00';
    }
    if ($level !== 'all') {
        $where[] = "({$classifiedLevel}) = ?";
        $params[] = $level;
    }
    if ($search !== '') {
        $where[] = '(e.nombre LIKE ? OR COALESCE(c.nombre, \'\') LIKE ? OR COALESCE(ev.source, \'\') LIKE ? OR COALESCE(ev.message, \'\') LIKE ?)';
        $term = '%' . $search . '%';
        array_push($params, $term, $term, $term, $term);
    }
    $whereSql = implode(' AND ', $where);

    $count = $pdo->prepare("SELECT COUNT(*) FROM monitor_eventos ev
        JOIN equipos e ON e.id=ev.equipo_id
        LEFT JOIN clientes c ON c.id=e.cliente_id
        WHERE {$whereSql}");
    $count->execute($params);
    $total = (int) $count->fetchColumn();

    $offset = ($page - 1) * $pageSize;
    $rows = $pdo->prepare("SELECT ev.id,
            DATE_FORMAT(ev.event_time,'%Y-%m-%dT%H:%i:%sZ') AS fecha,
            COALESCE(c.nombre,'Sin asignar') AS cliente,e.nombre AS equipo,
            {$classifiedLevel} AS nivel,ev.level AS nivel_original,ev.log_name AS canal,ev.record_id AS registro,
            ev.event_id,ev.source AS origen,ev.message AS mensaje
        FROM monitor_eventos ev
        JOIN equipos e ON e.id=ev.equipo_id
        LEFT JOIN clientes c ON c.id=e.cliente_id
        WHERE {$whereSql}
        ORDER BY ev.event_time DESC,ev.id DESC
        LIMIT {$pageSize} OFFSET {$offset}");
    $rows->execute($params);
    $events = $rows->fetchAll();
    foreach ($events as &$event) {
        $event['id'] = (int) $event['id'];
        $event['registro'] = $event['registro'] !== null ? (int) $event['registro'] : null;
        $event['event_id'] = (int) $event['event_id'];
    }
    unset($event);

    return [
        'eventos' => $events,
        'total' => $total,
        'pagina' => $page,
        'por_pagina' => $pageSize,
    ];
}

function monitor_notificaciones_listar(PDO $pdo, int $page, ?string $search, string $type, string $severity, int $pageSize = 25, ?int $equipmentId = null): array
{
    $types = ['all', 'disco', 'espacio', 'bateria', 'salud', 'crash', 'windows', 'copias_repetidas'];
    $severities = ['all', 'aviso', 'advertencia', 'error', 'critico'];
    if (!in_array($type, $types, true) || !in_array($severity, $severities, true)) {
        throw new InvalidArgumentException('Los filtros de notificaciones no son válidos.');
    }

    $page = max(1, min($page, 100000));
    $pageSize = max(1, min(500, $pageSize));
    $search = monitor_texto($search, 150) ?? '';
    $bugCheck = "(ev.log_name='System' AND ev.event_id=1001
        AND (LOWER(COALESCE(ev.source,'')) LIKE '%bugcheck%'
            OR LOWER(COALESCE(ev.source,'')) LIKE '%wer-systemerrorreporting%'))";
    $classifiedLevel = monitor_evento_nivel_sql();
    $crash = "({$bugCheck}
        OR (ev.log_name='Application' AND ev.event_id IN (1000,1002))
        OR (ev.log_name='Application' AND ev.event_id=1001
            AND (LOWER(COALESCE(ev.source,'')) LIKE '%error reporting%'
                OR LOWER(COALESCE(ev.source,'')) LIKE '%wer%'))
        OR (ev.log_name='System' AND ev.event_id=41))";

    $union = "SELECT CONCAT('salud-',a.id) AS id,
            DATE_FORMAT(a.ultima_vez,'%Y-%m-%dT%H:%i:%s') AS fecha,
            CASE WHEN a.alert_key LIKE 'volume-%' THEN 'espacio'
                WHEN a.alert_key LIKE 'battery-%' THEN 'bateria'
                WHEN a.alert_key LIKE 'disk-%' OR a.alert_key='smart-predict-failure' THEN 'disco'
                ELSE 'salud' END AS tipo,
            CASE a.severidad WHEN 'critical' THEN 'critico' ELSE 'advertencia' END AS nivel,
            a.titulo,a.detalle,COALESCE(c.nombre,'Sin asignar') AS cliente,e.nombre AS equipo,
            e.id AS equipo_id,e.cliente_id,NULL AS copia_id
        FROM monitor_alertas a
        JOIN equipos e ON e.id=a.equipo_id
        LEFT JOIN clientes c ON c.id=e.cliente_id
        WHERE a.activo=1
        UNION ALL
        SELECT CONCAT('evento-',ev.id) AS id,
            DATE_FORMAT(ev.event_time,'%Y-%m-%dT%H:%i:%s') AS fecha,
            CASE WHEN {$crash} THEN 'crash' ELSE 'windows' END AS tipo,
            CASE WHEN ({$classifiedLevel})='Critical' OR {$bugCheck} THEN 'critico'
                WHEN ({$classifiedLevel})='Warning' THEN 'advertencia' ELSE 'error' END AS nivel,
            CASE
                WHEN {$bugCheck} THEN 'Fallo crítico de Windows'
                WHEN ev.log_name='Application' AND ev.event_id=1000 THEN 'Cierre inesperado de aplicación'
                WHEN ev.log_name='Application' AND ev.event_id=1002 THEN 'Aplicación bloqueada'
                WHEN ev.log_name='System' AND ev.event_id=41 THEN 'Apagado inesperado'
                ELSE CONCAT('Evento de Windows: ',COALESCE(NULLIF(ev.source,''),'sin origen'))
            END AS titulo,
            COALESCE(ev.message,'') AS detalle,COALESCE(c.nombre,'Sin asignar') AS cliente,e.nombre AS equipo,
            e.id AS equipo_id,e.cliente_id,NULL AS copia_id
        FROM monitor_eventos ev
        JOIN equipos e ON e.id=ev.equipo_id
        LEFT JOIN clientes c ON c.id=e.cliente_id
        WHERE (({$classifiedLevel}) IN ('Critical','Error','Warning') OR {$bugCheck})
            AND (e.monitor_event_mode='full' OR ev.event_time >= UTC_TIMESTAMP() - INTERVAL 30 DAY)
        UNION ALL
        SELECT CONCAT('copias-',e.id) AS id,
            DATE_FORMAT(MAX(cp.fecha),'%Y-%m-%dT%H:%i:%s') AS fecha,
            'copias_repetidas' AS tipo,
            CASE WHEN COUNT(*) >= 3 THEN 'error' WHEN COUNT(*) = 2 THEN 'advertencia' ELSE 'aviso' END AS nivel,
            CASE WHEN COUNT(*) >= 3 THEN 'Fallos de copia repetidos'
                WHEN COUNT(*) = 2 THEN 'Dos fallos de copia recientes' ELSE 'Fallo de copia' END AS titulo,
            CONCAT(COUNT(*),' fallos registrados en las últimas 24 horas.') AS detalle,
            COALESCE(c.nombre,'Sin asignar') AS cliente,e.nombre AS equipo,
            e.id AS equipo_id,e.cliente_id,MAX(cp.id) AS copia_id
        FROM copias cp
        JOIN equipos e ON e.id=cp.equipo_id
        LEFT JOIN clientes c ON c.id=e.cliente_id
        WHERE cp.estado='ERROR' AND cp.fecha >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY e.id,e.nombre,e.cliente_id,c.nombre";

    $where = [];
    $params = [];
    if ($type !== 'all') {
        $where[] = 'n.tipo=?';
        $params[] = $type;
    }
    if ($severity !== 'all') {
        $where[] = 'n.nivel=?';
        $params[] = $severity;
    }
    if ($equipmentId !== null) {
        $where[] = 'n.equipo_id=?';
        $params[] = $equipmentId;
    }
    if ($search !== '') {
        $where[] = '(n.cliente LIKE ? OR n.equipo LIKE ? OR n.titulo LIKE ? OR n.detalle LIKE ?)';
        $term = '%' . $search . '%';
        array_push($params, $term, $term, $term, $term);
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $count = $pdo->prepare("SELECT COUNT(*) FROM ({$union}) n{$whereSql}");
    $count->execute($params);
    $total = (int) $count->fetchColumn();

    $offset = ($page - 1) * $pageSize;
    $rows = $pdo->prepare("SELECT n.* FROM ({$union}) n{$whereSql}
        ORDER BY n.fecha DESC,n.id DESC LIMIT {$pageSize} OFFSET {$offset}");
    $rows->execute($params);
    $notifications = $rows->fetchAll();
    foreach ($notifications as &$notification) {
        $notification['equipo_id'] = (int) $notification['equipo_id'];
        $notification['cliente_id'] = $notification['cliente_id'] !== null ? (int) $notification['cliente_id'] : null;
        $notification['copia_id'] = $notification['copia_id'] !== null ? (int) $notification['copia_id'] : null;
    }
    unset($notification);

    return [
        'notificaciones' => $notifications,
        'total' => $total,
        'pagina' => $page,
        'por_pagina' => $pageSize,
    ];
}

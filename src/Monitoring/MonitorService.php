<?php
declare(strict_types=1);

/**
 * Normaliza los informes de Monitor-Equipos, los asocia a equipos y mantiene alertas de salud.
 */

function monitor_migrar_esquema(PDO $pdo): void
{
    $columns = $pdo->query("SELECT column_name FROM information_schema.columns
        WHERE table_schema=DATABASE() AND table_name='equipos'")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('monitor_machine_id', $columns, true)) {
        $pdo->exec('ALTER TABLE equipos ADD COLUMN monitor_machine_id VARCHAR(128) NULL');
    }
    if (!in_array('monitor_report', $columns, true)) {
        $pdo->exec('ALTER TABLE equipos ADD COLUMN monitor_report MEDIUMTEXT NULL');
    }
    if (!in_array('monitor_last_seen', $columns, true)) {
        $pdo->exec('ALTER TABLE equipos ADD COLUMN monitor_last_seen DATETIME NULL');
    }
    $index = $pdo->query("SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema=DATABASE() AND table_name='equipos' AND index_name='uq_equipos_monitor_machine_id'")->fetchColumn();
    if (!(int) $index) {
        $pdo->exec('ALTER TABLE equipos ADD UNIQUE KEY uq_equipos_monitor_machine_id (monitor_machine_id)');
    }

    $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/database/monitor.sql');
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        $pdo->exec($statement);
    }
}

function monitor_texto($value, int $max, bool $required = false, string $field = ''): ?string
{
    if (!is_scalar($value)) {
        if ($required) {
            throw new InvalidArgumentException("Falta '$field'");
        }
        return null;
    }
    $text = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', mb_scrub((string) $value)));
    if ($text === '') {
        if ($required) {
            throw new InvalidArgumentException("Falta '$field'");
        }
        return null;
    }
    return mb_substr($text, 0, $max);
}

function monitor_numero($value): ?int
{
    if (!is_numeric($value)) {
        return null;
    }
    $number = (float) $value;
    if (!is_finite($number) || $number < 0 || $number > PHP_INT_MAX) {
        return null;
    }
    return (int) $number;
}

function monitor_campos($source, array $fields): ?array
{
    if (!is_array($source)) {
        return null;
    }
    $result = [];
    foreach ($fields as $field => $type) {
        if (!array_key_exists($field, $source)) {
            continue;
        }
        if ($type === 'text') {
            $value = monitor_texto($source[$field], 500);
        } elseif ($type === 'number') {
            $value = monitor_numero($source[$field]);
        } elseif ($type === 'boolean') {
            $value = is_bool($source[$field]) ? $source[$field] : null;
        } else {
            $value = null;
        }
        if ($value !== null) {
            $result[$field] = $value;
        }
    }
    return $result;
}

function monitor_lista($source, array $fields, int $maxItems): array
{
    if (!is_array($source)) {
        return [];
    }
    $result = [];
    foreach (array_slice($source, 0, $maxItems) as $item) {
        $normalized = monitor_campos($item, $fields);
        if ($normalized !== null) {
            $result[] = $normalized;
        }
    }
    return $result;
}

function monitor_normalizar_informe(array $data, string $machineHeader): array
{
    $machineId = monitor_texto($data['machineId'] ?? null, 128, true, 'machineId');
    $headerId = monitor_texto($machineHeader, 128, true, 'X-Machine-Id');
    if (!hash_equals($machineId, $headerId)) {
        throw new InvalidArgumentException('X-Machine-Id no coincide con machineId');
    }
    $hostname = monitor_texto($data['hostname'] ?? null, 200, true, 'hostname');
    $timestamp = strtotime((string) ($data['timestampUtc'] ?? ''));
    if ($timestamp === false || $timestamp > time() + 300) {
        throw new InvalidArgumentException('timestampUtc no válido');
    }

    $systemFields = [
        'osName' => 'text', 'osVersion' => 'text', 'osArchitecture' => 'text',
        'manufacturer' => 'text', 'model' => 'text', 'serialNumber' => 'text',
        'domain' => 'text', 'totalRamBytes' => 'number', 'lastBootUtc' => 'text',
    ];
    $boardFields = [
        'manufacturer' => 'text', 'product' => 'text', 'serialNumber' => 'text',
        'biosVendor' => 'text', 'biosVersion' => 'text', 'biosDate' => 'text',
    ];
    $cpuFields = [
        'name' => 'text', 'manufacturer' => 'text', 'cores' => 'number',
        'logicalProcessors' => 'number', 'maxClockMHz' => 'number', 'loadPercent' => 'number',
    ];
    $ramFields = [
        'slot' => 'text', 'manufacturer' => 'text', 'partNumber' => 'text',
        'serialNumber' => 'text', 'capacityBytes' => 'number', 'speedMHz' => 'number', 'type' => 'text',
    ];
    $gpuFields = ['name' => 'text', 'driverVersion' => 'text', 'adapterRamBytes' => 'number'];
    $diskFields = [
        'model' => 'text', 'serialNumber' => 'text', 'firmwareVersion' => 'text',
        'mediaType' => 'text', 'busType' => 'text', 'sizeBytes' => 'number',
        'healthStatus' => 'text', 'wearPercent' => 'number', 'lifeLeftPercent' => 'number',
        'temperatureC' => 'number', 'powerOnHours' => 'number',
        'readErrorsTotal' => 'number', 'writeErrorsTotal' => 'number',
    ];
    $volumeFields = [
        'drive' => 'text', 'label' => 'text', 'fileSystem' => 'text',
        'sizeBytes' => 'number', 'freeBytes' => 'number',
    ];
    $batteryFields = [
        'name' => 'text', 'chargePercent' => 'number', 'designCapacity' => 'number',
        'fullChargeCapacity' => 'number', 'healthPercent' => 'number',
    ];

    return [
        'agentVersion' => monitor_texto($data['agentVersion'] ?? null, 40),
        'machineId' => $machineId,
        'hostname' => $hostname,
        'timestampUtc' => gmdate('c', $timestamp),
        'system' => monitor_campos($data['system'] ?? null, $systemFields),
        'board' => monitor_campos($data['board'] ?? null, $boardFields),
        'cpus' => monitor_lista($data['cpus'] ?? null, $cpuFields, 32),
        'ram' => monitor_lista($data['ram'] ?? null, $ramFields, 64),
        'gpus' => monitor_lista($data['gpus'] ?? null, $gpuFields, 16),
        'disks' => monitor_lista($data['disks'] ?? null, $diskFields, 64),
        'volumes' => monitor_lista($data['volumes'] ?? null, $volumeFields, 128),
        'batteries' => monitor_lista($data['batteries'] ?? null, $batteryFields, 16),
        'smartPredictFailure' => is_bool($data['smartPredictFailure'] ?? null) ? $data['smartPredictFailure'] : null,
    ];
}

function monitor_evaluar_alertas(array $report): array
{
    $alerts = [];
    if (($report['smartPredictFailure'] ?? null) === true) {
        $alerts[] = [
            'key' => 'smart-predict-failure',
            'severity' => 'critical',
            'title' => 'SMART predice un fallo',
            'detail' => 'El sistema de almacenamiento informa de un fallo SMART inminente.',
        ];
    }

    foreach (($report['disks'] ?? []) as $index => $disk) {
        $health = strtolower((string) ($disk['healthStatus'] ?? ''));
        if (!in_array($health, ['warning', 'unhealthy'], true)) {
            continue;
        }
        $identity = strtolower((string) ($disk['serialNumber'] ?? $disk['model'] ?? ('disco-' . $index)));
        $alerts[] = [
            'key' => 'disk-' . substr(hash('sha256', $identity), 0, 24),
            'severity' => $health === 'unhealthy' ? 'critical' : 'warning',
            'title' => $health === 'unhealthy' ? 'Disco en estado crítico' : 'Disco con advertencia',
            'detail' => 'Disco ' . ((string) ($disk['model'] ?? '') ?: ('#' . ($index + 1))) . ': ' . $disk['healthStatus'],
        ];
    }

    foreach (($report['volumes'] ?? []) as $index => $volume) {
        $size = (float) ($volume['sizeBytes'] ?? 0);
        $free = (float) ($volume['freeBytes'] ?? 0);
        if ($size <= 0 || $free / $size >= 0.15) {
            continue;
        }
        $drive = (string) ($volume['drive'] ?? ('volumen-' . ($index + 1)));
        $percent = max(0, min(100, $free / $size * 100));
        $alerts[] = [
            'key' => 'volume-' . substr(hash('sha256', strtolower($drive)), 0, 24),
            'severity' => 'warning',
            'title' => 'Poco espacio libre',
            'detail' => sprintf('Volumen %s: %s%% de espacio libre.', $drive, number_format($percent, 1, '.', '')),
        ];
    }

    foreach (($report['batteries'] ?? []) as $index => $battery) {
        $health = $battery['healthPercent'] ?? null;
        if ($health === null || $health >= 50) {
            continue;
        }
        $name = (string) ($battery['name'] ?? ('batería-' . ($index + 1)));
        $alerts[] = [
            'key' => 'battery-' . substr(hash('sha256', strtolower($name)), 0, 24),
            'severity' => 'warning',
            'title' => 'Salud de batería baja',
            'detail' => sprintf('Batería %s: %d%% de salud.', $name, $health),
        ];
    }
    return $alerts;
}

function monitor_guardar_informe(PDO $pdo, array $report): int
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id FROM equipos WHERE monitor_machine_id=?');
        $st->execute([$report['machineId']]);
        $equipmentId = $st->fetchColumn();

        if (!$equipmentId) {
            $st = $pdo->prepare('SELECT id FROM equipos WHERE nombre=? AND monitor_machine_id IS NULL');
            $st->execute([$report['hostname']]);
            $matches = $st->fetchAll(PDO::FETCH_COLUMN);
            if (count($matches) === 1) {
                $equipmentId = (int) $matches[0];
            } else {
                $name = $report['hostname'];
                $st = $pdo->prepare("SELECT id FROM equipos WHERE software='MonitorAgent' AND nombre=?");
                $st->execute([$name]);
                if ($st->fetchColumn()) {
                    $suffix = substr(preg_replace('/[^a-zA-Z0-9]/', '', $report['machineId']), 0, 8);
                    $name = mb_substr($name, 0, 188) . ' [' . ($suffix ?: 'monitor') . ']';
                }
                $pdo->prepare("INSERT INTO equipos (nombre,software,origen,monitor_machine_id) VALUES (?,'MonitorAgent','Monitor-Equipos',?)")
                    ->execute([$name, $report['machineId']]);
                $equipmentId = (int) $pdo->lastInsertId();
            }
        }

        $json = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $pdo->prepare('UPDATE equipos SET monitor_machine_id=?,monitor_report=?,monitor_last_seen=NOW() WHERE id=?')
            ->execute([$report['machineId'], $json, $equipmentId]);

        $current = monitor_evaluar_alertas($report);
        foreach ($current as &$alert) {
            $alert['detail'] = mb_substr($alert['detail'], 0, 500);
        }
        unset($alert);
        $st = $pdo->prepare('SELECT alert_key,activo,primera_vez,email_enviado,email_ultimo_intento
            FROM monitor_alertas WHERE equipo_id=? AND activo=1');
        $st->execute([$equipmentId]);
        $previous = [];
        foreach ($st->fetchAll() as $row) {
            $previous[$row['alert_key']] = $row;
        }
        $pdo->prepare('UPDATE monitor_alertas SET activo=0,resuelto_en=NOW()
            WHERE equipo_id=? AND activo=1')->execute([$equipmentId]);

        $save = $pdo->prepare('INSERT INTO monitor_alertas
            (equipo_id,alert_key,severidad,titulo,detalle,activo,primera_vez,ultima_vez,resuelto_en,email_enviado,email_ultimo_intento)
            VALUES (?,?,?,?,?,1,COALESCE(?,NOW()),NOW(),NULL,?,?)
            ON DUPLICATE KEY UPDATE severidad=VALUES(severidad),titulo=VALUES(titulo),detalle=VALUES(detalle),
                primera_vez=VALUES(primera_vez),activo=1,ultima_vez=NOW(),resuelto_en=NULL,email_enviado=VALUES(email_enviado),
                email_ultimo_intento=VALUES(email_ultimo_intento)');
        foreach ($current as $alert) {
            $wasActive = $previous[$alert['key']] ?? null;
            $save->execute([
                $equipmentId,
                $alert['key'],
                $alert['severity'],
                $alert['title'],
                $alert['detail'],
                $wasActive ? $wasActive['primera_vez'] : null,
                $wasActive ? $wasActive['email_enviado'] : null,
                $wasActive ? $wasActive['email_ultimo_intento'] : null,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    monitor_enviar_alertas_pendientes($pdo);
    return (int) $equipmentId;
}

function monitor_enviar_alertas_pendientes(PDO $pdo): void
{
    $configuredRecipients = (array) cfg('monitor_alert_recipients', []);
    $validRecipients = array_filter(
        $configuredRecipients,
        static function ($address): bool {
            return is_string($address) && filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
        }
    );
    $recipients = array_values(array_unique($validRecipients));
    if (!$recipients) {
        return;
    }
    if (count($validRecipients) !== count($configuredRecipients)) {
        error_log('[monitor] hay destinatarios de alertas con formato no válido en config/config.php');
    }

    $from = (string) cfg('monitor_alert_sender', '');
    if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
        error_log('[monitor] monitor_alert_sender no es una dirección válida; se usará el remitente del servidor');
        $from = '';
    }
    $st = $pdo->query("SELECT a.id,a.titulo,a.detalle,a.severidad,a.ultima_vez,e.nombre AS equipo,c.nombre AS cliente
        FROM monitor_alertas a JOIN equipos e ON e.id=a.equipo_id
        LEFT JOIN clientes c ON c.id=e.cliente_id
        WHERE a.activo=1 AND a.email_enviado IS NULL
            AND (a.email_ultimo_intento IS NULL OR a.email_ultimo_intento < DATE_SUB(NOW(), INTERVAL 1 HOUR))
        ORDER BY a.id");
    $pending = $st->fetchAll();
    if (!$pending) {
        return;
    }

    foreach ($pending as $alert) {
        $claim = $pdo->prepare("UPDATE monitor_alertas SET email_ultimo_intento=NOW()
            WHERE id=? AND activo=1 AND email_enviado IS NULL
                AND (email_ultimo_intento IS NULL OR email_ultimo_intento < DATE_SUB(NOW(), INTERVAL 1 HOUR))");
        $claim->execute([$alert['id']]);
        if ($claim->rowCount() !== 1) {
            continue;
        }
        $subject = '=?UTF-8?B?' . base64_encode('Alerta de salud: ' . $alert['equipo']) . '?=';
        $body = implode("\n", [
            'Se ha detectado una alerta de salud en Monitor-Equipos.',
            '',
            'Nivel: ' . ($alert['severidad'] === 'critical' ? 'Crítico' : 'Advertencia'),
            'Cliente: ' . ($alert['cliente'] ?: 'Sin asignar'),
            'Equipo: ' . $alert['equipo'],
            'Alerta: ' . $alert['titulo'],
            'Detalle: ' . $alert['detalle'],
            'Detectada: ' . $alert['ultima_vez'],
        ]);
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        if ($from !== '') {
            $headers[] = 'From: ' . $from;
        }
        if (count($recipients) > 1) {
            $headers[] = 'Bcc: ' . implode(', ', array_slice($recipients, 1));
        }
        if (!function_exists('mail') || !mail($recipients[0], $subject, $body, implode("\r\n", $headers))) {
            error_log('[monitor] no se pudo enviar correo para la alerta ' . (int) $alert['id']);
            continue;
        }
        $pdo->prepare('UPDATE monitor_alertas SET email_enviado=NOW() WHERE id=?')->execute([$alert['id']]);
    }
}

function monitor_alertas_activas(PDO $pdo): array
{
    $rows = $pdo->query("SELECT a.id,a.equipo_id,a.alert_key,a.severidad,a.titulo,a.detalle,a.primera_vez,a.ultima_vez,
            e.nombre AS equipo,e.cliente_id,c.nombre AS cliente
        FROM monitor_alertas a JOIN equipos e ON e.id=a.equipo_id
        LEFT JOIN clientes c ON c.id=e.cliente_id
        WHERE a.activo=1
        ORDER BY CASE a.severidad WHEN 'critical' THEN 0 ELSE 1 END,a.primera_vez DESC")->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['equipo_id'] = (int) $row['equipo_id'];
        $row['cliente_id'] = $row['cliente_id'] !== null ? (int) $row['cliente_id'] : null;
    }
    unset($row);
    return $rows;
}

function monitor_detalle_equipo(PDO $pdo, int $equipmentId): ?array
{
    $st = $pdo->prepare('SELECT monitor_report,monitor_last_seen FROM equipos WHERE id=? AND monitor_report IS NOT NULL');
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
    return [
        'monitor' => $report,
        'monitor_last_seen' => $row['monitor_last_seen'],
        'alertas' => $alerts,
    ];
}

function monitor_adjuntar_datos(array &$equipos, PDO $pdo): array
{
    $alerts = monitor_alertas_activas($pdo);
    $byEquipment = [];
    foreach ($alerts as $alert) {
        $byEquipment[$alert['equipo_id']][] = $alert;
    }
    foreach ($equipos as &$equipment) {
        $equipment['tiene_monitor'] = (bool) $equipment['tiene_monitor'];
        $equipment['monitor_last_seen'] = $equipment['monitor_last_seen'] ?: null;
        $equipment['alertas'] = $byEquipment[$equipment['id']] ?? [];
    }
    unset($equipment);
    return $alerts;
}

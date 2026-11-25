<?php
declare(strict_types=1);

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
    if (!in_array('monitor_event_mode', $columns, true)) {
        $pdo->exec("ALTER TABLE equipos ADD COLUMN monitor_event_mode VARCHAR(20) NOT NULL DEFAULT 'standard'");
    }
    if (!in_array('monitor_alias', $columns, true)) {
        $pdo->exec('ALTER TABLE equipos ADD COLUMN monitor_alias VARCHAR(100) NULL');
    }
    if (!in_array('rustdesk_password_enc', $columns, true)) {
        $sqlPassword = 'ALTER TABLE equipos ADD COLUMN rustdesk_password_enc TEXT NULL';
        if (function_exists('migracion_ejecutar_sql'))
            migracion_ejecutar_sql($pdo, $sqlPassword, 'columna cifrada de contraseña RustDesk');
        else
            $pdo->exec($sqlPassword);
    }
    $index = $pdo->query("SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema=DATABASE() AND table_name='equipos' AND index_name='uq_equipos_monitor_machine_id'")->fetchColumn();
    if (!(int) $index) {
        $pdo->exec('ALTER TABLE equipos ADD UNIQUE KEY uq_equipos_monitor_machine_id (monitor_machine_id)');
    }

    $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/database/monitor.sql');
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        if (function_exists('migracion_ejecutar_sql'))
            migracion_ejecutar_sql($pdo, $statement, 'database/monitor.sql');
        else
            $pdo->exec($statement);
    }
}

function monitor_guardar_estado_copias(PDO $pdo, array $data, string $machineHeader): int
{
    $machineId = monitor_texto($data['machineId'] ?? null, 128, true, 'machineId');
    $headerId = monitor_texto($machineHeader, 128, true, 'X-Machine-Id');
    if (!hash_equals($machineId, $headerId)) {
        throw new InvalidArgumentException('X-Machine-Id no coincide con machineId');
    }
    $observed = strtotime((string) ($data['observedAtUtc'] ?? ''));
    if ($observed === false || $observed > time() + 300 || $observed < time() - 600) {
        throw new InvalidArgumentException('observedAtUtc no válido');
    }
    $canonical = [
        'acronis' => 'Acronis',
        'iperius' => 'Iperius',
        'veeam' => 'Veeam',
        'data protector' => 'Data Protector',
        'micro focus' => 'Data Protector',
        'arcserve' => 'Arcserve',
        'commvault' => 'Commvault',
        'veritas' => 'Veritas',
        'nakivo' => 'NAKIVO',
        'backup exec' => 'Backup Exec',
        'windows server backup' => 'Windows Server Backup',
        'synology active backup' => 'Synology Active Backup',
    ];
    $scanned = [];
    foreach (array_slice(is_array($data['scannedProducts'] ?? null) ? $data['scannedProducts'] : [], 0, 30) as $value) {
        $name = monitor_texto($value, 40);
        if ($name === null)
            continue;
        $key = mb_strtolower($name);
        if (isset($canonical[$key]))
            $scanned[$canonical[$key]] = true;
    }
    if (!is_array($data['activities'] ?? null) || count($data['activities']) > 200) {
        throw new InvalidArgumentException('activities no válido');
    }

    $source = $pdo->prepare('SELECT id,monitor_report FROM equipos WHERE monitor_machine_id=? LIMIT 1');
    $source->execute([$machineId]);
    $sourceRow = $source->fetch();
    $sourceEquipmentId = $sourceRow ? (int) $sourceRow['id'] : null;
    $sourceReport = is_array($sourceRow) ? json_decode((string) ($sourceRow['monitor_report'] ?? ''), true) : null;
    $sourceProducts = is_array($sourceReport['software']['backupProducts'] ?? null)
        ? array_map(static fn($value): string => mb_strtolower((string) $value), $sourceReport['software']['backupProducts']) : [];

    $findByName = $pdo->prepare('SELECT DISTINCT e.id FROM equipos e LEFT JOIN equipo_nombres n ON n.equipo_id=e.id
        WHERE LOWER(e.nombre)=LOWER(?) OR LOWER(n.nombre)=LOWER(?) ORDER BY e.id LIMIT 2');
    $findByReportHostname = $pdo->prepare("SELECT id FROM equipos WHERE monitor_report IS NOT NULL AND JSON_VALID(monitor_report)
        AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(monitor_report,'$.hostname')))=LOWER(?) ORDER BY id LIMIT 2");
    $equipmentByTarget = [];

    $normalized = [];
    foreach ($data['activities'] as $activity) {
        if (!is_array($activity))
            continue;
        $state = strtolower((string) ($activity['state'] ?? ''));
        if ($state !== 'running')
            continue;
        $givenProduct = monitor_texto($activity['product'] ?? null, 40, true, 'product');
        $product = $canonical[mb_strtolower($givenProduct)] ?? null;
        if ($product === null || !isset($scanned[$product]))
            continue;
        $target = monitor_texto($activity['targetHostname'] ?? null, 200, true, 'targetHostname');
        $session = monitor_texto($activity['sessionKey'] ?? null, 180, true, 'sessionKey');
        $startTime = strtotime((string) ($activity['startedAtUtc'] ?? ''));
        if ($startTime === false || $startTime > time() + 300)
            $startTime = $observed;
        $hostKey = mb_strtolower(trim($target));
        if (!array_key_exists($hostKey, $equipmentByTarget)) {
            $findByName->execute([$target, $target]);
            $matches = array_map('intval', $findByName->fetchAll(PDO::FETCH_COLUMN));
            if (count($matches) !== 1) {
                $findByReportHostname->execute([$target]);
                $matches = array_values(array_unique(array_merge($matches, array_map('intval', $findByReportHostname->fetchAll(PDO::FETCH_COLUMN)))));
            }
            $equipmentByTarget[$hostKey] = $matches;
        }
        $matches = $equipmentByTarget[$hostKey];
        if (count($matches) !== 1)
            continue;
        $remoteManagerProduct = in_array($product, ['Veeam', 'Data Protector'], true);
        if ($remoteManagerProduct) {
            $needle = $product === 'Data Protector' ? 'data protector' : 'veeam';
            $detected = false;
            foreach ($sourceProducts as $sourceProduct) {
                if (
                    str_contains($sourceProduct, $needle)
                    || ($product === 'Data Protector' && str_contains($sourceProduct, 'micro focus'))
                ) {
                    $detected = true;
                    break;
                }
            }
            if (!$detected)
                continue;
        } elseif ($sourceEquipmentId === null || (int) $matches[0] !== $sourceEquipmentId) {
            continue;
        }
        $normalized[] = [
            'equipmentId' => (int) $matches[0],
            'product' => $product,
            'sessionKey' => hash('sha256', $session . "\0" . $target),
            'startedAt' => gmdate('Y-m-d H:i:s', $startTime),
            'activeOperations' => max(1, min(100, monitor_numero($activity['activeOperations'] ?? 1) ?? 1)),
            'source' => monitor_texto($activity['source'] ?? null, 60) ?? 'agent',
        ];
    }

    $pdo->beginTransaction();
    try {
        monitor_agente_comprobar_retirado($pdo, $machineId);
        $pdo->exec('DELETE FROM monitor_backup_activity WHERE observed_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');
        foreach (array_keys($scanned) as $product) {
            $pdo->prepare('DELETE FROM monitor_backup_activity WHERE source_machine_key=? AND product=?')
                ->execute([hash('sha256', $machineId), $product]);
        }
        $insert = $pdo->prepare('INSERT INTO monitor_backup_activity
            (source_machine_id,source_machine_key,equipo_id,product,session_key,started_at,observed_at,active_operations,source)
            VALUES (?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE equipo_id=VALUES(equipo_id),started_at=VALUES(started_at),
                observed_at=VALUES(observed_at),active_operations=VALUES(active_operations),source=VALUES(source)');
        foreach ($normalized as $activity) {
            $insert->execute([
                $machineId,
                hash('sha256', $machineId),
                $activity['equipmentId'],
                $activity['product'],
                $activity['sessionKey'],
                $activity['startedAt'],
                gmdate('Y-m-d H:i:s', $observed),
                $activity['activeOperations'],
                $activity['source'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        throw $e;
    }
    return count($normalized);
}

function monitor_equipo_copias_en_curso(PDO $pdo, int $equipmentId): array
{
    $st = $pdo->prepare("SELECT product,DATE_FORMAT(started_at,'%Y-%m-%dT%H:%i:%sZ') AS started_at,
            DATE_FORMAT(observed_at,'%Y-%m-%dT%H:%i:%sZ') AS observed_at,active_operations
        FROM monitor_backup_activity WHERE equipo_id=?
            AND observed_at >= UTC_TIMESTAMP() - INTERVAL 5 MINUTE
        ORDER BY started_at DESC,product");
    $st->execute([$equipmentId]);
    return $st->fetchAll();
}

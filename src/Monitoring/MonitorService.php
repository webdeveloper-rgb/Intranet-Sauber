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
    if (!in_array('monitor_event_mode', $columns, true)) {
        $pdo->exec("ALTER TABLE equipos ADD COLUMN monitor_event_mode VARCHAR(20) NOT NULL DEFAULT 'standard'");
    }
    if (!in_array('monitor_alias', $columns, true)) {
        $pdo->exec('ALTER TABLE equipos ADD COLUMN monitor_alias VARCHAR(100) NULL');
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

function monitor_lista_textos($source, int $maxItems, int $maxLength): array
{
    if (!is_array($source)) {
        return [];
    }
    $result = [];
    foreach (array_slice($source, 0, $maxItems) as $item) {
        $text = monitor_texto($item, $maxLength);
        if ($text !== null && !in_array($text, $result, true)) {
            $result[] = $text;
        }
    }
    return $result;
}

function monitor_eventos_normalizar($source, int $reportTimestamp, string $eventMode = 'standard'): array
{
    if ($source === null) {
        return [];
    }
    if (!is_array($source) || count($source) > 500) {
        throw new InvalidArgumentException('La lista de eventos no es válida o supera el máximo permitido.');
    }

    $events = [];
    foreach ($source as $index => $event) {
        if (!is_array($event)) {
            throw new InvalidArgumentException("El evento {$index} no es válido.");
        }
        $timeText = monitor_texto($event['timeUtc'] ?? null, 64, true, 'events.timeUtc');
        $eventTimestamp = strtotime($timeText);
        if ($eventTimestamp === false
            || ($eventMode === 'standard' && $eventTimestamp < $reportTimestamp - 30 * 86400)
            || $eventTimestamp > $reportTimestamp + 300) {
            throw new InvalidArgumentException("La fecha del evento {$index} no está dentro del intervalo permitido.");
        }
        $level = monitor_texto($event['level'] ?? null, 20, true, 'events.level');
        $knownLevel = in_array($level, [
            'LogAlways', 'Critical', 'Error', 'Warning', 'Information', 'Verbose', 'Unknown',
        ], true) || preg_match('/^Level \d+$/D', $level) === 1;
        if (!$knownLevel || ($eventMode === 'standard' && !in_array($level, ['Critical', 'Error'], true))) {
            throw new InvalidArgumentException("El nivel del evento {$index} no es válido.");
        }
        $eventId = monitor_numero($event['eventId'] ?? null);
        if ($eventId === null) {
            throw new InvalidArgumentException("Falta el identificador del evento {$index}.");
        }
        $recordId = array_key_exists('recordId', $event) && $event['recordId'] !== null
            ? monitor_numero($event['recordId'])
            : null;
        if (array_key_exists('recordId', $event) && $event['recordId'] !== null && $recordId === null) {
            throw new InvalidArgumentException("El RecordId del evento {$index} no es válido.");
        }

        $events[] = [
            'recordId' => $recordId,
            'timeUtc' => gmdate('Y-m-d H:i:s', $eventTimestamp),
            'log' => monitor_texto($event['log'] ?? null, 128, true, 'events.log'),
            'source' => monitor_texto($event['source'] ?? null, 255),
            'eventId' => $eventId,
            'level' => $level,
            'message' => monitor_texto($event['message'] ?? null, 2000),
        ];
    }
    return $events;
}

function monitor_evento_clave(string $machineId, array $event): string
{
    $identity = [
        $machineId,
        strtolower($event['log']),
        (string) ($event['recordId'] ?? ''),
        $event['timeUtc'],
    ];
    if ($event['recordId'] === null) {
        $identity[] = (string) ($event['source'] ?? '');
        $identity[] = (string) $event['eventId'];
        $identity[] = (string) ($event['message'] ?? '');
    }
    return hash('sha256', implode("\0", $identity));
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
    $eventMode = monitor_texto($data['eventExtractionMode'] ?? 'standard', 20) ?? 'standard';
    if (!in_array($eventMode, ['standard', 'full'], true)) {
        throw new InvalidArgumentException('El modo de extracción de eventos no es válido.');
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
    $software = is_array($data['software'] ?? null) ? $data['software'] : [];

    return [
        'agentVersion' => monitor_texto($data['agentVersion'] ?? null, 40),
        'machineId' => $machineId,
        'hostname' => $hostname,
        'clientNote' => monitor_texto($data['clientNote'] ?? null, 160),
        'timestampUtc' => gmdate('c', $timestamp),
        'rustDeskId' => monitor_texto($data['rustDeskId'] ?? null, 128),
        'eventExtractionMode' => $eventMode,
        'system' => monitor_campos($data['system'] ?? null, $systemFields),
        'software' => [
            'available' => is_array($data['software'] ?? null),
            'windowsRelease' => monitor_texto($software['windowsRelease'] ?? null, 40),
            'windowsLicenseStatus' => in_array($software['windowsLicenseStatus'] ?? null, ['licensed', 'not_licensed', 'unknown'], true)
                ? $software['windowsLicenseStatus'] : 'unknown',
            'antivirusProducts' => monitor_lista_textos($software['antivirusProducts'] ?? null, 20, 160),
            'backupProducts' => monitor_lista_textos($software['backupProducts'] ?? null, 30, 160),
        ],
        'board' => monitor_campos($data['board'] ?? null, $boardFields),
        'cpus' => monitor_lista($data['cpus'] ?? null, $cpuFields, 32),
        'ram' => monitor_lista($data['ram'] ?? null, $ramFields, 64),
        'gpus' => monitor_lista($data['gpus'] ?? null, $gpuFields, 16),
        'disks' => monitor_lista($data['disks'] ?? null, $diskFields, 64),
        'volumes' => monitor_lista($data['volumes'] ?? null, $volumeFields, 128),
        'batteries' => monitor_lista($data['batteries'] ?? null, $batteryFields, 16),
        'smartPredictFailure' => is_bool($data['smartPredictFailure'] ?? null) ? $data['smartPredictFailure'] : null,
        'events' => monitor_eventos_normalizar($data['events'] ?? null, $timestamp, $eventMode),
    ];
}

function monitor_token_emitir(PDO $pdo, string $machineId): string
{
    $pdo->exec("DELETE FROM monitor_ingest_tokens
        WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)
            OR consumed_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
    $token = 'mtr_' . bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO monitor_ingest_tokens (token_hash,machine_id,expires_at)
        VALUES (?,?,DATE_ADD(NOW(), INTERVAL 5 MINUTE))')
        ->execute([hash('sha256', $token), $machineId]);
    return $token;
}

function monitor_enrollment_code_valido(?string $code): bool
{
    return is_string($code) && preg_match('/\Amre_[0-9a-f]{48}\z/', $code) === 1;
}

function monitor_credencial_agente_valida(?string $token): bool
{
    return is_string($token) && preg_match('/\Amra_[0-9a-f]{64}\z/', $token) === 1;
}

function monitor_enrollment_crear(string $createdBy): array
{
    $pdo = db();
    $pdo->exec("DELETE FROM monitor_enrollments
        WHERE (consumed_at IS NOT NULL OR revoked_at IS NOT NULL OR expires_at < NOW())
            AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $code = 'mre_' . bin2hex(random_bytes(24));
    $pdo->prepare('INSERT INTO monitor_enrollments (code_hash,code_prefix,created_by,expires_at)
        VALUES (?,?,?,DATE_ADD(NOW(), INTERVAL 15 MINUTE))')
        ->execute([hash('sha256', $code), substr($code, 0, 12), $createdBy]);
    return [
        'id' => (int) $pdo->lastInsertId(),
        'code' => $code,
        'prefix' => substr($code, 0, 12),
        'expires_in' => 900,
    ];
}

function monitor_enrollment_listar(PDO $pdo): array
{
    $rows = $pdo->query("SELECT id,code_prefix,created_by,consumed_machine_id AS machine_id,
            DATE_FORMAT(created_at,'%Y-%m-%d %H:%i') AS created_at,
            DATE_FORMAT(expires_at,'%Y-%m-%d %H:%i') AS expires_at,
            DATE_FORMAT(consumed_at,'%Y-%m-%d %H:%i') AS consumed_at,
            DATE_FORMAT(revoked_at,'%Y-%m-%d %H:%i') AS revoked_at,
            (consumed_at IS NULL AND revoked_at IS NULL AND expires_at>NOW()) AS active
        FROM monitor_enrollments ORDER BY id DESC LIMIT 100")->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['active'] = (int) $row['active'];
    }
    unset($row);
    return $rows;
}

function monitor_enrollment_revocar(PDO $pdo, int $id): bool
{
    $st = $pdo->prepare('UPDATE monitor_enrollments SET revoked_at=NOW()
        WHERE id=? AND consumed_at IS NULL AND revoked_at IS NULL AND expires_at>NOW()');
    $st->execute([$id]);
    return $st->rowCount() === 1;
}

function monitor_enrollment_redimir(PDO $pdo, ?string $code, string $machineId, ?string $deviceToken): bool
{
    if (!monitor_enrollment_code_valido($code)
        || !preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $machineId)
        || !monitor_credencial_agente_valida($deviceToken)) {
        return false;
    }

    $codeHash = hash('sha256', $code);
    $tokenHash = hash('sha256', $deviceToken);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id,consumed_at,consumed_machine_id,revoked_at,expires_at
            FROM monitor_enrollments WHERE code_hash=? FOR UPDATE');
        $st->execute([$codeHash]);
        $enrollment = $st->fetch();
        if (!$enrollment) {
            $pdo->rollBack();
            return false;
        }

        if ($enrollment['consumed_at'] !== null) {
            $st = $pdo->prepare('SELECT credential_hash,active FROM monitor_agents WHERE machine_id=?');
            $st->execute([$machineId]);
            $agent = $st->fetch();
            $sameRequest = $enrollment['consumed_machine_id'] !== null
                && hash_equals((string) $enrollment['consumed_machine_id'], $machineId)
                && $agent
                && (int) $agent['active'] === 1
                && hash_equals((string) $agent['credential_hash'], $tokenHash);
            $pdo->commit();
            return (bool) $sameRequest;
        }

        if ($enrollment['revoked_at'] !== null || strtotime((string) $enrollment['expires_at']) <= time()) {
            $pdo->rollBack();
            return false;
        }

        $pdo->prepare('UPDATE monitor_enrollments SET consumed_at=NOW(),consumed_machine_id=? WHERE id=?')
            ->execute([$machineId, $enrollment['id']]);
        $pdo->prepare("INSERT INTO monitor_agents (machine_id,credential_hash,active)
            VALUES (?,?,1)
            ON DUPLICATE KEY UPDATE credential_hash=VALUES(credential_hash),active=1,created_at=NOW(),last_used_at=NULL")
            ->execute([$machineId, $tokenHash]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function monitor_agente_autenticar(PDO $pdo, ?string $token, string $machineId): bool
{
    if (!monitor_credencial_agente_valida($token)) {
        return false;
    }
    $st = $pdo->prepare('SELECT credential_hash FROM monitor_agents WHERE machine_id=? AND active=1');
    $st->execute([$machineId]);
    $tokenHash = $st->fetchColumn();
    if (!is_string($tokenHash) || !hash_equals($tokenHash, hash('sha256', $token))) {
        return false;
    }
    $pdo->prepare('UPDATE monitor_agents SET last_used_at=NOW() WHERE machine_id=?')->execute([$machineId]);
    return true;
}

function monitor_token_consumir(PDO $pdo, ?string $token, string $machineId): bool
{
    if ($token === null || !preg_match('/\Amtr_[0-9a-f]{64}\z/', $token)) {
        return false;
    }

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id,machine_id FROM monitor_ingest_tokens
            WHERE token_hash=? AND consumed_at IS NULL AND expires_at>NOW() FOR UPDATE');
        $st->execute([hash('sha256', $token)]);
        $row = $st->fetch();
        if (!$row || !hash_equals((string) $row['machine_id'], $machineId)) {
            $pdo->rollBack();
            return false;
        }
        $st = $pdo->prepare('UPDATE monitor_ingest_tokens SET consumed_at=NOW()
            WHERE id=? AND consumed_at IS NULL');
        $st->execute([$row['id']]);
        if ($st->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
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

function monitor_cliente_texto_normalizar(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    if (function_exists('iconv')) {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii)) {
            $value = strtolower($ascii);
        }
    }
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
    $stop = ['cliente', 'client', 'empresa', 'equipo', 'pc', 'ordenador', 'portatil', 'sede', 'oficina', 'sucursal', 'nota', 'referencia', 'para', 'de', 'del', 'la', 'el', 'los', 'las', 'sl', 'sa', 'srl', 'slu', 'inc', 'ltd'];
    $tokens = array_filter(explode(' ', trim($value)), static fn($token) => $token !== '' && !in_array($token, $stop, true));
    return implode(' ', $tokens);
}

/** Returns one active client only when the note identifies it clearly and uniquely. */
function monitor_cliente_coincidir_nota(PDO $pdo, string $note, bool $requiresSatbk): ?array
{
    $needle = monitor_cliente_texto_normalizar($note);
    if ($needle === '') {
        return null;
    }
    $sql = 'SELECT id,nombre,codigo FROM clientes WHERE activo=1' . ($requiresSatbk ? ' AND tiene_satbk=1' : '');
    $clients = $pdo->query($sql)->fetchAll();
    $exact = [];
    foreach ($clients as $client) {
        foreach ([$client['nombre'] ?? '', $client['codigo'] ?? ''] as $candidate) {
            $normalized = monitor_cliente_texto_normalizar((string) $candidate);
            if ($normalized !== '' && $normalized === $needle) {
                $exact[(int) $client['id']] = $client;
                break;
            }
        }
    }
    if (count($exact) === 1) {
        return array_values($exact)[0];
    }
    if (count($exact) > 1) {
        return null;
    }

    $scores = [];
    foreach ($clients as $client) {
        $name = monitor_cliente_texto_normalizar((string) ($client['nombre'] ?? ''));
        if (strlen($name) < 5) {
            continue;
        }
        similar_text($needle, $name, $percent);
        $score = $percent / 100;
        if (strpos($needle, $name) !== false || strpos($name, $needle) !== false) {
            $score = max($score, 0.94);
        }
        if ($score >= 0.90) {
            $scores[(int) $client['id']] = ['client' => $client, 'score' => $score];
        }
    }
    if (!$scores) {
        return null;
    }
    usort($scores, static fn($a, $b) => $b['score'] <=> $a['score']);
    if ($scores[0]['score'] < 0.92
        || (isset($scores[1]) && $scores[0]['score'] - $scores[1]['score'] < 0.08)) {
        return null;
    }
    return $scores[0]['client'];
}

function monitor_guardar_informe(PDO $pdo, array $report): int
{
    $events = $report['events'] ?? [];
    unset($report['events']);

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
                $pdo->prepare("INSERT INTO equipos (nombre,software,origen,monitor_machine_id,vigilar) VALUES (?,'MonitorAgent','Monitor-Equipos',?,0)")
                    ->execute([$name, $report['machineId']]);
                $equipmentId = (int) $pdo->lastInsertId();
            }
        }

        if (!empty($report['clientNote'])) {
            $pdo->prepare('UPDATE equipos SET sugerido=COALESCE(sugerido,?) WHERE id=?')
                ->execute([$report['clientNote'], $equipmentId]);
            $st = $pdo->prepare('SELECT cliente_id,
                (EXISTS (SELECT 1 FROM copias cp WHERE cp.equipo_id=e.id)
                    OR EXISTS (SELECT 1 FROM correo_copias cc WHERE cc.equipo_id=e.id)) AS tiene_copias
                FROM equipos e WHERE e.id=? FOR UPDATE');
            $st->execute([$equipmentId]);
            $assignment = $st->fetch();
            if ($assignment && $assignment['cliente_id'] === null) {
                $client = monitor_cliente_coincidir_nota($pdo, $report['clientNote'], (bool) $assignment['tiene_copias']);
                if ($client) {
                    $link = $pdo->prepare('UPDATE equipos SET cliente_id=? WHERE id=? AND cliente_id IS NULL');
                    $link->execute([(int) $client['id'], $equipmentId]);
                    if ($link->rowCount() === 1) {
                        $pdo->prepare('UPDATE copias SET cliente=? WHERE equipo_id=?')
                            ->execute([$client['nombre'], $equipmentId]);
                        if (function_exists('correo_importar_equipo')) {
                            correo_importar_equipo($equipmentId, (int) $client['id'], $pdo);
                        }
                    }
                }
            }
        }

        $json = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $pdo->prepare('UPDATE equipos SET monitor_machine_id=?,monitor_report=?,monitor_last_seen=NOW() WHERE id=?')
            ->execute([$report['machineId'], $json, $equipmentId]);

        $saveEvent = $pdo->prepare('INSERT INTO monitor_eventos
            (event_key,machine_id,equipo_id,record_id,event_time,log_name,source,event_id,level,message)
            VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id');
        foreach ($events as $event) {
            $saveEvent->execute([
                monitor_evento_clave($report['machineId'], $event),
                $report['machineId'],
                $equipmentId,
                $event['recordId'],
                $event['timeUtc'],
                $event['log'],
                $event['source'],
                $event['eventId'],
                $event['level'],
                $event['message'],
            ]);
        }

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
    return [
        'monitor' => $report,
        'monitor_alias' => $row['monitor_alias'],
        'monitor_last_seen' => $row['monitor_last_seen'],
        'event_extraction_mode' => $row['monitor_event_mode'],
        'alertas' => $alerts,
    ];
}

function monitor_eventos_listar(PDO $pdo, int $page, ?string $search, string $level, ?int $equipoId = null): array
{
    if (!in_array($level, [
        'all', 'LogAlways', 'Critical', 'Error', 'Warning', 'Information', 'Verbose', 'Unknown',
    ], true) && preg_match('/^Level \d+$/D', $level) !== 1) {
        throw new InvalidArgumentException('El nivel de evento no es válido.');
    }

    $page = max(1, min($page, 100000));
    $pageSize = 50;
    $search = monitor_texto($search, 150) ?? '';
    $where = ["(e.monitor_event_mode='full' OR ev.event_time >= UTC_TIMESTAMP() - INTERVAL 30 DAY)"];
    $params = [];
    if ($equipoId !== null) {
        $where[] = 'ev.equipo_id = ?';
        $params[] = $equipoId;
    }
    if ($level !== 'all') {
        $where[] = 'ev.level = ?';
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
            ev.level AS nivel,ev.log_name AS canal,ev.record_id AS registro,
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

function monitor_notificaciones_listar(PDO $pdo, int $page, ?string $search, string $type, string $severity): array
{
    $types = ['all', 'disco', 'espacio', 'bateria', 'salud', 'crash', 'windows', 'copias_repetidas'];
    $severities = ['all', 'aviso', 'advertencia', 'error', 'critico'];
    if (!in_array($type, $types, true) || !in_array($severity, $severities, true)) {
        throw new InvalidArgumentException('Los filtros de notificaciones no son válidos.');
    }

    $page = max(1, min($page, 100000));
    $pageSize = 25;
    $search = monitor_texto($search, 150) ?? '';
    $bugCheck = "(ev.log_name='System' AND ev.event_id=1001
        AND (LOWER(COALESCE(ev.source,'')) LIKE '%bugcheck%'
            OR LOWER(COALESCE(ev.source,'')) LIKE '%wer-systemerrorreporting%'))";
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
            CASE WHEN ev.level='Critical' OR {$bugCheck} THEN 'critico'
                WHEN ev.level='Warning' THEN 'advertencia' ELSE 'error' END AS nivel,
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
        WHERE ev.level IN ('Critical','Error','Warning')
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

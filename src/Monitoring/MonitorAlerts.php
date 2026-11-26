<?php
declare(strict_types=1);

function monitor_evaluar_alertas(array $report): array
{
    $alerts = [];
    $diskPredictiveFailure = false;
    foreach (($report['disks'] ?? []) as $disk) {
        if (($disk['predictiveFailure'] ?? null) === true
            || stripos((string) ($disk['operationalStatus'] ?? ''), 'predictive failure') !== false) {
            $diskPredictiveFailure = true;
            break;
        }
    }
    if (($report['smartPredictFailure'] ?? null) === true && !$diskPredictiveFailure) {
        $alerts[] = [
            'key' => 'smart-predict-failure',
            'severity' => 'critical',
            'title' => 'SMART predice un fallo',
            'detail' => 'Windows ha recibido una señal de predicción de fallo SMART, pero no ha podido asociarla con precisión a un disco concreto.',
        ];
    }

    foreach (($report['disks'] ?? []) as $index => $disk) {
        $health = strtolower((string) ($disk['healthStatus'] ?? ''));
        $operational = (string) ($disk['operationalStatus'] ?? '');
        $predictiveFailure = ($disk['predictiveFailure'] ?? null) === true
            || stripos($operational, 'predictive failure') !== false;
        if (!in_array($health, ['warning', 'unhealthy'], true) && !$predictiveFailure) {
            continue;
        }
        $identity = strtolower((string) ($disk['serialNumber'] ?? $disk['deviceId'] ?? $disk['model'] ?? ('disco-' . $index)));
        $severity = $health === 'unhealthy' || $predictiveFailure ? 'critical' : 'warning';
        $detail = 'Disco ' . ((string) ($disk['model'] ?? '') ?: ('#' . ($index + 1))) . ': ' . ($disk['healthStatus'] ?? 'estado no disponible');
        if ($operational !== '') $detail .= '; estado operativo: ' . $operational;
        $alerts[] = [
            'key' => 'disk-' . substr(hash('sha256', $identity), 0, 24),
            'severity' => $severity,
            'title' => $predictiveFailure ? 'Disco con predicción de fallo' : ($health === 'unhealthy' ? 'Disco en estado crítico' : 'Disco con advertencia'),
            'detail' => $detail,
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
    $value = strtr($value, [
        'á' => 'a',
        'à' => 'a',
        'ä' => 'a',
        'â' => 'a',
        'é' => 'e',
        'è' => 'e',
        'ë' => 'e',
        'ê' => 'e',
        'í' => 'i',
        'ì' => 'i',
        'ï' => 'i',
        'î' => 'i',
        'ó' => 'o',
        'ò' => 'o',
        'ö' => 'o',
        'ô' => 'o',
        'ú' => 'u',
        'ù' => 'u',
        'ü' => 'u',
        'û' => 'u',
        'ñ' => 'n',
        'ç' => 'c',
    ]);
    if (function_exists('iconv')) {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii)) {
            $value = strtolower($ascii);
        }
    }
    // Normaliza las variantes legales habituales: "S.L.", "S. L." y "Sociedad Limitada"
    // deben equivaler a "SL", para que una referencia breve coincida con la razón social.
    $value = preg_replace(
        '/\b(?:s\.?\s*l\.?u?\.?|s\.?\s*a\.?u?\.?|s\.?\s*r\.?\s*l\.?|sociedad\s+(?:limitada|anonima)|responsabilidad\s+limitada)\b/u',
        ' ',
        $value
    ) ?? $value;
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
    if (
        $scores[0]['score'] < 0.92
        || (isset($scores[1]) && $scores[0]['score'] - $scores[1]['score'] < 0.08)
    ) {
        return null;
    }
    return $scores[0]['client'];
}

/** El nombre puede cambiar; la identidad del agente y el historial se conservan. */
function monitor_actualizar_nombre(PDO $pdo, int $equipmentId, string $hostname): void
{
    $st = $pdo->prepare('SELECT nombre,software,monitor_machine_id,nombre_manual FROM equipos WHERE id=? FOR UPDATE');
    $st->execute([$equipmentId]);
    $equipment = $st->fetch(PDO::FETCH_ASSOC);
    if (!$equipment || $equipment['nombre'] === $hostname) {
        return;
    }

    if (!empty($equipment['nombre_manual'])) {
        // El administrador fijó el nombre visible. Guardamos el hostname reportado como referencia
        // para que los siguientes informes del agente sigan asociándose a esta ficha.
        if (!empty($equipment['monitor_machine_id'])) {
            $find = $pdo->prepare('SELECT equipo_id FROM equipo_nombres WHERE software=? AND nombre=? FOR UPDATE');
            $find->execute(['MonitorAgent', $hostname]);
            $owner = $find->fetchColumn();
            if ($owner === false || (int) $owner === $equipmentId) {
                $pdo->prepare('INSERT IGNORE INTO equipo_nombres (software,nombre,equipo_id) VALUES (?,?,?)')
                    ->execute(['MonitorAgent', $hostname, $equipmentId]);
            }
        }
        return;
    }

    // La clave unica es (software,nombre). Un homonimo no debe fusionar dos equipos.
    $exists = $pdo->prepare('SELECT id FROM equipos WHERE software=? AND nombre=? AND id<>?');
    $name = $hostname;
    $attempt = 0;
    do {
        $exists->execute([$equipment['software'], $name, $equipmentId]);
        if (!$exists->fetchColumn()) {
            break;
        }
        $suffix = ' [' . $equipmentId . (++$attempt > 1 ? '-' . $attempt : '') . ']';
        $name = mb_substr($hostname, 0, 200 - mb_strlen($suffix)) . $suffix;
    } while (true);

    // Conserva el nombre anterior como referencia de recepcion antes de renombrar.
    $findAlias = $pdo->prepare('SELECT equipo_id FROM equipo_nombres WHERE software=? AND nombre=? FOR UPDATE');
    $insertAlias = $pdo->prepare('INSERT INTO equipo_nombres (software,nombre,equipo_id) VALUES (?,?,?)');
    $products = [$equipment['software']];
    if (!empty($equipment['monitor_machine_id']))
        $products[] = 'MonitorAgent';
    foreach (array_unique($products) as $product) {
        $findAlias->execute([$product, $equipment['nombre']]);
        $owner = $findAlias->fetchColumn();
        if ($owner !== false && (int) $owner !== $equipmentId) {
            // Una vinculacion manual anterior tiene prioridad; nunca se reasigna por un renombrado.
            continue;
        }
        if ($owner === false)
            $insertAlias->execute([$product, $equipment['nombre'], $equipmentId]);
    }
    $pdo->prepare('UPDATE equipos SET nombre=? WHERE id=?')->execute([$name, $equipmentId]);
}

/** Busca una ficha monitorizada previa cuando se reinstala el agente y cambia su MachineId local. */
function monitor_equipo_por_hardware(PDO $pdo, array $report): ?array
{
    $normalizar = static function ($value): ?string {
        if (!is_string($value) || trim($value) === '') return null;
        $value = mb_strtoupper(preg_replace('/\s+/u', ' ', trim($value)), 'UTF-8');
        $compact = preg_replace('/[^A-Z0-9]/', '', $value);
        if ($compact === '' || preg_match('/\A(?:0+|F+|UNKNOWN|NONE|NA|NULL|DEFAULTSTRING|SYSTEMSERIALNUMBER|TOBEFILLEDBYOEM)\z/', $compact)) {
            return null;
        }
        return $value;
    };

    $uuid = $normalizar($report['hardwareUuid'] ?? null);
    $systemSerial = $normalizar($report['system']['serialNumber'] ?? null);
    $boardSerial = $normalizar($report['board']['serialNumber'] ?? null);

    if ($uuid !== null) {
        $byUuid = $pdo->prepare("SELECT id,monitor_machine_id FROM equipos
            WHERE monitor_machine_id IS NOT NULL AND monitor_report IS NOT NULL AND JSON_VALID(monitor_report)
                AND UPPER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(monitor_report,'$.hardwareUuid'))))=UPPER(?)
            ORDER BY id LIMIT 2 FOR UPDATE");
        $byUuid->execute([$uuid]);
        $matches = $byUuid->fetchAll(PDO::FETCH_ASSOC);
        if (count($matches) === 1) return $matches[0];
    }

    // Los informes antiguos no guardaban hardwareUuid en el servidor. Los seriales
    // de BIOS/placa ya estaban incluidos y permiten recuperar esas fichas tras el formato.
    foreach ([
        ['$.system.serialNumber', $systemSerial],
        ['$.board.serialNumber', $boardSerial],
    ] as [$path, $serial]) {
        if ($serial === null) continue;
        $bySerial = $pdo->prepare("SELECT id,monitor_machine_id FROM equipos
            WHERE monitor_machine_id IS NOT NULL AND monitor_report IS NOT NULL AND JSON_VALID(monitor_report)
                AND UPPER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(monitor_report,'{$path}'))))=UPPER(?)
            ORDER BY id LIMIT 2 FOR UPDATE");
        $bySerial->execute([$serial]);
        $matches = $bySerial->fetchAll(PDO::FETCH_ASSOC);
        if (count($matches) === 1) return $matches[0];
    }
    return null;
}

function monitor_guardar_informe(PDO $pdo, array $report, ?string $rustDeskPassword = null, ?string &$canonicalMachineId = null): int
{
    $events = $report['events'] ?? [];
    unset($report['events']);

    $pdo->beginTransaction();
    try {
        $requestedMachineId = $report['machineId'];
        monitor_agente_comprobar_retirado($pdo, $requestedMachineId);
        $currentMachineId = monitor_machine_id_canonico($pdo, $requestedMachineId);
        $st = $pdo->prepare('SELECT id,monitor_machine_id FROM equipos WHERE monitor_machine_id=?');
        $st->execute([$currentMachineId]);
        $equipment = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        $equipmentId = $equipment['id'] ?? null;
        $previousMachineId = null;

        if (!$equipmentId) {
            $previous = monitor_equipo_por_hardware($pdo, $report);
            if ($previous) {
                $equipmentId = (int) $previous['id'];
                $previousMachineId = (string) $previous['monitor_machine_id'];
                $equipment = $previous;
            }
        }

        if (!$equipmentId) {
            $st = $pdo->prepare('SELECT id FROM equipos WHERE nombre=? AND monitor_machine_id IS NULL');
            $st->execute([$report['hostname']]);
            $matches = $st->fetchAll(PDO::FETCH_COLUMN);
            if (count($matches) === 1) {
                $equipmentId = (int) $matches[0];
                $equipment = ['id' => $equipmentId, 'monitor_machine_id' => null];
            } else {
                $name = $report['hostname'];
                $st = $pdo->prepare("SELECT id FROM equipos WHERE software='MonitorAgent' AND nombre=?");
                $st->execute([$name]);
                if ($st->fetchColumn()) {
                    $suffix = substr(preg_replace('/[^a-zA-Z0-9]/', '', $report['machineId']), 0, 8);
                    $name = mb_substr($name, 0, 188) . ' [' . ($suffix ?: 'monitor') . ']';
                }
                $pdo->prepare("INSERT INTO equipos (nombre,software,origen,monitor_machine_id,vigilar) VALUES (?,'MonitorAgent','Monitor-Equipos',?,0)")
                    ->execute([$name, $currentMachineId]);
                $equipmentId = (int) $pdo->lastInsertId();
                $equipment = ['id' => $equipmentId, 'monitor_machine_id' => $currentMachineId];
            }
        }

        $targetMachineId = $currentMachineId;
        if (!empty($report['identityMigrationSupported']) && !empty($report['stableMachineId'])) {
            $targetMachineId = (string) $report['stableMachineId'];
        }

        if ($targetMachineId !== $currentMachineId) {
            $targetEquipment = $pdo->prepare('SELECT id FROM equipos WHERE monitor_machine_id=? LIMIT 1 FOR UPDATE');
            $targetEquipment->execute([$targetMachineId]);
            $targetEquipmentId = $targetEquipment->fetchColumn();
            $targetAgent = $pdo->prepare('SELECT machine_id FROM monitor_agents WHERE machine_id=? LIMIT 1 FOR UPDATE');
            $targetAgent->execute([$targetMachineId]);
            $targetAgentExists = $targetAgent->fetchColumn() !== false;

            if (($targetEquipmentId !== false && (int) $targetEquipmentId !== (int) $equipmentId) || $targetAgentExists) {
                $targetMachineId = $currentMachineId;
            }
        }

        if ($targetMachineId !== $currentMachineId) {
            $pdo->prepare('UPDATE monitor_agents SET machine_id=? WHERE machine_id=?')
                ->execute([$targetMachineId, $currentMachineId]);
            monitor_machine_id_alias_guardar($pdo, $currentMachineId, $targetMachineId);
            $pdo->prepare('DELETE FROM monitor_backup_activity WHERE source_machine_key=?')
                ->execute([hash('sha256', $currentMachineId)]);
        }

        if ($previousMachineId !== null && $previousMachineId !== $targetMachineId) {
            $pdo->prepare('UPDATE monitor_agents SET active=0 WHERE machine_id=?')->execute([$previousMachineId]);
            $pdo->prepare('DELETE FROM monitor_ingest_tokens WHERE machine_id=?')->execute([$previousMachineId]);
            $pdo->prepare('DELETE FROM monitor_backup_activity WHERE source_machine_key=?')
                ->execute([hash('sha256', $previousMachineId)]);
            monitor_machine_id_alias_guardar($pdo, $previousMachineId, $targetMachineId);
        }

        $report['machineId'] = $targetMachineId;
        $canonicalMachineId = $targetMachineId;
        $equipment['monitor_machine_id'] = $targetMachineId;

        monitor_actualizar_nombre($pdo, (int) $equipmentId, $report['hostname']);

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

        // Los informes sin lista de programas (el agente la envía solo cada ~12 h) no deben borrar la anterior.
        if (($report['software']['installedPrograms'] ?? null) === null) {
            $previous = $pdo->prepare('SELECT monitor_report FROM equipos WHERE id=?');
            $previous->execute([$equipmentId]);
            $oldReport = json_decode((string) $previous->fetchColumn(), true);
            if (is_array($oldReport['software']['installedPrograms'] ?? null)) {
                $report['software']['installedPrograms'] = $oldReport['software']['installedPrograms'];
            }
        }

        $json = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($rustDeskPassword !== null) {
            $encrypted = monitor_password_cifrar($rustDeskPassword);
            $pdo->prepare('UPDATE equipos SET monitor_machine_id=?,monitor_report=?,rustdesk_password_enc=?,monitor_last_seen=NOW() WHERE id=?')
                ->execute([$report['machineId'], $json, $encrypted, $equipmentId]);
        } else {
            $pdo->prepare('UPDATE equipos SET monitor_machine_id=?,monitor_report=?,monitor_last_seen=NOW() WHERE id=?')
                ->execute([$report['machineId'], $json, $equipmentId]);
        }

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

function monitor_password_clave(): string
{
    $secret = trim((string) cfg('monitor_password_secret', ''));
    if ($secret === '') {
        $database = cfg('db', []);
        $secret = (string) ($database['pass'] ?? '');
    }
    if (strlen($secret) < 16) {
        throw new RuntimeException('Configura monitor_password_secret (mínimo 16 caracteres) o una contraseña de base de datos segura.');
    }
    return hash_hmac('sha256', 'sauber-rustdesk-passwords-v1', $secret, true);
}

function monitor_password_cifrar(string $password): string
{
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('OpenSSL debe estar habilitado para cifrar contraseñas de RustDesk.');
    }
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($password, 'aes-256-gcm', monitor_password_clave(), OPENSSL_RAW_DATA, $nonce, $tag);
    if (!is_string($ciphertext)) {
        throw new RuntimeException('No se pudo cifrar la contraseña de RustDesk.');
    }
    return 'v1:' . base64_encode($nonce . $tag . $ciphertext);
}

function monitor_password_descifrar(string $encoded): string
{
    if (!function_exists('openssl_decrypt') || substr($encoded, 0, 3) !== 'v1:') {
        throw new RuntimeException('Formato de contraseña cifrada no compatible.');
    }
    $payload = base64_decode(substr($encoded, 3), true);
    if (!is_string($payload) || strlen($payload) < 29) {
        throw new RuntimeException('La contraseña cifrada está dañada.');
    }
    $plain = openssl_decrypt(
        substr($payload, 28),
        'aes-256-gcm',
        monitor_password_clave(),
        OPENSSL_RAW_DATA,
        substr($payload, 0, 12),
        substr($payload, 12, 16)
    );
    if (!is_string($plain)) {
        throw new RuntimeException('No se pudo descifrar la contraseña de RustDesk. Revisa la clave del servidor.');
    }
    return $plain;
}

function monitor_password_equipo(PDO $pdo, int $equipmentId): ?string
{
    $st = $pdo->prepare('SELECT rustdesk_password_enc FROM equipos WHERE id=? AND monitor_machine_id IS NOT NULL');
    $st->execute([$equipmentId]);
    $value = $st->fetchColumn();
    if (!is_string($value) || $value === '') {
        return null;
    }
    return monitor_password_descifrar($value);
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

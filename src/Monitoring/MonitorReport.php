<?php
declare(strict_types=1);

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

/**
 * Programas instalados enviados por el agente. Devuelve null si el agente no envía la lista
 * (agente antiguo) y un array, posiblemente vacío, si la envía. Acepta objetos
 * {name, version, publisher} o simples textos, elimina duplicados y ordena por nombre.
 */
function monitor_programas_instalados($source, int $maxItems = 2000): ?array
{
    if (!is_array($source)) {
        return null;
    }
    $result = [];
    $seen = [];
    foreach ($source as $item) {
        if (is_array($item)) {
            $name = monitor_texto($item['name'] ?? null, 200);
            $version = monitor_texto($item['version'] ?? null, 60);
            $publisher = monitor_texto($item['publisher'] ?? null, 160);
        } elseif (is_string($item)) {
            $name = monitor_texto($item, 200);
            $version = null;
            $publisher = null;
        } else {
            continue;
        }
        if ($name === null) {
            continue;
        }
        $key = mb_strtolower($name . "\0" . ($version ?? ''));
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $program = ['name' => $name];
        if ($version !== null) {
            $program['version'] = $version;
        }
        if ($publisher !== null) {
            $program['publisher'] = $publisher;
        }
        $result[] = $program;
        if (count($result) >= $maxItems) {
            break;
        }
    }
    usort($result, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
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
        if (
            $eventTimestamp === false
            || ($eventMode === 'standard' && $eventTimestamp < $reportTimestamp - 30 * 86400)
            || $eventTimestamp > $reportTimestamp + 300
        ) {
            throw new InvalidArgumentException("La fecha del evento {$index} no está dentro del intervalo permitido.");
        }
        $level = monitor_texto($event['level'] ?? null, 20, true, 'events.level');
        $knownLevel = in_array($level, [
            'LogAlways',
            'Critical',
            'Error',
            'Warning',
            'Information',
            'Verbose',
            'Unknown',
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

/** Debe mantenerse alineado con MachineIdentity.cs: UUID determinista de UUID SMBIOS y seriales fiables. */
function monitor_hardware_machine_id(array $data): ?string
{
    $normalizar = static function ($value): ?string {
        if (!is_string($value) || trim($value) === '') return null;
        $value = mb_strtoupper(preg_replace('/\\s+/u', ' ', trim($value)), 'UTF-8');
        $compact = preg_replace('/[^A-Z0-9]/', '', $value);
        if (strlen($compact) < 4 || preg_match('/\\A(?:0+|F+|UNKNOWN|NONE|NA|NULL|DEFAULTSTRING|SYSTEMSERIALNUMBER|TOBEFILLEDBYOEM)\\z/', $compact)) {
            return null;
        }
        return $value;
    };

    $system = is_array($data['system'] ?? null) ? $data['system'] : [];
    $board = is_array($data['board'] ?? null) ? $data['board'] : [];
    $parts = [];
    foreach ([
        ['system-uuid', $data['hardwareUuid'] ?? null],
        ['bios-serial', $system['serialNumber'] ?? null],
        ['board-serial', $board['serialNumber'] ?? null],
    ] as [$label, $value]) {
        $value = $normalizar($value);
        if ($value !== null) $parts[] = $label . '=' . $value;
    }
    if (!$parts) return null;

    $hash = hash('sha256', 'SauberMonitorAgent|hardware-id|v1|' . implode('|', $parts), true);
    $bytes = array_values(unpack('C*', substr($hash, 0, 16)));
    $bytes[7] = ($bytes[7] & 0x0F) | 0x50;
    $bytes[8] = ($bytes[8] & 0x3F) | 0x80;
    // .NET Guid(byte[]) imprime los tres primeros bloques invirtiendo el orden de bytes.
    return sprintf('%02x%02x%02x%02x-%02x%02x-%02x%02x-%02x%02x-%02x%02x%02x%02x%02x%02x',
        $bytes[3], $bytes[2], $bytes[1], $bytes[0], $bytes[5], $bytes[4],
        $bytes[7], $bytes[6], $bytes[8], $bytes[9],
        $bytes[10], $bytes[11], $bytes[12], $bytes[13], $bytes[14], $bytes[15]);
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

    $stableIdCandidate = monitor_texto($data['stableMachineId'] ?? null, 128);
    $hardwareMachineId = monitor_hardware_machine_id($data);
    $stableMachineId = ($data['identityMigrationSupported'] ?? false) === true
        && $stableIdCandidate !== null
        && $hardwareMachineId !== null
        && hash_equals($hardwareMachineId, strtolower($stableIdCandidate))
            ? $hardwareMachineId : null;

    $systemFields = [
        'osName' => 'text',
        'osVersion' => 'text',
        'osArchitecture' => 'text',
        'manufacturer' => 'text',
        'model' => 'text',
        'serialNumber' => 'text',
        'domain' => 'text',
        'totalRamBytes' => 'number',
        'lastBootUtc' => 'text',
    ];
    $boardFields = [
        'manufacturer' => 'text',
        'product' => 'text',
        'serialNumber' => 'text',
        'biosVendor' => 'text',
        'biosVersion' => 'text',
        'biosDate' => 'text',
    ];
    $cpuFields = [
        'name' => 'text',
        'manufacturer' => 'text',
        'cores' => 'number',
        'logicalProcessors' => 'number',
        'maxClockMHz' => 'number',
        'loadPercent' => 'number',
    ];
    $ramFields = [
        'slot' => 'text',
        'manufacturer' => 'text',
        'partNumber' => 'text',
        'serialNumber' => 'text',
        'capacityBytes' => 'number',
        'speedMHz' => 'number',
        'type' => 'text',
    ];
    $gpuFields = ['name' => 'text', 'driverVersion' => 'text', 'adapterRamBytes' => 'number'];
    $diskFields = [
        'deviceId' => 'text',
        'model' => 'text',
        'serialNumber' => 'text',
        'firmwareVersion' => 'text',
        'mediaType' => 'text',
        'busType' => 'text',
        'sizeBytes' => 'number',
        'healthStatus' => 'text',
        'operationalStatus' => 'text',
        'predictiveFailure' => 'boolean',
        'wearPercent' => 'number',
        'lifeLeftPercent' => 'number',
        'temperatureC' => 'number',
        'temperatureMaxC' => 'number',
        'powerOnHours' => 'number',
        'readErrorsTotal' => 'text',
        'readErrorsCorrected' => 'text',
        'readErrorsUncorrected' => 'text',
        'writeErrorsTotal' => 'text',
        'writeErrorsCorrected' => 'text',
        'writeErrorsUncorrected' => 'text',
        'readLatencyMaxMs' => 'text',
        'writeLatencyMaxMs' => 'text',
        'flushLatencyMaxMs' => 'text',
        'startStopCycleCount' => 'number',
        'startStopCycleCountMax' => 'number',
        'loadUnloadCycleCount' => 'number',
        'loadUnloadCycleCountMax' => 'number',
    ];
    $volumeFields = [
        'drive' => 'text',
        'label' => 'text',
        'fileSystem' => 'text',
        'sizeBytes' => 'number',
        'freeBytes' => 'number',
    ];
    $batteryFields = [
        'name' => 'text',
        'chargePercent' => 'number',
        'designCapacity' => 'number',
        'fullChargeCapacity' => 'number',
        'healthPercent' => 'number',
    ];
    $software = is_array($data['software'] ?? null) ? $data['software'] : [];

    return [
        'agentVersion' => monitor_texto($data['agentVersion'] ?? null, 40),
        'machineId' => $machineId,
        'stableMachineId' => $stableMachineId,
        'identityMigrationSupported' => ($data['identityMigrationSupported'] ?? false) === true,
        'hardwareUuid' => monitor_texto($data['hardwareUuid'] ?? null, 128),
        'hostname' => $hostname,
        'clientNote' => monitor_texto($data['clientNote'] ?? null, 160),
        'timestampUtc' => gmdate('c', $timestamp),
        'rustDeskId' => monitor_texto($data['rustDeskId'] ?? null, 128),
        'collectionErrors' => monitor_lista_textos($data['collectionErrors'] ?? null, 40, 500),
        'eventExtractionMode' => $eventMode,
        'system' => monitor_campos($data['system'] ?? null, $systemFields),
        'software' => [
            'available' => is_array($data['software'] ?? null),
            'windowsRelease' => monitor_texto($software['windowsRelease'] ?? null, 40),
            'windowsLicenseStatus' => in_array($software['windowsLicenseStatus'] ?? null, ['licensed', 'not_licensed', 'unknown'], true)
                ? $software['windowsLicenseStatus'] : 'unknown',
            'antivirusProducts' => monitor_lista_textos($software['antivirusProducts'] ?? null, 20, 160),
            'backupProducts' => monitor_lista_textos($software['backupProducts'] ?? null, 30, 160),
            // El agente envía la lista en la raíz del informe (installedPrograms), y solo cada ~12 h.
            'installedPrograms' => monitor_programas_instalados($data['installedPrograms'] ?? $software['installedPrograms'] ?? null),
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

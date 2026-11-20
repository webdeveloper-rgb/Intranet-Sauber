<?php
declare(strict_types=1);

require __DIR__ . '/../src/Monitoring/MonitorService.php';

$checks = 0;
$reportTime = time();
$same = static function ($expected, $actual, string $case) use (&$checks): void {
    $checks++;
    if ($expected !== $actual) {
        fwrite(STDERR, "{$case}: esperado " . var_export($expected, true) . ', recibido ' . var_export($actual, true) . "\n");
        exit(1);
    }
};

$report = monitor_normalizar_informe([
    'agentVersion' => '1.0',
    'machineId' => 'machine-123',
    'hostname' => 'PC-CLIENTE',
    'timestampUtc' => gmdate('c', $reportTime),
    'rustDeskId' => '123456789',
    'system' => ['osName' => 'Windows', 'totalRamBytes' => 8589934592, 'campoNoAdmitido' => 'no guardar'],
    'disks' => [
        ['model' => 'Disco crítico', 'healthStatus' => 'Unhealthy', 'temperatureC' => 61],
        ['model' => 'Disco advertencia', 'healthStatus' => 'Warning'],
        ['model' => 'Disco sano', 'healthStatus' => 'Healthy'],
    ],
    'volumes' => [
        ['drive' => 'C:', 'sizeBytes' => 1000, 'freeBytes' => 149],
        ['drive' => 'D:', 'sizeBytes' => 1000, 'freeBytes' => 150],
    ],
    'batteries' => [
        ['name' => 'Batería baja', 'healthPercent' => 49],
        ['name' => 'Batería límite', 'healthPercent' => 50],
    ],
    'smartPredictFailure' => true,
    'events' => [[
        'recordId' => 55,
        'timeUtc' => gmdate('c', $reportTime - 60),
        'log' => 'System',
        'source' => 'Service Control Manager',
        'eventId' => 7000,
        'level' => 'Error',
        'message' => 'No se pudo iniciar el servicio.',
    ]],
], 'machine-123');

$same('PC-CLIENTE', $report['hostname'], 'hostname normalizado');
$same('123456789', $report['rustDeskId'], 'se conserva el ID de RustDesk');
$clasificacionEventos = monitor_evento_nivel_sql();
$same(true, str_contains($clasificacionEventos, 'windowsupdateclient'), 'la clasificación compartida rebaja errores conocidos de Windows Update');
$same(true, str_contains($clasificacionEventos, "THEN 'Information'"), 'la clasificación compartida conserva casos informativos');
$same('standard', $report['eventExtractionMode'], 'el modo por defecto es estándar');
$same(null, $report['system']['campoNoAdmitido'] ?? null, 'se descartan campos no admitidos');
$same(1, count($report['events']), 'se normalizan los eventos recibidos');
$same('System', $report['events'][0]['log'], 'se conserva el registro de eventos');
$same('Error', $report['events'][0]['level'], 'se conserva el nivel del evento');
$same(
    monitor_evento_clave('machine-123', $report['events'][0]),
    monitor_evento_clave('machine-123', $report['events'][0]),
    'la clave del evento es estable para reintentos'
);
$same(
    false,
    monitor_evento_clave('machine-123', $report['events'][0]) === monitor_evento_clave('machine-456', $report['events'][0]),
    'la clave separa eventos de equipos distintos'
);
$same(true, monitor_enrollment_code_valido('mre_' . str_repeat('a', 48)), 'se acepta el formato del código de alta');
$same(false, monitor_enrollment_code_valido('sbr_' . str_repeat('a', 48)), 'se rechaza un prefijo de código incorrecto');
$same(true, monitor_credencial_agente_valida('mra_' . str_repeat('0', 64)), 'se acepta el formato de credencial de agente');
$same(false, monitor_credencial_agente_valida('mra_' . str_repeat('g', 64)), 'se rechaza una credencial con caracteres inválidos');

$alerts = monitor_evaluar_alertas($report);
$same(5, count($alerts), 'alertas de SMART, discos, espacio y batería');
$same('critical', $alerts[0]['severity'], 'SMART es crítico');
$same('critical', $alerts[1]['severity'], 'disco Unhealthy es crítico');
$same('warning', $alerts[2]['severity'], 'disco Warning es advertencia');
$same('warning', $alerts[3]['severity'], 'menos del 15% libre es advertencia');
$same('warning', $alerts[4]['severity'], 'salud inferior al 50% es advertencia');

$sinAlertas = monitor_evaluar_alertas([
    'smartPredictFailure' => false,
    'disks' => [['healthStatus' => 'Healthy']],
    'volumes' => [['sizeBytes' => 1000, 'freeBytes' => 150]],
    'batteries' => [['healthPercent' => 50]],
]);
$same([], $sinAlertas, 'los valores sanos y los umbrales exactos no alertan');

$checks++;
try {
    monitor_normalizar_informe([
        'machineId' => 'machine-123',
        'hostname' => 'PC-CLIENTE',
        'timestampUtc' => gmdate('c', $reportTime),
        'events' => [[
            'timeUtc' => gmdate('c', $reportTime - 31 * 86400),
            'log' => 'System',
            'eventId' => 7000,
            'level' => 'Error',
        ]],
    ], 'machine-123');
    fwrite(STDERR, "Evento fuera de los últimos 30 días: se esperaba InvalidArgumentException\n");
    exit(1);
} catch (InvalidArgumentException $e) {
    $same(true, strpos($e->getMessage(), 'fecha del evento') !== false, 'se rechazan eventos anteriores al límite de 30 días');
}

$auditoriaCompleta = monitor_normalizar_informe([
    'machineId' => 'machine-123',
    'hostname' => 'PC-CLIENTE',
    'timestampUtc' => gmdate('c', $reportTime),
    'eventExtractionMode' => 'full',
    'events' => [
        [
            'timeUtc' => gmdate('c', $reportTime - 365 * 86400),
            'log' => 'Application',
            'eventId' => 1000,
            'level' => 'Error',
        ],
        [
            'timeUtc' => gmdate('c', $reportTime - 365 * 86400),
            'log' => 'Security',
            'eventId' => 4624,
            'level' => 'Information',
        ],
        [
            'timeUtc' => gmdate('c', $reportTime - 365 * 86400),
            'log' => 'System',
            'eventId' => 1,
            'level' => 'Warning',
        ],
        [
            'timeUtc' => gmdate('c', $reportTime - 365 * 86400),
            'log' => 'ForwardedEvents',
            'eventId' => 2,
            'level' => 'Verbose',
        ],
        [
            'timeUtc' => gmdate('c', $reportTime - 365 * 86400),
            'log' => 'Setup',
            'eventId' => 3,
            'level' => 'LogAlways',
        ],
        [
            'timeUtc' => gmdate('c', $reportTime - 365 * 86400),
            'log' => 'CustomChannel',
            'eventId' => 4,
            'level' => 'Level 17',
        ],
    ],
], 'machine-123');
$same(6, count($auditoriaCompleta['events']), 'auditoría completa acepta eventos históricos de todos los niveles y canales');
$same('full', $auditoriaCompleta['eventExtractionMode'], 'se conserva el modo de auditoría completa');
$same('Information', $auditoriaCompleta['events'][1]['level'], 'se conserva un nivel informativo en auditoría completa');
$same('Level 17', $auditoriaCompleta['events'][5]['level'], 'se conservan niveles no estándar de Windows');

$checks++;
try {
    monitor_eventos_normalizar([[
        'timeUtc' => gmdate('c', $reportTime),
        'log' => 'System',
        'eventId' => 1,
        'level' => 'Warning',
    ]], $reportTime);
    fwrite(STDERR, "Nivel Warning en modo estándar: se esperaba InvalidArgumentException\n");
    exit(1);
} catch (InvalidArgumentException $e) {
    $same(true, strpos($e->getMessage(), 'nivel del evento') !== false, 'estándar solo acepta niveles Critical y Error');
}

$checks++;
try {
    monitor_normalizar_informe([
        'machineId' => 'machine-123',
        'hostname' => 'PC-CLIENTE',
        'timestampUtc' => gmdate('c'),
    ], 'otro-machine');
    fwrite(STDERR, "X-Machine-Id no coincidente: se esperaba InvalidArgumentException\n");
    exit(1);
} catch (InvalidArgumentException $e) {
    $same('X-Machine-Id no coincide con machineId', $e->getMessage(), 'se valida la identidad de la máquina');
}

echo "{$checks} comprobaciones de Monitor-Equipos correctas.\n";

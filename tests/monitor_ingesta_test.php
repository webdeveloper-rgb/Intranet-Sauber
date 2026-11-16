<?php
declare(strict_types=1);

require __DIR__ . '/../src/Monitoring/MonitorService.php';

$checks = 0;
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
    'timestampUtc' => gmdate('c'),
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
    'events' => [['message' => 'no guardar mensajes de eventos']],
], 'machine-123');

$same('PC-CLIENTE', $report['hostname'], 'hostname normalizado');
$same(null, $report['system']['campoNoAdmitido'] ?? null, 'se descartan campos no admitidos');
$same(false, array_key_exists('events', $report), 'no se almacenan mensajes de eventos');

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
        'timestampUtc' => gmdate('c'),
    ], 'otro-machine');
    fwrite(STDERR, "X-Machine-Id no coincidente: se esperaba InvalidArgumentException\n");
    exit(1);
} catch (InvalidArgumentException $e) {
    $same('X-Machine-Id no coincide con machineId', $e->getMessage(), 'se valida la identidad de la máquina');
}

echo "{$checks} comprobaciones de Monitor-Equipos correctas.\n";

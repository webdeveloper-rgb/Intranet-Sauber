<?php
declare(strict_types=1);

require __DIR__ . '/../src/Monitoring/MonitorService.php';

// SQLite permite comprobar las escrituras reales; FOR UPDATE es propio de MySQL.
$pdo = new class('sqlite::memory:') extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
};
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE equipos (
    id INTEGER PRIMARY KEY, nombre TEXT, software TEXT, monitor_machine_id TEXT,
    cliente_id INTEGER, UNIQUE(software,nombre))');
$pdo->exec("INSERT INTO equipos VALUES (1,'PC-ANTIGUO','MonitorAgent','machine-1',10),
    (2,'PC-OCUPADO','MonitorAgent','machine-2',20),
    (3,'PC-OCUPADO [1]','MonitorAgent','machine-3',20)");
$checks = 0;
$same = static function ($expected, $actual) use (&$checks): void {
    $checks++;
    if ($expected !== $actual) {
        throw new RuntimeException(var_export([$expected, $actual], true));
    }
};
$name = static fn(): string => $pdo->query('SELECT nombre FROM equipos WHERE id=1')->fetchColumn();

monitor_actualizar_nombre($pdo, 1, 'PC-NUEVO');
$same('PC-NUEVO', $name());
$same(['monitor_machine_id' => 'machine-1', 'cliente_id' => 10],
    $pdo->query('SELECT monitor_machine_id,cliente_id FROM equipos WHERE id=1')->fetch(PDO::FETCH_ASSOC));
monitor_actualizar_nombre($pdo, 1, 'PC-NUEVO');
$same('PC-NUEVO', $name());
monitor_actualizar_nombre($pdo, 1, 'PC-OCUPADO');
$same('PC-OCUPADO [1-2]', $name());
$same('PC-OCUPADO', $pdo->query('SELECT nombre FROM equipos WHERE id=2')->fetchColumn());
monitor_actualizar_nombre($pdo, 1, 'PC-LIBRE');
$same('PC-LIBRE', $name());
$longName = str_repeat('Ñ', 200);
$pdo->prepare('INSERT INTO equipos VALUES (4,?,?,?,NULL)')->execute([$longName, 'MonitorAgent', 'machine-4']);
monitor_actualizar_nombre($pdo, 1, $longName);
$same(200, mb_strlen($name()));
$same(true, str_ends_with($name(), ' [1]'));
$same(4, (int) $pdo->query('SELECT COUNT(*) FROM equipos')->fetchColumn());

echo "{$checks} comprobaciones de cambio de nombre correctas.\n";

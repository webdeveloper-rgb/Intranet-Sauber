<?php
declare(strict_types=1);

require __DIR__ . '/../src/Equipment/EquipmentService.php';

// SQLite temporal: adapta solo sintaxis MySQL; se ejecutan los servicios reales.
final class MergeTestPDO extends PDO
{
    private function translate(string $sql): string
    {
        if ($sql === 'SHOW WARNINGS') return 'SELECT NULL WHERE 0';
        $sql = str_replace([' FOR UPDATE', 'INSERT IGNORE INTO', 'NOW()', 'ON DUPLICATE KEY UPDATE id=id'],
            ['', 'INSERT OR IGNORE INTO', 'CURRENT_TIMESTAMP', 'ON CONFLICT(huella) DO NOTHING'], $sql);
        return $sql;
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare($this->translate($query), $options);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
    {
        return $fetchMode === null ? parent::query($this->translate($query)) : parent::query($this->translate($query), $fetchMode, ...$args);
    }
}
function fixture(): PDO
{
    $p = new MergeTestPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $p->exec('PRAGMA foreign_keys=ON');
    $p->exec("CREATE TABLE clientes(id INTEGER PRIMARY KEY,nombre TEXT,activo INT);
        INSERT INTO clientes VALUES(1,'Sauber',1),(2,'Otro cliente',1);
        CREATE TABLE equipos(id INTEGER PRIMARY KEY,nombre TEXT,software TEXT,cliente_id INT,monitor_machine_id TEXT UNIQUE,
          monitor_report TEXT,monitor_last_seen TEXT,monitor_event_mode TEXT DEFAULT 'standard',monitor_alias TEXT,
          rustdesk_password_enc TEXT,origen TEXT,sugerido TEXT,tipo TEXT,tipo_manual TEXT,programa TEXT,
          error_reviewed_at TEXT,error_reviewed_by TEXT,error_review_desc TEXT,vigilar INT DEFAULT 1,dias_copia TEXT DEFAULT '1111100');
        INSERT INTO equipos(id,nombre,software,cliente_id,monitor_machine_id) VALUES
          (1,'SAUBER OFIMATICA - PC Ventas','Acronis',1,NULL),(2,'Alba-PC','Acronis',1,'machine-alba'),(3,'Alba anterior','Iperius',NULL,NULL),
          (4,'Otra empresa','Acronis',2,NULL),(5,'Otro agente','MonitorAgent',1,'machine-otro');
        CREATE TABLE copias(id INTEGER PRIMARY KEY,fecha TEXT,cliente TEXT,equipo TEXT,equipo_id INT REFERENCES equipos(id),
          estado TEXT,desc_error TEXT,codigo TEXT,resultado_original TEXT,clasificacion_fuente TEXT,clasificacion_evidencia TEXT,
          software TEXT,destino TEXT,tamano TEXT,duracion TEXT,huella TEXT UNIQUE,tipo TEXT,programa TEXT,revisado INT DEFAULT 0);
        INSERT INTO copias(id,fecha,cliente,equipo,equipo_id,estado,software,huella,revisado) VALUES
          (10,'2026-11-19','Sauber','Alba-PC',2,'ERROR','Acronis','old-copy',1),
          (11,'2026-11-19','Sauber','Ventas',1,'OK','Acronis','target-copy',0);
        CREATE TABLE notas(id INTEGER PRIMARY KEY,copia_id INT REFERENCES copias(id) ON DELETE CASCADE,texto TEXT);
        INSERT INTO notas VALUES(1,10,'Nota tecnica conservada');
        CREATE TABLE correo_copias(id INTEGER PRIMARY KEY,message_key TEXT UNIQUE,equipo_id INT REFERENCES equipos(id),fecha TEXT,
          estado TEXT,desc_error TEXT DEFAULT '',codigo TEXT,resultado_original TEXT DEFAULT '',clasificacion_fuente TEXT DEFAULT '',
          clasificacion_evidencia TEXT DEFAULT '',software TEXT,destino TEXT,tamano TEXT,duracion TEXT,importada INT DEFAULT 0);
        INSERT INTO correo_copias(id,message_key,equipo_id,fecha,estado,software) VALUES(1,'pending-copy',3,'2026-11-19','OK','Iperius');
        CREATE TABLE correo_copias_eliminadas(message_key TEXT PRIMARY KEY,equipo_id INT);
        INSERT INTO correo_copias_eliminadas VALUES('deleted-copy',2);
        CREATE TABLE monitor_eventos(id INTEGER PRIMARY KEY,equipo_id INT REFERENCES equipos(id) ON DELETE CASCADE);
        INSERT INTO monitor_eventos VALUES(1,2);
        CREATE TABLE monitor_backup_activity(id INTEGER PRIMARY KEY,equipo_id INT REFERENCES equipos(id) ON DELETE CASCADE);
        INSERT INTO monitor_backup_activity VALUES(1,2);
        CREATE TABLE monitor_alertas(id INTEGER PRIMARY KEY,equipo_id INT REFERENCES equipos(id) ON DELETE CASCADE,alert_key TEXT,
          activo INT,severidad TEXT,primera_vez TEXT,ultima_vez TEXT,resuelto_en TEXT,UNIQUE(equipo_id,alert_key));
        INSERT INTO monitor_alertas VALUES(1,1,'disk',0,'warning','2026-11-10','2026-11-15','2026-11-15'),
          (2,2,'disk',1,'critical','2026-11-12','2026-11-19',NULL);
        CREATE TABLE equipo_nombres(id INTEGER PRIMARY KEY,software TEXT,nombre TEXT,equipo_id INT REFERENCES equipos(id) ON DELETE CASCADE,UNIQUE(software,nombre));
        CREATE TABLE equipo_combinaciones(id INTEGER PRIMARY KEY,destino_id INT,usuario TEXT,detalle TEXT);");
    return $p;
}
function check(bool $value, string $case): void { if (!$value) throw new RuntimeException($case); }
function rejected(PDO $p, int $target, array $sources, string $case): void
{
    try { equipos_combinar_plan($p, $target, $sources); }
    catch (ApiError $e) { return; }
    throw new RuntimeException('Debia rechazar ' . $case);
}
$p = fixture();
rejected($p, 1, [4], 'clientes distintos');
rejected($p, 1, [2,5], 'dos agentes');
rejected($p, 1, [1], 'destino incluido');
rejected($p, 1, [999], 'equipo inexistente');
$plan = equipos_combinar_plan($p, 1, [2,3]);
check($plan['destino']['nombre'] === 'SAUBER OFIMATICA - PC Ventas', 'destino correcto');
check($plan['origenes'][0]['copias'] === 1, 'vista previa correcta');
equipos_combinar($p, 1, [2,3], $plan['token'], 'tecnico');
check((int) $p->query('SELECT COUNT(*) FROM equipos WHERE id IN (2,3)')->fetchColumn() === 0, 'origenes retirados');
check($p->query('SELECT monitor_machine_id FROM equipos WHERE id=1')->fetchColumn() === 'machine-alba', 'agente trasladado');
check((int) $p->query('SELECT equipo_id FROM copias WHERE id=10')->fetchColumn() === 1, 'historial trasladado');
check($p->query('SELECT equipo FROM copias WHERE id=10')->fetchColumn() === 'SAUBER OFIMATICA - PC Ventas', 'nombre del destino en el historial');
check((int) $p->query('SELECT revisado FROM copias WHERE id=10')->fetchColumn() === 1, 'revision conservada');
check($p->query('SELECT texto FROM notas WHERE copia_id=10')->fetchColumn() === 'Nota tecnica conservada', 'notas conservadas');
check((int) $p->query('SELECT COUNT(*) FROM copias')->fetchColumn() === 3, 'staging importado sin duplicar');
foreach (['correo_copias', 'correo_copias_eliminadas', 'monitor_eventos', 'monitor_backup_activity', 'equipo_nombres'] as $table) {
    check((int) $p->query("SELECT COUNT(*) FROM $table WHERE equipo_id IN (2,3)")->fetchColumn() === 0, 'referencias trasladadas ' . $table);
}
check((int) $p->query('SELECT COUNT(*) FROM monitor_alertas')->fetchColumn() === 1, 'alerta reconciliada');
check($p->query('SELECT severidad FROM monitor_alertas')->fetchColumn() === 'critical', 'alerta critica conservada');
check((int) $p->query('SELECT COUNT(*) FROM equipo_combinaciones')->fetchColumn() === 1, 'auditoria guardada');
$d = ['message_key'=>'next-copy', 'nombre'=>'Alba-PC', 'software'=>'Acronis', 'sugerido'=>null, 'tipo'=>'usuario', 'programa'=>null,
    'origen'=>'Alba-PC', 'fecha'=>'2026-11-19', 'estado'=>'OK', 'desc_error'=>'', 'codigo'=>null, 'resultado_original'=>'OK',
    'clasificacion_fuente'=>'test', 'clasificacion_evidencia'=>'', 'destino'=>null, 'tamano'=>null, 'duracion'=>null];
ingesta_guardar($p, $d);
check((int) $p->query("SELECT equipo_id FROM copias WHERE huella='next-copy'")->fetchColumn() === 1, 'proxima copia del nombre antiguo vinculada');
check((int) $p->query("SELECT COUNT(*) FROM equipos WHERE nombre='Alba-PC'")->fetchColumn() === 0, 'sin recrear origen');
$p = fixture(); $plan = equipos_combinar_plan($p,1,[2]);
$p->exec("UPDATE equipos SET nombre='Nombre cambiado' WHERE id=2");
try { equipos_combinar($p,1,[2],$plan['token'],'tecnico'); throw new RuntimeException('Token obsoleto aceptado'); }
catch (ApiError $e) { check((int) $p->query('SELECT COUNT(*) FROM equipos')->fetchColumn() === 5, 'sin cambios con plan obsoleto'); }
$p = fixture(); $p->exec("INSERT INTO equipo_nombres(software,nombre,equipo_id) VALUES('Acronis','Alba-PC',4)");
$plan = equipos_combinar_plan($p,1,[2]);
try { equipos_combinar($p,1,[2],$plan['token'],'tecnico'); throw new RuntimeException('Alias conflictivo aceptado'); }
catch (ApiError $e) { check((int) $p->query('SELECT equipo_id FROM copias WHERE id=10')->fetchColumn() === 2, 'rollback por conflicto'); }
echo "Combinacion, historial, notas, agente, alias, recepcion futura, conflictos y rollback verificados.\n";

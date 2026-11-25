<?php
declare(strict_types=1);

require __DIR__ . '/../src/Equipment/EquipmentService.php';
require __DIR__ . '/../src/Backup/CopyRepository.php';

// SQLite temporal: adapta solo sintaxis MySQL; se ejecutan los servicios reales.
final class MergeTestPDO extends PDO
{
    private function translate(string $sql): string
    {
        if ($sql === 'SHOW WARNINGS') return 'SELECT NULL WHERE 0';
        $sql = str_replace([' FOR UPDATE', 'INSERT IGNORE INTO', 'NOW()', 'ON DUPLICATE KEY UPDATE id=id', '<=>'],
            ['', 'INSERT OR IGNORE INTO', 'CURRENT_TIMESTAMP', 'ON CONFLICT(huella) DO NOTHING', 'IS'], $sql);
        $sql = str_replace([
            'CONVERT(estado USING utf8mb4) COLLATE utf8mb4_unicode_ci',
            'CONVERT(message_key USING utf8mb4) COLLATE utf8mb4_unicode_ci',
            'CONVERT(huella USING utf8mb4) COLLATE utf8mb4_unicode_ci',
        ], ['estado', 'message_key', 'huella'], $sql);
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
        CREATE TABLE copias(id INTEGER PRIMARY KEY,fecha TEXT,cliente TEXT,equipo TEXT,maquina_virtual TEXT,tarea TEXT,equipo_id INT REFERENCES equipos(id),
          estado TEXT,severidad TEXT DEFAULT 'normal',desc_error TEXT,codigo TEXT,resultado_original TEXT,clasificacion_fuente TEXT,clasificacion_evidencia TEXT,
          software TEXT,destino TEXT,tamano TEXT,duracion TEXT,huella TEXT UNIQUE,tipo TEXT,programa TEXT,revisado INT DEFAULT 0);
        INSERT INTO copias(id,fecha,cliente,equipo,equipo_id,estado,software,huella,revisado) VALUES
          (10,'2026-11-20','Sauber','Alba-PC',2,'ERROR','Acronis','old-copy',1),
          (11,'2026-11-19','Sauber','Ventas',1,'OK','Acronis','target-copy',0);
        CREATE TABLE notas(id INTEGER PRIMARY KEY,copia_id INT REFERENCES copias(id) ON DELETE CASCADE,texto TEXT);
        INSERT INTO notas VALUES(1,10,'Nota tecnica conservada');
        CREATE TABLE correo_copias(id INTEGER PRIMARY KEY,message_key TEXT UNIQUE,equipo_id INT REFERENCES equipos(id),nombre_recibido TEXT,maquina_virtual TEXT,tarea TEXT,fecha TEXT,
          estado TEXT,severidad TEXT DEFAULT 'normal',desc_error TEXT DEFAULT '',codigo TEXT,resultado_original TEXT DEFAULT '',clasificacion_fuente TEXT DEFAULT '',
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
        CREATE TABLE equipo_combinaciones(id INTEGER PRIMARY KEY,destino_id INT,usuario TEXT,detalle TEXT);
        CREATE TABLE panel_cache(cache_key TEXT PRIMARY KEY,value TEXT,expires_at TEXT);
        CREATE TABLE monitor_agents(machine_id TEXT PRIMARY KEY,credential_hash TEXT,active INT,last_used_at TEXT);
        INSERT INTO monitor_agents(machine_id,active) VALUES('machine-alba',1),('machine-otro',1);
        CREATE TABLE monitor_ingest_tokens(id INTEGER PRIMARY KEY,machine_id TEXT,token_hash TEXT,consumed_at TEXT,expires_at TEXT);
        INSERT INTO monitor_ingest_tokens(machine_id,token_hash,expires_at) VALUES('machine-alba','old-token','2099-01-01');");
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
rejected($p, 1, [1], 'destino incluido');
rejected($p, 1, [999], 'equipo inexistente');
$plan = equipos_combinar_plan($p, 1, [2,3]);
check($plan['destino']['nombre'] === 'SAUBER OFIMATICA - PC Ventas', 'destino correcto');
check($plan['origenes'][0]['copias'] === 1, 'vista previa correcta');
equipos_combinar($p, 1, [2,3], $plan['token'], 'tecnico');
check((int) $p->query('SELECT COUNT(*) FROM equipos WHERE id IN (2,3)')->fetchColumn() === 0, 'origenes retirados');
check($p->query('SELECT monitor_machine_id FROM equipos WHERE id=1')->fetchColumn() === 'machine-alba', 'agente trasladado');
check((int) $p->query('SELECT equipo_id FROM copias WHERE id=10')->fetchColumn() === 1, 'historial trasladado');
check($p->query('SELECT equipo FROM copias WHERE id=10')->fetchColumn() === 'Alba-PC', 'nombre original de copia conservado');
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
    'origen'=>'Alba-PC', 'fecha'=>'2026-11-21', 'estado'=>'OK', 'desc_error'=>'', 'codigo'=>null, 'resultado_original'=>'OK',
    'clasificacion_fuente'=>'test', 'clasificacion_evidencia'=>'', 'destino'=>null, 'tamano'=>null, 'duracion'=>null];
ingesta_guardar($p, $d);
check((int) $p->query("SELECT equipo_id FROM copias WHERE huella='next-copy'")->fetchColumn() === 1, 'proxima copia del nombre antiguo vinculada');
check($p->query("SELECT equipo FROM copias WHERE huella='next-copy'")->fetchColumn() === 'Alba-PC', 'nombre recibido conservado al importar');
check((int) $p->query("SELECT COUNT(*) FROM copias WHERE huella='old-copy'")->fetchColumn() === 0, 'un OK posterior cierra los errores anteriores');
check((int) $p->query("SELECT COUNT(*) FROM correo_copias_eliminadas WHERE message_key='old-copy'")->fetchColumn() === 1, 'los errores cerrados no reaparecen');
check((int) $p->query("SELECT COUNT(*) FROM equipos WHERE nombre='Alba-PC'")->fetchColumn() === 0, 'sin recrear origen');
$p = fixture(); $plan = equipos_combinar_plan($p,1,[2]);
$p->exec("UPDATE equipos SET nombre='Nombre cambiado' WHERE id=2");
try { equipos_combinar($p,1,[2],$plan['token'],'tecnico'); throw new RuntimeException('Token obsoleto aceptado'); }
catch (ApiError $e) { check((int) $p->query('SELECT COUNT(*) FROM equipos')->fetchColumn() === 5, 'sin cambios con plan obsoleto'); }
$p = fixture(); $p->exec("INSERT INTO equipo_nombres(software,nombre,equipo_id) VALUES('Acronis','Alba-PC',4)");
$plan = equipos_combinar_plan($p,1,[2]);
try { equipos_combinar($p,1,[2],$plan['token'],'tecnico'); throw new RuntimeException('Alias conflictivo aceptado'); }
catch (ApiError $e) { check((int) $p->query('SELECT equipo_id FROM copias WHERE id=10')->fetchColumn() === 2, 'rollback por conflicto'); }
$p = fixture(); $plan = equipos_combinar_plan($p,1,[2,5]);
check(count($plan['agentes']) === 2, 'vista previa con dos agentes');
foreach ([null, 4] as $invalidAgent) {
    try { equipos_combinar($p,1,[2,5],$plan['token'],'tecnico',$invalidAgent); throw new RuntimeException('Agente no seleccionado aceptado'); }
    catch (ApiError $e) { check((int) $p->query('SELECT COUNT(*) FROM equipos')->fetchColumn() === 5, 'seleccion obligatoria sin cambios'); }
}
$p->exec("UPDATE equipos SET monitor_report='{\"hostname\":\"PC-Actual\"}',rustdesk_password_enc='cifrado-actual' WHERE id=5");
$credential = 'mra_' . str_repeat('a',64);
$q = $p->prepare('UPDATE monitor_agents SET credential_hash=?');
$q->execute([hash('sha256',$credential)]);
$plan = equipos_combinar_plan($p,1,[2,5]);
equipos_combinar($p,1,[2,5],$plan['token'],'tecnico',5);
check($p->query('SELECT monitor_machine_id FROM equipos WHERE id=1')->fetchColumn() === 'machine-otro', 'agente elegido trasladado');
check($p->query('SELECT nombre FROM equipos WHERE id=1')->fetchColumn() === 'PC-Actual', 'nombre principal del agente elegido');
check($p->query('SELECT rustdesk_password_enc FROM equipos WHERE id=1')->fetchColumn() === 'cifrado-actual', 'credencial RustDesk del agente elegido');
check((int) $p->query("SELECT active FROM monitor_agents WHERE machine_id='machine-alba'")->fetchColumn() === 0, 'agente viejo desactivado');
check((int) $p->query("SELECT COUNT(*) FROM monitor_ingest_tokens WHERE machine_id='machine-alba'")->fetchColumn() === 0, 'tokens viejos eliminados');
check(!monitor_agente_autenticar($p,$credential,'machine-alba'), 'credencial vieja rechazada');
check(monitor_agente_autenticar($p,$credential,'machine-otro'), 'credencial elegida conservada');
try {
    monitor_guardar_informe($p,['machineId'=>'machine-alba','hostname'=>'Alba-PC']);
    throw new RuntimeException('El agente retirado pudo volver a guardar datos');
} catch (InvalidArgumentException $e) {
    check((int) $p->query("SELECT COUNT(*) FROM equipos WHERE nombre='Alba-PC'")->fetchColumn() === 0, 'envio en curso no recrea origen');
}
// Incluso un token emitido justo despues de revocar queda inutilizado al consumirlo.
$temporary = 'mtr_' . str_repeat('b',64);
$q = $p->prepare('INSERT INTO monitor_ingest_tokens(machine_id,token_hash,expires_at) VALUES(?,?,?)');
$q->execute(['machine-alba',hash('sha256',$temporary),'2099-01-01']);
check(!monitor_token_consumir($p,$temporary,'machine-alba'), 'token tardio del agente revocado rechazado');
// Tambien se puede conservar el agente que ya estaba en la ficha destino.
$p = fixture(); $plan = equipos_combinar_plan($p,2,[5]);
equipos_combinar($p,2,[5],$plan['token'],'tecnico',2);
check($p->query('SELECT monitor_machine_id FROM equipos WHERE id=2')->fetchColumn() === 'machine-alba', 'agente destino conservado');
check((int) $p->query("SELECT active FROM monitor_agents WHERE machine_id='machine-otro'")->fetchColumn() === 0, 'agente origen retirado');
$p = fixture();
$p->exec("CREATE TRIGGER fallo_combinacion BEFORE DELETE ON equipos WHEN OLD.id=5 BEGIN SELECT RAISE(ABORT,'fallo simulado'); END");
$plan = equipos_combinar_plan($p,1,[2,5]);
try {
    equipos_combinar($p,1,[2,5],$plan['token'],'tecnico',5);
    throw new RuntimeException('Se esperaba un fallo transaccional');
} catch (PDOException $e) {
    check((int) $p->query('SELECT COUNT(*) FROM equipos')->fetchColumn() === 5, 'rollback restaura fichas');
    check((int) $p->query("SELECT active FROM monitor_agents WHERE machine_id='machine-alba'")->fetchColumn() === 1, 'rollback restaura credencial');
    check((int) $p->query('SELECT COUNT(*) FROM monitor_ingest_tokens')->fetchColumn() === 1, 'rollback restaura tokens');
    check((int) $p->query('SELECT COUNT(*) FROM equipo_combinaciones')->fetchColumn() === 0, 'rollback restaura auditoria');
}
$p = fixture();
monitor_actualizar_nombre($p,2,'PC-VENTAS');
ingesta_guardar($p,$d);
check($p->query('SELECT nombre FROM equipos WHERE id=2')->fetchColumn() === 'PC-VENTAS', 'la copia no cambia el nombre del agente');
check((int) $p->query("SELECT equipo_id FROM copias WHERE huella='next-copy'")->fetchColumn() === 2, 'nombre anterior vinculado tras renombrado');
check($p->query("SELECT nombre_recibido FROM correo_copias WHERE message_key='next-copy'")->fetchColumn() === 'Alba-PC', 'aviso conserva el nombre recibido');
foreach (['intentos','revisado_por','revisado_fecha','revision_desc','seg','recordar'] as $column) {
    $p->exec("ALTER TABLE copias ADD COLUMN $column TEXT");
}
$p->sqliteCreateFunction('DATE_FORMAT',static fn($value,$format) => $value);
$q = $p->query(SQL_COPIA . " WHERE huella='next-copy'");
$view = $q->fetch(PDO::FETCH_ASSOC);
check($view['equipo'] === 'PC-VENTAS' && $view['nombre_recibido'] === 'Alba-PC', 'API muestra nombre del agente y referencia original por separado');
echo "Combinacion, agentes, nombres de recepcion, vista principal y rollback verificados.\n";

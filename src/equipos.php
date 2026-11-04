<?php
declare(strict_types=1);

require_once __DIR__ . '/api_utilities.php';

function asegurar_tablas_equipos(PDO $pdo): void
{
    static $hecho = false;
    if ($hecho) return;
    asegurar_tablas_sync($pdo);
    $sql = (string) file_get_contents(__DIR__ . '/../database/equipos.sql');
    $sql = (string) preg_replace('/^--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) $pdo->exec($statement);

    $columns = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='equipos'")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('origen', $columns, true)) $pdo->exec('ALTER TABLE equipos ADD COLUMN origen VARCHAR(200) NULL AFTER software');
    if (!in_array('sugerido', $columns, true)) $pdo->exec('ALTER TABLE equipos ADD COLUMN sugerido VARCHAR(200) NULL AFTER origen');

    $copyColumns = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='copias'")->fetchAll(PDO::FETCH_COLUMN);
    if ($copyColumns && !in_array('huella', $copyColumns, true)) {
        $pdo->exec('ALTER TABLE copias ADD COLUMN huella CHAR(40) NULL, ADD UNIQUE KEY uq_copias_huella (huella)');
    }
    $hecho = true;
}

function equipos_listado(): array
{
    $pdo = db();
    asegurar_tablas_equipos($pdo);
    $rows = $pdo->query("SELECT e.id,e.nombre,e.software,e.origen,e.sugerido,e.cliente_id,c.nombre AS cliente,
        (SELECT COUNT(*) FROM correo_copias cc WHERE cc.equipo_id=e.id AND (cc.importada=0 OR e.cliente_id IS NULL)) AS pendientes
        FROM equipos e LEFT JOIN clientes c ON c.id=e.cliente_id ORDER BY e.nombre,e.software")->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['cliente_id'] = $row['cliente_id'] !== null ? (int) $row['cliente_id'] : null;
        $row['pendientes'] = (int) $row['pendientes'];
    }
    unset($row);
    $mailRun = $pdo->query("SELECT DATE_FORMAT(inicio,'%Y-%m-%d %H:%i') AS fecha,ok,nuevos,ignorados,mensaje,origen
        FROM correo_sync_log ORDER BY id DESC LIMIT 1")->fetch() ?: null;
    if ($mailRun) {
        $mailRun['ok'] = $mailRun['ok'] !== null ? (int)$mailRun['ok'] : null;
        $mailRun['nuevos'] = (int)$mailRun['nuevos'];
        $mailRun['ignorados'] = (int)$mailRun['ignorados'];
    }
    return ['equipos' => $rows, 'correo' => $mailRun];
}

function equipos_asignar_cliente(int $equipoId, int $clienteId): int
{
    $pdo = db();
    asegurar_tablas_equipos($pdo);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id FROM equipos WHERE id=? FOR UPDATE');
        $st->execute([$equipoId]);
        if (!$st->fetchColumn()) throw new ApiError('Equipo no encontrado');
        $st = $pdo->prepare('SELECT id FROM clientes WHERE id=? AND activo=1');
        $st->execute([$clienteId]);
        if (!$st->fetchColumn()) throw new ApiError('Cliente no válido');
        $pdo->prepare('UPDATE equipos SET cliente_id=? WHERE id=?')->execute([$clienteId, $equipoId]);
        $imported = correo_importar_equipo($equipoId, $clienteId, $pdo);
        $pdo->commit();
        return $imported;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function correo_importar_equipo(int $equipoId, ?int $clienteId, ?PDO $pdo = null): int
{
    $pdo ??= db();
    $st = $pdo->prepare('SELECT nombre FROM equipos WHERE id=?');
    $st->execute([$equipoId]);
    $equipment = $st->fetchColumn();
    if (!$equipment) return 0;

    $customer = null;
    if ($clienteId !== null) {
        $st = $pdo->prepare('SELECT nombre FROM clientes WHERE id=? AND activo=1');
        $st->execute([$clienteId]);
        $customer = $st->fetchColumn() ?: null;
    }
    $customer ??= 'Sin asignar';

    $pending = $pdo->prepare('SELECT * FROM correo_copias WHERE equipo_id=? AND importada=0 ORDER BY fecha,id');
    $pending->execute([$equipoId]);
    $insert = $pdo->prepare('INSERT IGNORE INTO copias (fecha,cliente,equipo,equipo_id,estado,desc_error,codigo,software,destino,tamano,duracion,huella)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    $mark = $pdo->prepare('UPDATE correo_copias SET importada=1 WHERE id=?');
    $pdo->prepare('UPDATE copias SET cliente=?,equipo=? WHERE equipo_id=?')->execute([$customer,$equipment,$equipoId]);
    $count = 0;
    foreach ($pending as $copy) {
        $insert->execute([$copy['fecha'],$customer,$equipment,$equipoId,$copy['estado'],$copy['desc_error'],$copy['codigo'],
            $copy['software'],$copy['destino'],$copy['tamano'],$copy['duracion'],$copy['message_key']]);
        $mark->execute([$copy['id']]);
        $count += $insert->rowCount();
    }
    return $count;
}

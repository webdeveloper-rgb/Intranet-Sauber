<?php
declare(strict_types=1);

require_once __DIR__ . '/api_utilities.php';
require_once __DIR__ . '/ingesta.php';

function asegurar_tablas_equipos(PDO $pdo): void
{
    static $hecho = false;
    if ($hecho)
        return;
    asegurar_tablas_sync($pdo);
    $sql = (string) file_get_contents(__DIR__ . '/../database/equipos.sql');
    $sql = (string) preg_replace('/^--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement)
        $pdo->exec($statement);

    $columns = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='equipos'")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('origen', $columns, true))
        $pdo->exec('ALTER TABLE equipos ADD COLUMN origen VARCHAR(200) NULL AFTER software');
    if (!in_array('sugerido', $columns, true))
        $pdo->exec('ALTER TABLE equipos ADD COLUMN sugerido VARCHAR(200) NULL AFTER origen');
    $tipoNuevo = !in_array('tipo', $columns, true);
    if ($tipoNuevo)
        $pdo->exec('ALTER TABLE equipos ADD COLUMN tipo VARCHAR(20) NULL AFTER sugerido, ADD COLUMN programa VARCHAR(50) NULL AFTER tipo');
    elseif (!in_array('programa', $columns, true))
        $pdo->exec('ALTER TABLE equipos ADD COLUMN programa VARCHAR(50) NULL AFTER tipo');
    $vigNuevo = !in_array('vigilar', $columns, true);
    if ($vigNuevo)
        $pdo->exec('ALTER TABLE equipos ADD COLUMN vigilar TINYINT(1) NOT NULL DEFAULT 1');
    $pdo->exec('CREATE TABLE IF NOT EXISTS tareas_estado (nombre VARCHAR(50) NOT NULL PRIMARY KEY, ultima DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $copyColumns = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='copias'")->fetchAll(PDO::FETCH_COLUMN);
    if ($copyColumns && !in_array('huella', $copyColumns, true)) {
        $pdo->exec('ALTER TABLE copias ADD COLUMN huella CHAR(40) NULL, ADD UNIQUE KEY uq_copias_huella (huella)');
    }
    if ($copyColumns && !in_array('tipo', $copyColumns, true)) {
        $pdo->exec('ALTER TABLE copias ADD COLUMN tipo VARCHAR(20) NULL, ADD COLUMN programa VARCHAR(50) NULL');
    } elseif ($copyColumns && !in_array('programa', $copyColumns, true)) {
        $pdo->exec('ALTER TABLE copias ADD COLUMN programa VARCHAR(50) NULL AFTER tipo');
    }
    if ($tipoNuevo)
        equipos_rellenar_tipos($pdo); // equipos que ya existían: se clasifican por su nombre
    // Primera vez: los equipos sin copias en los últimos 30 días no se vigilan (probablemente dados de baja).
    if ($vigNuevo)
        $pdo->exec('UPDATE equipos SET vigilar=0 WHERE id NOT IN (SELECT equipo_id FROM copias WHERE equipo_id IS NOT NULL AND fecha >= DATE_SUB(NOW(), INTERVAL 30 DAY))');
    $hecho = true;
}

/** Clasifica (usuario / servidor / programa) los equipos que ya estaban registrados. Se ejecuta una sola vez. */
function equipos_rellenar_tipos(PDO $pdo): void
{
    $upE = $pdo->prepare('UPDATE equipos SET tipo=?, programa=? WHERE id=?');
    $upC = $pdo->prepare('UPDATE copias SET tipo=?, programa=? WHERE equipo_id=?');
    foreach ($pdo->query('SELECT id,nombre,software FROM equipos')->fetchAll() as $e) {
        $c = ingesta_clasificar_nombre($e['nombre']);
        $tipo = $c['tipo'] ?? ($e['software'] === 'VMware' ? 'servidor' : null);
        if ($tipo === null)
            continue;
        $upE->execute([$tipo, $c['programa'], $e['id']]);
        $upC->execute([$tipo, $c['programa'], $e['id']]);
    }
}

function equipos_listado(): array
{
    $pdo = db();
    asegurar_tablas_equipos($pdo);
    $rows = $pdo->query("SELECT e.id,e.nombre,e.software,e.origen,e.sugerido,e.tipo,e.programa,e.vigilar,e.cliente_id,c.nombre AS cliente,
        (SELECT COUNT(*) FROM correo_copias cc WHERE cc.equipo_id=e.id AND (cc.importada=0 OR e.cliente_id IS NULL)) AS pendientes
        FROM equipos e LEFT JOIN clientes c ON c.id=e.cliente_id ORDER BY e.nombre,e.software")->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['cliente_id'] = $row['cliente_id'] !== null ? (int) $row['cliente_id'] : null;
        $row['pendientes'] = (int) $row['pendientes'];
        $row['vigilar'] = (int) $row['vigilar'];
    }
    unset($row);
    $mailRun = $pdo->query("SELECT DATE_FORMAT(inicio,'%Y-%m-%d %H:%i') AS fecha,ok,nuevos,ignorados,mensaje,origen
        FROM correo_sync_log ORDER BY id DESC LIMIT 1")->fetch() ?: null;
    if ($mailRun) {
        $mailRun['ok'] = $mailRun['ok'] !== null ? (int) $mailRun['ok'] : null;
        $mailRun['nuevos'] = (int) $mailRun['nuevos'];
        $mailRun['ignorados'] = (int) $mailRun['ignorados'];
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
        if (!$st->fetchColumn())
            throw new ApiError('Equipo no encontrado');
        $st = $pdo->prepare('SELECT id FROM clientes WHERE id=? AND activo=1');
        $st->execute([$clienteId]);
        if (!$st->fetchColumn())
            throw new ApiError('Cliente no válido');
        $pdo->prepare('UPDATE equipos SET cliente_id=? WHERE id=?')->execute([$clienteId, $equipoId]);
        $imported = correo_importar_equipo($equipoId, $clienteId, $pdo);
        $pdo->commit();
        return $imported;
    } catch (Throwable $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        throw $e;
    }
}

function equipos_vigilar(int $equipoId, bool $vigilar): void
{
    $pdo = db();
    asegurar_tablas_equipos($pdo);
    $st = $pdo->prepare('SELECT id FROM equipos WHERE id=?');
    $st->execute([$equipoId]);
    if (!$st->fetchColumn())
        throw new ApiError('Equipo no encontrado');
    $pdo->prepare('UPDATE equipos SET vigilar=? WHERE id=?')->execute([$vigilar ? 1 : 0, $equipoId]);
}

function equipos_desasignar_cliente(int $equipoId): int
{
    $pdo = db();
    asegurar_tablas_equipos($pdo);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id FROM equipos WHERE id=? FOR UPDATE');
        $st->execute([$equipoId]);
        if (!$st->fetchColumn())
            throw new ApiError('Equipo no encontrado');

        $pdo->prepare('UPDATE equipos SET cliente_id=NULL WHERE id=?')->execute([$equipoId]);
        $copias = $pdo->prepare("UPDATE copias SET cliente='Sin asignar' WHERE equipo_id=?");
        $copias->execute([$equipoId]);
        $actualizadas = $copias->rowCount();
        $pdo->commit();
        return $actualizadas;
    } catch (Throwable $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        throw $e;
    }
}

function correo_importar_equipo(int $equipoId, ?int $clienteId, ?PDO $pdo = null): int
{
    $pdo ??= db();
    $st = $pdo->prepare('SELECT nombre,tipo,programa FROM equipos WHERE id=?');
    $st->execute([$equipoId]);
    $eqRow = $st->fetch();
    if (!$eqRow)
        return 0;
    $equipment = $eqRow['nombre'];

    $customer = null;
    if ($clienteId !== null) {
        $st = $pdo->prepare('SELECT nombre FROM clientes WHERE id=? AND activo=1');
        $st->execute([$clienteId]);
        $customer = $st->fetchColumn() ?: null;
    }
    $customer ??= 'Sin asignar';

    $pending = $pdo->prepare('SELECT * FROM correo_copias WHERE equipo_id=? AND importada=0 ORDER BY fecha,id');
    $pending->execute([$equipoId]);
    $insert = $pdo->prepare('INSERT IGNORE INTO copias (fecha,cliente,equipo,equipo_id,estado,desc_error,codigo,software,destino,tamano,duracion,huella,tipo,programa)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $mark = $pdo->prepare('UPDATE correo_copias SET importada=1 WHERE id=?');
    $pdo->prepare('UPDATE copias SET cliente=?,equipo=?,tipo=?,programa=? WHERE equipo_id=?')->execute([$customer, $equipment, $eqRow['tipo'], $eqRow['programa'], $equipoId]);
    $count = 0;
    foreach ($pending as $copy) {
        $insert->execute([
            $copy['fecha'],
            $customer,
            $equipment,
            $equipoId,
            $copy['estado'],
            $copy['desc_error'],
            $copy['codigo'],
            $copy['software'],
            $copy['destino'],
            $copy['tamano'],
            $copy['duracion'],
            $copy['message_key'],
            $eqRow['tipo'],
            $eqRow['programa']
        ]);
        $mark->execute([$copy['id']]);
        $count += $insert->rowCount();
    }
    return $count;
}
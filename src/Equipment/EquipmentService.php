<?php
declare(strict_types=1);


/**
 * Persistencia de equipos y asignaciones; importa avisos pendientes al historial al vincular un cliente.
 */
require_once __DIR__ . '/../Integration/ClientApi.php';
require_once __DIR__ . '/../Backup/IngestionService.php';
require_once __DIR__ . '/../Monitoring/MonitorService.php';
require_once __DIR__ . '/MergeService.php';

function asegurar_tablas_equipos(PDO $pdo): void
{
    if (!defined('SCHEMA_MIGRATING')) {
        schema_exigir_migraciones($pdo);
        return;
    }
    static $hecho = false;
    if ($hecho)
        return;
    asegurar_tablas_sync($pdo);
    $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/database/equipos.sql');
    $sql = (string) preg_replace('/^[ \t]*--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        if (function_exists('migracion_ejecutar_sql'))
            migracion_ejecutar_sql($pdo, $statement, 'database/equipos.sql');
        else
            $pdo->exec($statement);
    }

    $columns = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='equipos'")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('origen', $columns, true))
        $pdo->exec('ALTER TABLE equipos ADD COLUMN origen VARCHAR(200) NULL AFTER software');
    if (!in_array('sugerido', $columns, true))
        $pdo->exec('ALTER TABLE equipos ADD COLUMN sugerido VARCHAR(200) NULL AFTER origen');
    $tipoNuevo = !in_array('tipo', $columns, true);
    if ($tipoNuevo) {
        $sqlTipo = 'ALTER TABLE equipos ADD COLUMN tipo VARCHAR(20) NULL AFTER sugerido';
        $sqlPrograma = 'ALTER TABLE equipos ADD COLUMN programa VARCHAR(50) NULL AFTER tipo';
        if (function_exists('migracion_ejecutar_sql')) {
            migracion_ejecutar_sql($pdo, $sqlTipo, 'columna tipo de equipos');
            migracion_ejecutar_sql($pdo, $sqlPrograma, 'columna programa de equipos');
        } else {
            $pdo->exec($sqlTipo);
            $pdo->exec($sqlPrograma);
        }
    } elseif (!in_array('programa', $columns, true)) {
        $sqlPrograma = 'ALTER TABLE equipos ADD COLUMN programa VARCHAR(50) NULL AFTER tipo';
        if (function_exists('migracion_ejecutar_sql'))
            migracion_ejecutar_sql($pdo, $sqlPrograma, 'columna programa de equipos');
        else
            $pdo->exec($sqlPrograma);
    }
    if (!in_array('tipo_manual', $columns, true)) {
        $sqlTipoManual = 'ALTER TABLE equipos ADD COLUMN tipo_manual VARCHAR(20) NULL AFTER sugerido';
        if (function_exists('migracion_ejecutar_sql'))
            migracion_ejecutar_sql($pdo, $sqlTipoManual, 'columna tipo_manual de equipos');
        else
            $pdo->exec($sqlTipoManual);
    }
    $vigNuevo = !in_array('vigilar', $columns, true);
    if ($vigNuevo)
        $pdo->exec('ALTER TABLE equipos ADD COLUMN vigilar TINYINT(1) NOT NULL DEFAULT 1');
    if (!in_array('dias_copia', $columns, true))
        $pdo->exec("ALTER TABLE equipos ADD COLUMN dias_copia CHAR(7) NOT NULL DEFAULT '1111111'");
    if (!in_array('error_reviewed_at', $columns, true))
        $pdo->exec('ALTER TABLE equipos ADD COLUMN error_reviewed_at DATETIME NULL');
    if (!in_array('error_reviewed_by', $columns, true))
        $pdo->exec('ALTER TABLE equipos ADD COLUMN error_reviewed_by VARCHAR(50) NULL');
    if (!in_array('error_review_desc', $columns, true))
        $pdo->exec('ALTER TABLE equipos ADD COLUMN error_review_desc TEXT NULL');
    $pdo->exec("UPDATE equipos SET dias_copia='1111111'
        WHERE dias_copia IS NULL OR dias_copia NOT REGEXP '^[01]{7}$' OR dias_copia NOT LIKE '%1%'");
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
    $rows = $pdo->query("SELECT e.id,e.nombre,e.nombre_manual,e.monitor_alias,e.software,e.origen,e.sugerido,e.tipo,e.tipo_manual,e.programa,e.vigilar,e.dias_copia,e.cliente_id,c.nombre AS cliente,
        e.error_reviewed_at,e.error_reviewed_by,e.error_review_desc,
        ((SELECT COUNT(*) FROM copias review_cp WHERE review_cp.equipo_id=e.id AND review_cp.estado='ERROR'
            AND review_cp.fecha > COALESCE((SELECT MAX(ok.fecha) FROM (
                SELECT fecha FROM copias WHERE equipo_id=e.id AND estado='OK'
                UNION ALL SELECT fecha FROM correo_copias WHERE equipo_id=e.id AND importada=0 AND estado='OK'
            ) ok), '1970-01-01'))
            + (SELECT COUNT(*) FROM correo_copias review_cc WHERE review_cc.equipo_id=e.id AND review_cc.importada=0 AND review_cc.estado='ERROR'
            AND review_cc.fecha > COALESCE((SELECT MAX(ok.fecha) FROM (
                SELECT fecha FROM copias WHERE equipo_id=e.id AND estado='OK'
                UNION ALL SELECT fecha FROM correo_copias WHERE equipo_id=e.id AND importada=0 AND estado='OK'
            ) ok), '1970-01-01'))) AS errores_pendientes,
        DATE_FORMAT(e.creado,'%Y-%m-%d') AS fecha_creacion,
        DATE_FORMAT(ult.ultima_copia,'%Y-%m-%d %H:%i') AS ultima_copia,
        (e.monitor_report IS NOT NULL) AS tiene_monitor,e.monitor_last_seen,
        (EXISTS (SELECT 1 FROM correo_copias cc WHERE cc.equipo_id=e.id)
            OR EXISTS (SELECT 1 FROM copias cp WHERE cp.equipo_id=e.id)) AS tiene_copias,
        (SELECT COUNT(*) FROM correo_copias cc WHERE cc.equipo_id=e.id AND (cc.importada=0 OR e.cliente_id IS NULL)) AS pendientes
        FROM equipos e LEFT JOIN clientes c ON c.id=e.cliente_id
        LEFT JOIN (SELECT equipo_id,MAX(fecha) AS ultima_copia FROM (
            SELECT equipo_id,fecha FROM copias WHERE equipo_id IS NOT NULL
            UNION ALL SELECT equipo_id,fecha FROM correo_copias WHERE importada=0
        ) fechas GROUP BY equipo_id) ult ON ult.equipo_id=e.id
        ORDER BY e.nombre,e.software")->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['cliente_id'] = $row['cliente_id'] !== null ? (int) $row['cliente_id'] : null;
        $row['pendientes'] = (int) $row['pendientes'];
        $row['errores_pendientes'] = (int) $row['errores_pendientes'];
        $row['vigilar'] = (int) $row['vigilar'];
        $row['tiene_monitor'] = (bool) $row['tiene_monitor'];
        $row['tiene_copias'] = (bool) $row['tiene_copias'];
    }
    unset($row);
    $names = $pdo->query('SELECT equipo_id,software,nombre FROM equipo_nombres ORDER BY nombre')->fetchAll();
    $namesByEquipment = [];
    foreach ($names as $name) {
        $namesByEquipment[(int) $name['equipo_id']][] = ['software' => $name['software'], 'nombre' => $name['nombre']];
    }
    foreach ($rows as &$row) {
        $row['nombres_recepcion'] = $namesByEquipment[$row['id']] ?? [];
    }
    unset($row);
    $mailRun = $pdo->query("SELECT DATE_FORMAT(inicio,'%Y-%m-%d %H:%i') AS fecha,ok,nuevos,ignorados,mensaje,origen,
        TIMESTAMPDIFF(MINUTE,inicio,NOW()) AS minutos_desde
        FROM correo_sync_log WHERE origen='n8n' ORDER BY id DESC LIMIT 1")->fetch() ?: null;
    $horasAlerta = max(1, (int) cfg('ingest_alert_hours', 24));
    if ($mailRun) {
        $mailRun['ok'] = $mailRun['ok'] !== null ? (int) $mailRun['ok'] : null;
        $mailRun['nuevos'] = (int) $mailRun['nuevos'];
        $mailRun['ignorados'] = (int) $mailRun['ignorados'];
        $mailRun['alerta'] = (int) $mailRun['minutos_desde'] >= $horasAlerta * 60;
        $mailRun['horas_desde'] = (int) floor((int) $mailRun['minutos_desde'] / 60);
        $mailRun['umbral_horas'] = $horasAlerta;
        unset($mailRun['minutos_desde']);
    } else {
        $mailRun = [
            'fecha' => null,
            'ok' => null,
            'nuevos' => 0,
            'ignorados' => 0,
            'mensaje' => null,
            'origen' => null,
            'alerta' => true,
            'horas_desde' => null,
            'umbral_horas' => $horasAlerta,
        ];
    }
    return ['equipos' => $rows, 'correo' => $mailRun];
}

function equipos_revisar_errores(int $equipoId, string $usuario, string $descripcion): int
{
    $pdo = db();
    asegurar_tablas_equipos($pdo);
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id FROM equipos WHERE id=? FOR UPDATE');
        $lock->execute([$equipoId]);
        if (!$lock->fetchColumn()) {
            throw new ApiError('Equipo no encontrado.');
        }
        $count = $pdo->prepare("SELECT
            (SELECT COUNT(*) FROM copias WHERE equipo_id=? AND estado='ERROR')
            + (SELECT COUNT(*) FROM correo_copias WHERE equipo_id=? AND importada=0 AND estado='ERROR')");
        $count->execute([$equipoId, $equipoId]);
        $errors = (int) $count->fetchColumn();
        if ($errors < 1) {
            throw new ApiError('Este equipo no tiene errores de copia pendientes de revisión.');
        }
        $save = $pdo->prepare('UPDATE equipos SET error_reviewed_at=NOW(),error_reviewed_by=?,error_review_desc=? WHERE id=?');
        $save->execute([$usuario, $descripcion, $equipoId]);
        $pdo->commit();
        return $errors;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function equipos_asignar_cliente(int $equipoId, int $clienteId): int
{
    $pdo = db();
    asegurar_tablas_equipos($pdo);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id,
            (EXISTS (SELECT 1 FROM correo_copias cc WHERE cc.equipo_id=equipos.id)
                OR EXISTS (SELECT 1 FROM copias cp WHERE cp.equipo_id=equipos.id)) AS tiene_copias
            FROM equipos WHERE id=? FOR UPDATE');
        $st->execute([$equipoId]);
        $equipo = $st->fetch();
        if (!$equipo)
            throw new ApiError('Equipo no encontrado');
        $st = $pdo->prepare('SELECT id,tiene_satbk FROM clientes WHERE id=? AND activo=1');
        $st->execute([$clienteId]);
        $cliente = $st->fetch();
        if (!$cliente)
            throw new ApiError('Cliente no válido');
        if ((bool) $equipo['tiene_copias'] && !(bool) $cliente['tiene_satbk'])
            throw new ApiError('Este equipo ya tiene copias y solo se puede asignar a clientes con SATBK.');
        $pdo->prepare('UPDATE equipos SET cliente_id=? WHERE id=?')->execute([$clienteId, $equipoId]);
        $st = $pdo->prepare('SELECT nombre,tipo,programa FROM equipos WHERE id=?');
        $st->execute([$equipoId]);
        $equipo = $st->fetch();
        $st = $pdo->prepare('SELECT nombre FROM clientes WHERE id=?');
        $st->execute([$clienteId]);
        $cliente = (string) $st->fetchColumn();
        $pdo->prepare('UPDATE copias SET cliente=?,equipo=?,tipo=?,programa=? WHERE equipo_id=?')
            ->execute([$cliente, $equipo['nombre'], $equipo['tipo'], $equipo['programa'], $equipoId]);
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

function equipos_guardar_tipo(int $equipoId, $tipo): string
{
    if (!is_string($tipo) || !in_array($tipo, ['auto', 'usuario', 'servidor', 'programa'], true))
        throw new ApiError('Selecciona un tipo de equipo válido.');

    $pdo = db();
    asegurar_tablas_equipos($pdo);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id,nombre,software,programa FROM equipos WHERE id=? FOR UPDATE');
        $st->execute([$equipoId]);
        $equipo = $st->fetch();
        if (!$equipo)
            throw new ApiError('Equipo no encontrado.');

        $clasificacion = ingesta_clasificar_nombre($equipo['nombre']);
        $tipoFinal = $tipo === 'auto' ? ($clasificacion['tipo'] ?? ($equipo['software'] === 'VMware' ? 'servidor' : null)) : $tipo;
        $programa = $tipoFinal === 'programa' ? ($clasificacion['programa'] ?? $equipo['programa']) : null;
        $tipoManual = $tipo === 'auto' ? null : $tipo;
        $pdo->prepare('UPDATE equipos SET tipo_manual=?,tipo=?,programa=? WHERE id=?')
            ->execute([$tipoManual, $tipoFinal, $programa, $equipoId]);
        $pdo->prepare('UPDATE copias SET tipo=?,programa=? WHERE equipo_id=?')
            ->execute([$tipoFinal, $programa, $equipoId]);
        $pdo->commit();
        return $tipoFinal ?? '';
    } catch (Throwable $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        throw $e;
    }
}

function equipos_guardar_dias_copia(int $equipoId, $dias): void
{
    if (!is_string($dias) || preg_match('/\A[01]{7}\z/', $dias) !== 1)
        throw new ApiError('El horario debe contener exactamente siete caracteres binarios.');
    if (strpos($dias, '1') === false)
        throw new ApiError('Selecciona al menos un día de la semana.');

    $pdo = db();
    asegurar_tablas_equipos($pdo);
    $st = $pdo->prepare('UPDATE equipos SET dias_copia=? WHERE id=?');
    $st->execute([$dias, $equipoId]);
    if (!$st->rowCount()) {
        $st = $pdo->prepare('SELECT id FROM equipos WHERE id=?');
        $st->execute([$equipoId]);
        if (!$st->fetchColumn())
            throw new ApiError('Equipo no encontrado');
    }
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

/** Desasigna los equipos cuyo cliente activo no dispone de SATBK. */
function equipos_desasignar_clientes_sin_satbk(): array
{
    $pdo = db();
    asegurar_tablas_equipos($pdo);
    $pdo->beginTransaction();
    try {
        $ids = $pdo->query('SELECT e.id FROM equipos e
            INNER JOIN clientes c ON c.id=e.cliente_id
            WHERE c.tiene_satbk=0 FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
        $ids = array_map('intval', $ids);
        if (!$ids) {
            $pdo->commit();
            return ['equipos' => 0, 'copias' => 0];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $updateEquipos = $pdo->prepare("UPDATE equipos SET cliente_id=NULL WHERE id IN ({$placeholders})");
        $updateEquipos->execute($ids);
        $updateCopias = $pdo->prepare("UPDATE copias SET cliente='Sin asignar' WHERE equipo_id IN ({$placeholders})");
        $updateCopias->execute($ids);
        $pdo->commit();
        return ['equipos' => count($ids), 'copias' => $updateCopias->rowCount()];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
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
    $insert = $pdo->prepare('INSERT INTO copias (fecha,cliente,equipo,maquina_virtual,tarea,equipo_id,estado,severidad,desc_error,codigo,resultado_original,clasificacion_fuente,clasificacion_evidencia,software,destino,tamano,duracion,huella,tipo,programa)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id');
    $mark = $pdo->prepare('UPDATE correo_copias SET importada=1 WHERE id=?');
    $count = 0;
    foreach ($pending as $copy) {
        $insert->execute([
            $copy['fecha'],
            $customer,
            $copy['nombre_recibido'] ?? $equipment,
            $copy['maquina_virtual'] ?? null,
            $copy['tarea'] ?? null,
            $equipoId,
            $copy['estado'],
            $copy['severidad'] ?? 'normal',
            $copy['desc_error'],
            $copy['codigo'],
            $copy['resultado_original'],
            $copy['clasificacion_fuente'],
            $copy['clasificacion_evidencia'],
            $copy['software'],
            $copy['destino'],
            $copy['tamano'],
            $copy['duracion'],
            $copy['message_key'],
            $eqRow['tipo'],
            $eqRow['programa']
        ]);
        if ($pdo->query('SHOW WARNINGS')->fetchColumn()) {
            throw new RuntimeException('La copia contiene datos que la base de datos no pudo guardar completos.');
        }
        $mark->execute([$copy['id']]);
        $count += $insert->rowCount();
    }
    return $count;
}

/** Normaliza el nombre de una tarea quitando verbos de resultado para emparejar OK y ERROR de la misma tarea. */
function correo_normalizar_tarea(?string $tarea): string
{
    $t = mb_strtolower(trim((string) $tarea), 'UTF-8');
    if ($t === '') {
        return '';
    }
    $t = preg_replace('/\b(?:se ha realizado|ha fallado|fall[oó]|ha terminado|completad[oa]|finalizad[oa]|correctamente|satisfactoriamente|failed|completed|succeeded|successfully|finished)\b/iu', ' ', $t);
    return trim((string) preg_replace('/\s+/u', ' ', (string) $t));
}

/** Cierra los errores de copia pendientes tras una copia correcta y evita que reaparezcan. */
function correo_resolver_errores_equipo(int $equipoId, ?PDO $pdo = null, ?string $maquinaVirtual = null, ?string $tarea = null): int
{
    $pdo ??= db();
    $filtroVm = ' AND maquina_virtual <=> ?';
    $paramsVm = [$maquinaVirtual];
    $filtroTarea = ' AND tarea <=> ?';
    $paramsTarea = [$tarea];
    // Una copia correcta solo resuelve errores de la misma VM y tarea.
    // Así un OK antiguo no puede ocultar errores que llegaron después.
    $ultimaCopia = $pdo->prepare("SELECT estado FROM (
        SELECT CONVERT(estado USING utf8mb4) COLLATE utf8mb4_unicode_ci AS estado,
            fecha,id AS orden_id,0 AS origen FROM copias WHERE equipo_id=?{$filtroVm}{$filtroTarea}
        UNION ALL
        SELECT CONVERT(estado USING utf8mb4) COLLATE utf8mb4_unicode_ci AS estado,
            fecha,id AS orden_id,1 AS origen FROM correo_copias WHERE equipo_id=? AND importada=0{$filtroVm}{$filtroTarea}
    ) copias_equipo ORDER BY fecha DESC,origen DESC,orden_id DESC LIMIT 1");
    $ultimaCopia->execute(array_merge([$equipoId], $paramsVm, $paramsTarea, [$equipoId], $paramsVm, $paramsTarea));
    if ($ultimaCopia->fetchColumn() !== 'OK') {
        return 0;
    }

    // Incluye tareas cuyo único cambio entre OK y ERROR sea el verbo de resultado (p. ej. "se ha realizado" vs "falló").
    $tareaNorm = correo_normalizar_tarea($tarea);
    $tareasCoincidentes = [$tarea];
    if ($tareaNorm !== '') {
        $qTareas = $pdo->prepare("SELECT DISTINCT tarea FROM (
            SELECT tarea FROM copias WHERE equipo_id=? AND estado='ERROR'{$filtroVm} AND tarea IS NOT NULL
            UNION ALL
            SELECT tarea FROM correo_copias WHERE equipo_id=? AND estado='ERROR'{$filtroVm} AND tarea IS NOT NULL
        ) t");
        $qTareas->execute(array_merge([$equipoId], $paramsVm, [$equipoId], $paramsVm));
        foreach ($qTareas->fetchAll(PDO::FETCH_COLUMN) as $tExistente) {
            if (correo_normalizar_tarea((string) $tExistente) === $tareaNorm && !in_array($tExistente, $tareasCoincidentes, true)) {
                $tareasCoincidentes[] = $tExistente;
            }
        }
    }
    $condicionTarea = count($tareasCoincidentes) === 1 && $tarea === null
        ? ' AND tarea IS NULL'
        : ' AND tarea IN (' . implode(',', array_fill(0, count($tareasCoincidentes), '?')) . ')';
    $paramsTareas = count($tareasCoincidentes) === 1 && $tarea === null ? [] : $tareasCoincidentes;

    $rememberDeleted = $pdo->prepare("INSERT IGNORE INTO correo_copias_eliminadas (message_key,equipo_id)
        SELECT CONVERT(message_key USING utf8mb4) COLLATE utf8mb4_unicode_ci,equipo_id
        FROM correo_copias WHERE equipo_id=? AND estado='ERROR'{$filtroVm}{$condicionTarea}
        UNION ALL
        SELECT CONVERT(huella USING utf8mb4) COLLATE utf8mb4_unicode_ci,equipo_id
        FROM copias WHERE equipo_id=? AND estado='ERROR' AND huella IS NOT NULL{$filtroVm}{$condicionTarea}");
    $rememberDeleted->execute(array_merge([$equipoId], $paramsVm, $paramsTareas, [$equipoId], $paramsVm, $paramsTareas));

    $deleteHistory = $pdo->prepare("DELETE FROM copias WHERE equipo_id=? AND estado='ERROR'{$filtroVm}{$condicionTarea}");
    $deleteHistory->execute(array_merge([$equipoId], $paramsVm, $paramsTareas));
    $deleted = $deleteHistory->rowCount();

    $pdo->prepare("DELETE FROM correo_copias WHERE equipo_id=? AND estado='ERROR'{$filtroVm}{$condicionTarea}")
        ->execute(array_merge([$equipoId], $paramsVm, $paramsTareas));
    $pendingErrors = $pdo->prepare("SELECT
        (SELECT COUNT(*) FROM copias WHERE equipo_id=? AND estado='ERROR')
        + (SELECT COUNT(*) FROM correo_copias WHERE equipo_id=? AND importada=0 AND estado='ERROR')");
    $pendingErrors->execute([$equipoId, $equipoId]);
    if ((int) $pendingErrors->fetchColumn() === 0) {
        $pdo->prepare('UPDATE equipos SET error_reviewed_at=NULL,error_reviewed_by=NULL,error_review_desc=NULL WHERE id=?')
            ->execute([$equipoId]);
    }
    $cacheKey = hash('sha256', 'copias_sin_recibir:v5');
    $pdo->prepare('DELETE FROM panel_cache WHERE cache_key=?')->execute([$cacheKey]);
    return $deleted;
}

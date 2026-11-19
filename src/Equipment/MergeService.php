<?php
declare(strict_types=1);

/** Prepara una combinacion explicita, nunca una coincidencia aproximada por nombre. */
function equipos_combinar_plan(PDO $pdo, int $destinoId, array $origenIds, bool $lock = false): array
{
    if ($destinoId < 1 || !$origenIds || count($origenIds) > 20) {
        throw new ApiError('Selecciona entre 1 y 20 equipos de origen y un destino válido.');
    }
    foreach ($origenIds as $id) {
        if (filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id < 1 || (int) $id === $destinoId) {
            throw new ApiError('Los equipos de origen deben ser distintos del destino.');
        }
    }
    $origenIds = array_values(array_unique(array_map('intval', $origenIds)));
    sort($origenIds);
    $ids = array_merge([$destinoId], $origenIds);
    sort($ids);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT * FROM equipos WHERE id IN ($in) ORDER BY id" . ($lock ? ' FOR UPDATE' : ''));
    $st->execute($ids);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== count($ids)) {
        throw new ApiError('Algún equipo ya no existe. Actualiza la lista.');
    }
    $byId = array_column($rows, null, 'id');
    $destino = $byId[$destinoId];
    $machines = [];
    foreach ($rows as $row) {
        if (!empty($row['cliente_id']) && (int) $row['cliente_id'] !== (int) $destino['cliente_id']) {
            throw new ApiError('Los orígenes deben pertenecer al cliente del destino o estar sin asignar. Corrige primero su asignación.');
        }
        if (!empty($row['monitor_machine_id'])) {
            $machines[$row['monitor_machine_id']] = $row;
        }
    }
    if (count($machines) > 1) {
        throw new ApiError('Estos equipos tienen agentes con identidades distintas. No se pueden combinar automáticamente sin decidir qué agente conservar.');
    }
    $counts = [];
    foreach (['copias', 'correo_copias', 'monitor_eventos', 'monitor_alertas', 'monitor_backup_activity'] as $table) {
        $q = $pdo->prepare("SELECT equipo_id,COUNT(*) AS total FROM `$table` WHERE equipo_id IN ($in) GROUP BY equipo_id");
        $q->execute($ids);
        $counts[$table] = $q->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    $aliases = $pdo->prepare("SELECT software,nombre,equipo_id FROM equipo_nombres WHERE equipo_id IN ($in) ORDER BY id");
    $aliases->execute($ids);
    $names = $aliases->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $names[] = ['software' => $row['software'], 'nombre' => $row['nombre'], 'equipo_id' => $row['id']];
    }
    // Conserva tambien los nombres y productos guardados en el historial anterior.
    $q = $pdo->prepare("SELECT DISTINCT software,equipo AS nombre,equipo_id FROM copias WHERE equipo_id IN ($in) AND software IS NOT NULL");
    $q->execute($ids);
    $names = array_merge($names, $q->fetchAll(PDO::FETCH_ASSOC));
    $q = $pdo->prepare("SELECT DISTINCT cc.software,e.nombre,cc.equipo_id FROM correo_copias cc
        JOIN equipos e ON e.id=cc.equipo_id WHERE cc.equipo_id IN ($in) AND cc.software IS NOT NULL");
    $q->execute($ids);
    $names = array_merge($names, $q->fetchAll(PDO::FETCH_ASSOC));
    $seen = [];
    foreach ($names as $name) {
        $seen[$name['software'] . "\0" . $name['nombre']] = ['software' => $name['software'], 'nombre' => $name['nombre']];
    }
    $names = array_values($seen);
    $public = [];
    foreach (array_merge([$destinoId], $origenIds) as $id) {
        $r = $byId[$id];
        $public[] = ['id' => (int) $id, 'nombre' => $r['nombre'], 'software' => $r['software'],
            'cliente_id' => $r['cliente_id'] !== null ? (int) $r['cliente_id'] : null,
            'con_agente' => !empty($r['monitor_machine_id']),
            'copias' => (int) ($counts['copias'][$id] ?? 0),
            'pendientes' => (int) ($counts['correo_copias'][$id] ?? 0),
            'eventos' => (int) ($counts['monitor_eventos'][$id] ?? 0)];
    }
    $token = hash('sha256', json_encode([$rows, $counts, $names], JSON_THROW_ON_ERROR));
    return ['destino' => $public[0], 'origenes' => array_slice($public, 1), 'nombres' => $names, 'token' => $token,
        '_rows' => $rows, '_counts' => $counts, '_monitor' => $machines ? reset($machines) : null];
}

function equipos_combinar(PDO $pdo, int $destinoId, array $origenIds, string $token, string $usuario): array
{
    $pdo->beginTransaction();
    try {
        $plan = equipos_combinar_plan($pdo, $destinoId, $origenIds, true);
        if (!hash_equals($plan['token'], $token)) {
            throw new ApiError('Los datos han cambiado. Vuelve a revisar la combinación antes de confirmar.');
        }
        $byId = array_column($plan['_rows'], null, 'id');
        $destino = $byId[$destinoId];
        $origenIds = array_column($plan['origenes'], 'id');
        $ids = array_merge([$destinoId], $origenIds);
        $findName = $pdo->prepare('SELECT id FROM equipos WHERE software=? AND nombre=?');
        $findAlias = $pdo->prepare('SELECT equipo_id FROM equipo_nombres WHERE software=? AND nombre=? FOR UPDATE');
        $insertAlias = $pdo->prepare('INSERT INTO equipo_nombres (software,nombre,equipo_id) VALUES (?,?,?)');
        foreach ($plan['nombres'] as $name) {
            $params = [$name['software'], $name['nombre']];
            $findName->execute($params);
            foreach ($findName->fetchAll(PDO::FETCH_COLUMN) as $otherId) {
                if (!in_array((int) $otherId, $ids, true)) {
                    throw new ApiError('Uno de los nombres pertenece a otro equipo. Inclúyelo en la selección o revisa sus datos.');
                }
            }
            $findAlias->execute($params);
            $owner = $findAlias->fetchColumn();
            if ($owner !== false && !in_array((int) $owner, $ids, true)) {
                throw new ApiError('Uno de los nombres ya está vinculado a otro equipo.');
            }
            if ($owner === false) {
                $insertAlias->execute([$name['software'], $name['nombre'], $destinoId]);
            }
        }
        // Auditoria con las fichas y configuraciones originales, dentro de la misma transaccion.
        $pdo->prepare('INSERT INTO equipo_combinaciones (destino_id,usuario,detalle) VALUES (?,?,?)')
            ->execute([$destinoId, $usuario, json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
        $customer = 'Sin asignar';
        if ($destino['cliente_id'] !== null) {
            $q = $pdo->prepare('SELECT nombre FROM clientes WHERE id=?');
            $q->execute([$destino['cliente_id']]);
            $customer = $q->fetchColumn();
            if ($customer === false) throw new ApiError('El cliente del destino ya no existe.');
        }
        foreach ($origenIds as $sourceId) {
            // Solo puede existir un agente. Si estaba en el origen, se traslada sin perder su identidad.
            if ($plan['_monitor'] && (int) $plan['_monitor']['id'] === $sourceId) {
                $monitor = $plan['_monitor'];
                $pdo->prepare('UPDATE equipos SET monitor_machine_id=NULL WHERE id=?')->execute([$sourceId]);
                $pdo->prepare('UPDATE equipos SET monitor_machine_id=?,monitor_report=?,monitor_last_seen=?,monitor_event_mode=?,rustdesk_password_enc=?,monitor_alias=COALESCE(monitor_alias,?) WHERE id=?')
                    ->execute([$monitor['monitor_machine_id'], $monitor['monitor_report'], $monitor['monitor_last_seen'], $monitor['monitor_event_mode'], $monitor['rustdesk_password_enc'], $monitor['monitor_alias'], $destinoId]);
            }
            $pdo->prepare('UPDATE copias SET equipo_id=?,cliente=?,equipo=? WHERE equipo_id=?')->execute([$destinoId, $customer, $destino['nombre'], $sourceId]);
            foreach (['correo_copias', 'correo_copias_eliminadas', 'monitor_eventos', 'monitor_backup_activity', 'equipo_nombres'] as $table) {
                $pdo->prepare("UPDATE `$table` SET equipo_id=? WHERE equipo_id=?")->execute([$destinoId, $sourceId]);
            }
            $pdo->prepare('UPDATE equipo_combinaciones SET destino_id=? WHERE destino_id=?')->execute([$destinoId, $sourceId]);
            // Las alertas con la misma clave se conservan como una sola, con el estado mas prudente.
            $q = $pdo->prepare('SELECT * FROM monitor_alertas WHERE equipo_id=?');
            $q->execute([$sourceId]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $alert) {
                $existing = $pdo->prepare('SELECT * FROM monitor_alertas WHERE equipo_id=? AND alert_key=?');
                $existing->execute([$destinoId, $alert['alert_key']]);
                $other = $existing->fetch(PDO::FETCH_ASSOC);
                if (!$other) {
                    $pdo->prepare('UPDATE monitor_alertas SET equipo_id=? WHERE id=?')->execute([$destinoId, $alert['id']]);
                } else {
                    $active = (int) $alert['activo'] || (int) $other['activo'];
                    $pdo->prepare('UPDATE monitor_alertas SET activo=?,severidad=?,primera_vez=?,ultima_vez=?,resuelto_en=? WHERE id=?')
                        ->execute([(int) $active, in_array('critical', [$alert['severidad'], $other['severidad']], true) ? 'critical' : 'warning',
                            min($alert['primera_vez'], $other['primera_vez']), max($alert['ultima_vez'], $other['ultima_vez']),
                            $active ? null : max($alert['resuelto_en'], $other['resuelto_en']), $other['id']]);
                    $pdo->prepare('DELETE FROM monitor_alertas WHERE id=?')->execute([$alert['id']]);
                }
            }
            $pdo->prepare('DELETE FROM equipos WHERE id=?')->execute([$sourceId]);
        }
        // Una revision anterior no puede cubrir errores que acaban de trasladarse de otra ficha.
        $pdo->prepare('UPDATE equipos SET error_reviewed_at=NULL,error_reviewed_by=NULL,error_review_desc=NULL WHERE id=?')->execute([$destinoId]);
        correo_importar_equipo($destinoId, $destino['cliente_id'] !== null ? (int) $destino['cliente_id'] : null, $pdo);
        $pdo->commit();
        return ['ok' => true, 'destino_id' => $destinoId, 'combinados' => count($origenIds)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

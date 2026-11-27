<?php
declare(strict_types=1);


/**
 * Ciclo de revision de errores: revisar, reabrir, seguimiento y notas.
 */
final class CopyReviewController
{
    private static function revisionFilter(array $group): array
    {
        $fields = [
            'software' => 80,
            'codigo' => 40,
            'resultado_original' => 24,
            'desc_error' => 500,
            'clasificacion_evidencia' => 600,
        ];
        $where = ['estado=\'REVISION\''];
        $params = [];
        foreach ($fields as $field => $max) {
            $where[] = "COALESCE({$field}, '') = ?";
            $params[] = txt($group[$field] ?? '', $max);
        }
        $where[] = "COALESCE(maquina_virtual, '') = ?";
        $params[] = txt($group['maquina_virtual'] ?? '', 200);
        $where[] = "COALESCE(tarea, '') = ?";
        $params[] = txt($group['tarea'] ?? '', 200);
        return [implode(' AND ', $where), $params];
    }

    private static function classifyRevisionGroup(array $input, array $user): bool
    {
        $group = is_array($input['grupo'] ?? null) ? $input['grupo'] : [];
        $state = strtoupper((string) ($input['estado'] ?? ''));
        if (!in_array($state, ['OK', 'ERROR', 'WARNING', 'RUNNING'], true)) {
            fail('Clasificación no válida');
        }

        [$where, $params] = self::revisionFilter($group);
        $idsQuery = db()->prepare("SELECT id FROM copias WHERE {$where}");
        $idsQuery->execute($params);
        $ids = array_map('intval', $idsQuery->fetchAll(PDO::FETCH_COLUMN));
        if (!$ids) {
            fail('No quedan errores pendientes de clasificación con ese resultado', 404);
        }

        $code = $state === 'OK' ? '' : txt($input['codigo'] ?? ($group['codigo'] ?? ''), 40);
        $description = $state === 'OK' ? '' : txt($input['desc_error'] ?? ($group['desc_error'] ?? ''), 500);
        $severity = $state === 'ERROR' ? strtolower((string) ($input['severidad'] ?? 'normal')) : 'normal';
        if (!in_array($severity, ['leve', 'normal', 'grave', 'critico'], true)) {
            $severity = 'normal';
        }
        if ($state !== 'OK' && $description === '') {
            fail('Indica una descripción para clasificar el error');
        }
        $source = 'manual:' . $user['usuario'];
        $update = db()->prepare("UPDATE copias SET estado=?, severidad=?, codigo=?, desc_error=?,
            clasificacion_fuente=?, revisado=0, revisado_por=NULL, revisado_fecha=NULL,
            revision_desc=NULL, seg=NULL, recordar=NULL WHERE {$where}");
        $update->execute(array_merge([$state, $severity, $code !== '' ? $code : null, $description, $source], $params));
        out([
            'actualizadas' => $ids,
            'clasificacion' => [
                'estado' => $state,
                'severidad' => $severity,
                'codigo' => $code !== '' ? $code : null,
                'desc_error' => $description,
                'clasificacion_fuente' => $source,
            ]
        ]);
    }

    private static function issueFilter(array $copy): array
    {
        $where = ['estado=\'ERROR\'', 'fecha <= ?'];
        $params = [$copy['cursor_fecha']];

        if (!empty($copy['equipo_id'])) {
            $where[] = 'equipo_id = ?';
            $params[] = (int) $copy['equipo_id'];
        } else {
            $where[] = 'cliente = ?';
            $where[] = 'equipo = ?';
            $params[] = $copy['cliente'];
            $params[] = $copy['nombre_recibido'] ?: $copy['equipo'];
        }

        $where[] = 'COALESCE(software, \'\') = ?';
        $where[] = 'COALESCE(codigo, \'\') = ?';
        $where[] = 'COALESCE(desc_error, \'\') = ?';
        $where[] = 'COALESCE(maquina_virtual, \'\') = ?';
        $where[] = 'COALESCE(tarea, \'\') = ?';
        $params[] = (string) ($copy['software'] ?? '');
        $params[] = (string) ($copy['codigo'] ?? '');
        $params[] = (string) ($copy['desc_error'] ?? '');
        $params[] = (string) ($copy['maquina_virtual'] ?? '');
        $params[] = (string) ($copy['tarea'] ?? '');

        return [implode(' AND ', $where), $params];
    }

    private static function closeIssue(array $copy, array $user): array
    {
        [$where, $params] = self::issueFilter($copy);
        $idsQuery = db()->prepare("SELECT id FROM copias WHERE {$where}");
        $idsQuery->execute($params);
        $ids = array_map('intval', $idsQuery->fetchAll(PDO::FETCH_COLUMN));
        if (!$ids) {
            return [];
        }

        $description = (string) ($copy['revision_desc'] ?? '');
        $update = db()->prepare("UPDATE copias SET estado='OK', severidad='normal', revisado=1,
            revisado_por=COALESCE(revisado_por, ?),
            revisado_fecha=COALESCE(revisado_fecha, NOW()),
            revision_desc=COALESCE(revision_desc, ?),
            seg='cerrado', recordar=NULL WHERE {$where}");
        $update->execute(array_merge([$user['usuario'], $description], $params));
        return $ids;
    }

    public static function handle(string $action, array $input, array $user): bool
    {
        if (!in_array($action, ['revisar', 'reabrir', 'seguimiento', 'nota', 'clasificar_revision'], true)) {
            return false;
        }

        if ($action === 'clasificar_revision') {
            return self::classifyRevisionGroup($input, $user);
        }

        $copy = copia_obtener((int) ($input['id'] ?? 0));
        if (!$copy) {
            fail('Copia no encontrada', 404);
        }
        $id = $copy['id'];
        $updatedIds = [];

        switch ($action) {
            case 'revisar':
                if ($copy['estado'] !== 'ERROR') {
                    fail('Solo se pueden revisar copias con error');
                }
                if ($copy['revisado']) {
                    fail('Esta copia ya está revisada');
                }
                $description = txt($input['desc'] ?? '', 2000);
                if (palabras($description) < MIN_PALABRAS) {
                    fail('La descripción debe tener al menos ' . MIN_PALABRAS . ' palabras');
                }
                $update = db()->prepare("UPDATE copias SET revisado=1, revisado_por=?, revisado_fecha=NOW(), revision_desc=?, seg='vigilancia', recordar=NULL WHERE id=? AND estado='ERROR' AND revisado=0");
                $update->execute([$user['usuario'], $description, $id]);
                if ($update->rowCount() !== 1) {
                    fail('La copia ya fue revisada por otro usuario. Actualiza la lista.', 409);
                }
                // Al rellenar la revision, se cierra la incidencia y se marca como OK.
                $updatedIds = self::closeIssue(copia_obtener($id) ?: $copy, $user);
                break;

            case 'reabrir':
                if ($copy['estado'] !== 'ERROR') {
                    fail('Solo se pueden reabrir copias con error');
                }
                db()->prepare("UPDATE copias SET revisado=0, revisado_por=NULL, revisado_fecha=NULL, revision_desc=NULL, seg=NULL, recordar=NULL WHERE id=?")
                    ->execute([$id]);
                break;

            case 'seguimiento':
                if ($copy['estado'] !== 'ERROR' || !$copy['revisado']) {
                    fail('La copia debe estar revisada');
                }
                $followUp = $copy['seg'];
                $reminder = $copy['recordar'];
                if (array_key_exists('seg', $input)) {
                    if (!in_array($input['seg'], ['vigilancia', 'cerrado'], true)) {
                        fail('Seguimiento no válido');
                    }
                    $followUp = $input['seg'];
                }
                if (array_key_exists('recordar', $input)) {
                    $value = $input['recordar'];
                    if ($value === null || $value === '') {
                        $reminder = null;
                    } else {
                        $date = DateTime::createFromFormat('Y-m-d', (string) $value);
                        if (!$date || $date->format('Y-m-d') !== $value) {
                            fail('Fecha no válida');
                        }
                        $reminder = $value;
                    }
                }
                if ($followUp === 'cerrado') {
                    $reminder = null;
                }
                db()->prepare('UPDATE copias SET seg=?, recordar=? WHERE id=?')->execute([$followUp, $reminder, $id]);
                if ($followUp === 'cerrado') {
                    $updatedIds = self::closeIssue(copia_obtener($id) ?: $copy, $user);
                }
                break;

            case 'nota':
                if ($copy['estado'] !== 'ERROR' || !$copy['revisado']) {
                    fail('La copia debe estar revisada');
                }
                $text = txt($input['texto'] ?? '', 2000);
                if ($text === '') {
                    fail('La nota está vacía');
                }
                db()->prepare('INSERT INTO notas (copia_id, usuario, texto) VALUES (?,?,?)')
                    ->execute([$id, $user['usuario'], $text]);
                break;
        }

        out(['copia' => copia_obtener($id), 'actualizadas' => $updatedIds]);
    }
}

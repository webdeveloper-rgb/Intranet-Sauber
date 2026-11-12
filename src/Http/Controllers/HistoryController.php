<?php
declare(strict_types=1);


/**
 * Historial general y por equipo, paginado con cursor fecha/ID.
 */
final class HistoryController
{
    public static function handle(string $action, array $input): bool
    {
        if ($action === 'equipo_historial') {
            $equipoId = filter_var($input['equipo_id'] ?? null, FILTER_VALIDATE_INT);
            if ($equipoId === false || $equipoId < 1) {
                fail('Equipo no válido');
            }
            $exists = db()->prepare('SELECT 1 FROM equipos WHERE id=?');
            $exists->execute([$equipoId]);
            if (!$exists->fetchColumn()) {
                fail('Equipo no encontrado', 404);
            }

            $cursorFecha = $input['cursor_fecha'] ?? null;
            $cursorId = filter_var($input['cursor_id'] ?? null, FILTER_VALIDATE_INT);
            if ($cursorFecha === null) {
                $where = 'equipo_id=?';
                $params = [$equipoId];
            } else {
                $cursorFechaValida = is_string($cursorFecha)
                    ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $cursorFecha)
                    : false;
                if (
                    !$cursorFechaValida
                    || $cursorFechaValida->format('Y-m-d H:i:s') !== $cursorFecha
                    || $cursorId === false
                    || $cursorId < 1
                ) {
                    fail('Cursor de historial no válido');
                }
                $where = 'equipo_id=? AND (fecha < ? OR (fecha = ? AND id < ?))';
                $params = [$equipoId, $cursorFecha, $cursorFecha, $cursorId];
            }

            $st = db()->prepare(SQL_COPIA . " WHERE $where ORDER BY cursor_fecha DESC,id DESC LIMIT 51");
            $st->execute($params);
            $rows = $st->fetchAll();
            $hasMore = count($rows) > 50;
            if ($hasMore) {
                array_pop($rows);
            }
            $rows = array_map('copia_fila', $rows);
            $notas = copia_notas_de(array_column($rows, 'id'));
            foreach ($rows as &$row) {
                $row['notas'] = $notas[$row['id']] ?? [];
            }
            unset($row);
            out(['copias' => $rows, 'hay_mas_historial' => $hasMore]);
        }

        if ($action !== 'history') {
            return false;
        }

        $cursorFecha = $input['cursor_fecha'] ?? null;
        $cursorId = filter_var($input['cursor_id'] ?? null, FILTER_VALIDATE_INT);
        $cursorFechaValida = is_string($cursorFecha)
            ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $cursorFecha)
            : false;
        if ($cursorFecha === null) {
            $where = 'fecha < DATE_SUB(NOW(), INTERVAL 60 DAY)';
            $params = [];
        } elseif (
            !$cursorFechaValida
            || $cursorFechaValida->format('Y-m-d H:i:s') !== $cursorFecha
            || $cursorId === false
            || $cursorId < 1
        ) {
            fail('Cursor de historial no válido');
        } else {
            $where = '(fecha < ? OR (fecha = ? AND id < ?))';
            $params = [$cursorFecha, $cursorFecha, $cursorId];
        }

        $st = db()->prepare(SQL_COPIA . " WHERE $where ORDER BY cursor_fecha DESC,id DESC LIMIT 501");
        $st->execute($params);
        $rows = $st->fetchAll();
        $hasMore = count($rows) > 500;
        if ($hasMore) {
            array_pop($rows);
        }
        $rows = array_map('copia_fila', $rows);
        $notas = copia_notas_de(array_column($rows, 'id'));
        foreach ($rows as &$row) {
            $row['notas'] = $notas[$row['id']] ?? [];
        }
        unset($row);
        out(['copias' => $rows, 'hay_mas_historial' => $hasMore]);
        return true;
    }
}

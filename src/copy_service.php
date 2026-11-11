<?php
declare(strict_types=1);

function copia_fila(array $row): array
{
    foreach (['id', 'revisado'] as $key)
        $row[$key] = (int) $row[$key];
    $row['intentos'] = $row['intentos'] !== null ? (int) $row['intentos'] : null;
    $row['notas'] = [];
    return $row;
}

const SQL_COPIA = "SELECT id, DATE_FORMAT(fecha,'%Y-%m-%d %H:%i') AS fecha, cliente, equipo, estado, desc_error,
    codigo, software, destino, tamano, duracion, tipo, programa, intentos, revisado, revisado_por,
    DATE_FORMAT(fecha,'%Y-%m-%d %H:%i:%s') AS cursor_fecha,
    DATE_FORMAT(revisado_fecha,'%Y-%m-%d %H:%i') AS revisado_fecha, revision_desc, seg,
    DATE_FORMAT(recordar,'%Y-%m-%d') AS recordar FROM copias";

function copia_notas_de(array $ids): array
{
    if (!$ids)
        return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $query = db()->prepare("SELECT copia_id, usuario, texto, DATE_FORMAT(fecha,'%Y-%m-%d %H:%i') AS fecha
        FROM notas WHERE copia_id IN ($in) ORDER BY fecha,id");
    $query->execute($ids);
    $notes = [];
    foreach ($query as $note) {
        $notes[(int) $note['copia_id']][] = ['usuario' => $note['usuario'], 'texto' => $note['texto'], 'fecha' => $note['fecha']];
    }
    return $notes;
}

function copia_obtener(int $id): ?array
{
    $query = db()->prepare(SQL_COPIA . ' WHERE id=?');
    $query->execute([$id]);
    $row = $query->fetch();
    if (!$row)
        return null;
    $row = copia_fila($row);
    $row['notas'] = copia_notas_de([$id])[$id] ?? [];
    return $row;
}

function copias_sin_recibir(PDO $pdo): array
{
    $sql = "SELECT CONCAT('sin-copia-', e.id) AS id,
        DATE_FORMAT(DATE_ADD(DATE_SUB(CURDATE(), INTERVAL d.dias DAY), INTERVAL 1 DAY),'%Y-%m-%d %H:%i') AS fecha,
            DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL d.dias DAY),'%Y-%m-%d') AS fecha_esperada,
            c.nombre AS cliente, e.nombre AS equipo, e.tipo, e.programa, e.software,
            DATE_FORMAT(cp.ultima,'%Y-%m-%d %H:%i') AS ultima_copia,
            'ERROR' AS estado,
            CONCAT('No llegó una copia el día programado ',
                DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL d.dias DAY),'%Y-%m-%d'),
                '. Última copia: ', COALESCE(DATE_FORMAT(cp.ultima,'%Y-%m-%d %H:%i'),'nunca')) AS desc_error,
            'SIN_COPIA' AS codigo, NULL AS destino, NULL AS tamano, NULL AS duracion,
            NULL AS intentos, 0 AS revisado, NULL AS revisado_por, NULL AS revisado_fecha,
            NULL AS revision_desc, NULL AS seg, NULL AS recordar, NULL AS notas
        FROM equipos e
        INNER JOIN clientes c ON c.id=e.cliente_id AND c.activo=1
        LEFT JOIN (SELECT equipo_id, MAX(fecha) AS ultima FROM copias WHERE equipo_id IS NOT NULL GROUP BY equipo_id) cp
            ON cp.equipo_id=e.id
        INNER JOIN (
            SELECT 1 AS dias UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
            UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7
        ) d ON DATE_SUB(CURDATE(), INTERVAL d.dias DAY) > DATE(COALESCE(cp.ultima,e.creado))
            AND SUBSTRING(e.dias_copia,
                MOD(DAYOFWEEK(DATE_SUB(CURDATE(), INTERVAL d.dias DAY)) + 5,7) + 1,1)='1'
        WHERE e.vigilar=1
            AND NOT EXISTS (
                SELECT 1 FROM (
                    SELECT 1 AS dias UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
                    UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7
                ) reciente
                WHERE reciente.dias < d.dias
                    AND DATE_SUB(CURDATE(), INTERVAL reciente.dias DAY) > DATE(COALESCE(cp.ultima,e.creado))
                    AND SUBSTRING(e.dias_copia,
                        MOD(DAYOFWEEK(DATE_SUB(CURDATE(), INTERVAL reciente.dias DAY)) + 5,7) + 1,1)='1'
            )
        ORDER BY e.nombre,e.id";
    return $pdo->query($sql)->fetchAll();
}

function copias_sin_recibir_cache(PDO $pdo): array
{
    $key = hash('sha256', 'copias_sin_recibir:v1');
    $st = $pdo->prepare('SELECT payload FROM panel_cache WHERE cache_key=? AND expires_at>NOW()');
    $st->execute([$key]);
    $payload = $st->fetchColumn();
    if (is_string($payload)) {
        $cached = json_decode($payload, true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $rows = copias_sin_recibir($pdo);
    $payload = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($payload === false) {
        throw new RuntimeException('No se pudo serializar la caché de equipos sin copia.');
    }
    $st = $pdo->prepare('REPLACE INTO panel_cache (cache_key,payload,expires_at) VALUES (?,?,DATE_ADD(NOW(), INTERVAL 60 SECOND))');
    $st->execute([$key, $payload]);
    return $rows;
}
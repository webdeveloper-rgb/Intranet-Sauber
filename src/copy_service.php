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
        DATE_FORMAT(DATE_ADD(COALESCE(
            (SELECT MAX(cp.fecha) FROM copias cp WHERE cp.equipo_id=e.id),
            e.creado
        ), INTERVAL 24 HOUR),'%Y-%m-%d %H:%i') AS fecha,
            c.nombre AS cliente, e.nombre AS equipo, e.tipo, e.programa, e.software,
            'ERROR' AS estado,
            CONCAT('Sin copia recibida en las últimas 24 horas. Última copia: ',
                COALESCE(DATE_FORMAT(
                    (SELECT MAX(cp.fecha) FROM copias cp WHERE cp.equipo_id=e.id),
                    '%Y-%m-%d %H:%i'),'nunca')) AS desc_error,
            'SIN_COPIA' AS codigo, NULL AS destino, NULL AS tamano, NULL AS duracion,
            NULL AS intentos, 0 AS revisado, NULL AS revisado_por, NULL AS revisado_fecha,
            NULL AS revision_desc, NULL AS seg, NULL AS recordar, NULL AS notas
        FROM equipos e
        INNER JOIN clientes c ON c.id=e.cliente_id AND c.activo=1
        WHERE e.vigilar=1
            AND COALESCE(
                (SELECT MAX(cp.fecha) FROM copias cp WHERE cp.equipo_id=e.id),
                e.creado
            ) < DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ORDER BY e.nombre,e.id";
    return $pdo->query($sql)->fetchAll();
}
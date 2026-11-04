<?php
declare(strict_types=1);

function copia_fila(array $row): array
{
    foreach (['id', 'revisado'] as $key) $row[$key] = (int) $row[$key];
    $row['intentos'] = $row['intentos'] !== null ? (int) $row['intentos'] : null;
    $row['notas'] = [];
    return $row;
}

const SQL_COPIA = "SELECT id, DATE_FORMAT(fecha,'%Y-%m-%d %H:%i') AS fecha, cliente, equipo, estado, desc_error,
    codigo, software, destino, tamano, duracion, intentos, revisado, revisado_por,
    DATE_FORMAT(revisado_fecha,'%Y-%m-%d %H:%i') AS revisado_fecha, revision_desc, seg,
    DATE_FORMAT(recordar,'%Y-%m-%d') AS recordar FROM copias";

function copia_notas_de(array $ids): array
{
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $query = db()->prepare("SELECT copia_id, usuario, texto, DATE_FORMAT(fecha,'%Y-%m-%d %H:%i') AS fecha
        FROM notas WHERE copia_id IN ($in) ORDER BY fecha,id");
    $query->execute($ids);
    $notes = [];
    foreach ($query as $note) {
        $notes[(int)$note['copia_id']][] = ['usuario'=>$note['usuario'],'texto'=>$note['texto'],'fecha'=>$note['fecha']];
    }
    return $notes;
}

function copia_obtener(int $id): ?array
{
    $query = db()->prepare(SQL_COPIA . ' WHERE id=?');
    $query->execute([$id]);
    $row = $query->fetch();
    if (!$row) return null;
    $row = copia_fila($row);
    $row['notas'] = copia_notas_de([$id])[$id] ?? [];
    return $row;
}

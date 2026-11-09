<?php
declare(strict_types=1);

/**
 * Recepción de resultados de copia ya interpretados (n8n + ChatGPT).
 * El panel NO se fía del contenido: todo se valida y normaliza aquí.
 */

const INGESTA_SOFTWARE = ['Acronis', 'Iperius', 'VMware', 'Otro'];
const INGESTA_MAX_ITEMS = 100;

function ingesta_texto($v, int $max, bool $obligatorio = false, string $campo = ''): ?string
{
    if ($v === null || $v === '') {
        if ($obligatorio) throw new InvalidArgumentException("Falta '$campo'");
        return null;
    }
    if (!is_scalar($v)) throw new InvalidArgumentException("'$campo' debe ser texto");
    $t = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', mb_scrub((string) $v)));
    if ($t === '') {
        if ($obligatorio) throw new InvalidArgumentException("Falta '$campo'");
        return null;
    }
    return mb_substr($t, 0, $max);
}

/**
 * Adapta el formato del flujo n8n (resultado CORRECTA / FALLO_LEVE / FALLO_GRAVE, cliente, asunto, motivo...)
 * al formato interno. Si el elemento ya trae 'estado', se deja tal cual.
 */
function ingesta_adaptar($it)
{
    if (!is_array($it) || isset($it['estado'])) return $it;

    $res = strtoupper(trim((string) ($it['resultado'] ?? '')));
    $asunto = trim((string) ($it['asunto'] ?? ''));
    $cliente = trim((string) ($it['cliente'] ?? ''));
    if (strcasecmp($cliente, 'SIN_CLIENTE') === 0) $cliente = '';

    // Lo que no se pudo clasificar NO se oculta: entra como ERROR para que alguien lo revise.
    $etiquetas = ['FALLO_LEVE' => 'Fallo leve', 'FALLO_GRAVE' => 'Fallo grave', 'CORRECTA' => null];
    $estado = $res === 'CORRECTA' ? 'OK' : 'ERROR';
    $etiqueta = array_key_exists($res, $etiquetas) ? $etiquetas[$res] : 'Sin clasificar';

    $software = null;
    foreach (INGESTA_SOFTWARE as $s) {
        if (strcasecmp((string) ($it['software'] ?? ''), $s) === 0) $software = $s;
    }
    if ($software === null || $software === 'Otro') {
        if (preg_match('/Acronis/i', $asunto)) $software = 'Acronis';
        elseif (preg_match('/Iperius/i', $asunto)) $software = 'Iperius';
        elseif (preg_match('/VMware|vSphere|ESXi/i', $asunto)) $software = 'VMware';
        else $software = 'Otro';
    }

    $equipo = trim((string) ($it['equipo'] ?? ''));
    $nombre = $equipo !== '' ? $equipo : ($cliente !== '' ? $cliente : $asunto);

    $motivo = trim((string) ($it['motivo'] ?? ''));
    $desc = $estado === 'ERROR' ? $etiqueta . ': ' . ($motivo !== '' ? $motivo : $asunto) : null;

    return [
        'message_id' => $it['message_id'] ?? null,
        'fecha'      => $it['fecha'] ?? null,
        'software'   => $software,
        'nombre'     => $nombre,
        'origen'     => $asunto !== '' ? $asunto : $nombre,
        'sugerido'   => $cliente !== '' ? $cliente : null,
        'estado'     => $estado,
        'desc_error' => $desc,
        'codigo'     => $it['codigo'] ?? null,
        'destino'    => $it['destino'] ?? null,
        'tamano'     => $it['tamano'] ?? null,
        'duracion'   => $it['duracion'] ?? null,
    ];
}

function ingesta_normalizar($it): array
{
    if (!is_array($it)) throw new InvalidArgumentException('Cada elemento debe ser un objeto');

    $mid = ingesta_texto($it['message_id'] ?? null, 500, true, 'message_id');

    $software = null;
    foreach (INGESTA_SOFTWARE as $s) {
        if (strcasecmp((string) ($it['software'] ?? ''), $s) === 0) $software = $s;
    }
    if ($software === null) throw new InvalidArgumentException("'software' debe ser: " . implode(', ', INGESTA_SOFTWARE));

    $estado = strtoupper((string) ($it['estado'] ?? ''));
    if (!in_array($estado, ['OK', 'ERROR'], true)) throw new InvalidArgumentException("'estado' debe ser OK o ERROR");

    $nombre = ingesta_texto($it['nombre'] ?? null, 200, true, 'nombre');

    $ts = strtotime((string) ($it['fecha'] ?? ''));
    if ($ts === false) throw new InvalidArgumentException("'fecha' no válida (usa ISO 8601, p. ej. 2026-11-09T03:15:00+01:00)");
    if ($ts > time() + 86400) throw new InvalidArgumentException("'fecha' está en el futuro");

    $duracion = ingesta_texto($it['duracion'] ?? null, 20, false, 'duracion');
    if ($duracion !== null && !preg_match('/^\d{1,3}:[0-5]\d:[0-5]\d$/', $duracion))
        throw new InvalidArgumentException("'duracion' debe tener formato HH:MM:SS");

    return [
        'message_key' => sha1('n8n|' . strtolower($mid)),
        'software'    => $software,
        'nombre'      => $nombre,
        'origen'      => ingesta_texto($it['origen'] ?? null, 200) ?? $nombre,
        'sugerido'    => ingesta_texto($it['sugerido'] ?? null, 200),
        'estado'      => $estado,
        'fecha'       => date('Y-m-d H:i:s', $ts),
        'desc_error'  => $estado === 'ERROR' ? ingesta_texto($it['desc_error'] ?? null, 2000) : null,
        'codigo'      => ingesta_texto($it['codigo'] ?? null, 50),
        'destino'     => ingesta_texto($it['destino'] ?? null, 500),
        'tamano'      => ingesta_texto($it['tamano'] ?? null, 50),
        'duracion'    => $duracion,
    ];
}

/** Guarda un aviso. Devuelve true si es nuevo, false si ya estaba (mismo message_id). */
function ingesta_guardar(PDO $pdo, array $d): bool
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO equipos (nombre,software,origen,sugerido) VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE origen=VALUES(origen), sugerido=COALESCE(VALUES(sugerido),sugerido)')
            ->execute([$d['nombre'], $d['software'], $d['origen'], $d['sugerido']]);
        $st = $pdo->prepare('SELECT id, cliente_id FROM equipos WHERE software=? AND nombre=?');
        $st->execute([$d['software'], $d['nombre']]);
        $eq = $st->fetch();

        // Si el equipo aún no tiene cliente y el nombre sugerido (el de las llaves del asunto) coincide
        // con UN solo cliente activo, se asigna solo. Si no coincide, queda "Sin asignar" con la sugerencia.
        if ($eq['cliente_id'] === null && $d['sugerido'] !== null) {
            $q = $pdo->prepare('SELECT id FROM clientes WHERE activo=1 AND LOWER(nombre)=LOWER(?)');
            $q->execute([$d['sugerido']]);
            $ids = $q->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids) === 1) {
                $pdo->prepare('UPDATE equipos SET cliente_id=? WHERE id=?')->execute([(int) $ids[0], $eq['id']]);
                $eq['cliente_id'] = (int) $ids[0];
            }
        }

        $ins = $pdo->prepare('INSERT IGNORE INTO correo_copias
            (message_key,equipo_id,fecha,estado,desc_error,codigo,software,destino,tamano,duracion)
            VALUES (?,?,?,?,?,?,?,?,?,?)');
        $ins->execute([$d['message_key'], $eq['id'], $d['fecha'], $d['estado'], $d['desc_error'], $d['codigo'],
            $d['software'], $d['destino'], $d['tamano'], $d['duracion']]);
        $nuevo = $ins->rowCount() > 0;
        if ($nuevo) {
            correo_importar_equipo((int) $eq['id'], $eq['cliente_id'] !== null ? (int) $eq['cliente_id'] : null, $pdo);
        }
        $pdo->commit();
        return $nuevo;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

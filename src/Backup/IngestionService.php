<?php
declare(strict_types=1);

/**
 * Recepción de resultados de copia ya interpretados (n8n + ChatGPT).
 * El panel NO se fía del contenido: todo se valida y normaliza aquí.
 */

const INGESTA_SOFTWARE = ['Acronis', 'Iperius', 'VMware', 'Otro'];
const INGESTA_MAX_ITEMS = 100;
// Programas (software de gestión del cliente) que se copian aparte de los equipos de usuario y los servidores.
const INGESTA_PROGRAMAS = ['EuroAgro', 'EuroPos', 'EuroExtintor', 'EuroMetrics', 'DivaCon', 'DivaGes', 'BDP'];

function ingesta_limpiar_empresa(string $e): ?string
{
    $e = trim(str_replace('_', ' ', $e));
    return $e !== '' ? $e : null;
}

/**
 * Clasifica un nombre de copia con el formato EMPRESA_<algo>:
 *   EMPRESA_PC-NOMBRE        → tipo 'usuario'
 *   EMPRESA_SRV-... / _SERVIDOR / _SERVER → tipo 'servidor'
 *   EMPRESA_EuroAgro...      → tipo 'programa' (EuroAgro, EuroPos, EuroExtintor, EuroMetrics, DivaCon, DivaGes, BDP)
 * Devuelve ['empresa','tipo','programa']; todo null si el nombre no sigue ese formato.
 */
function ingesta_clasificar_nombre(?string $nombre): array
{
    $r = ['empresa' => null, 'tipo' => null, 'programa' => null];
    $n = trim((string) $nombre);
    if ($n === '')
        return $r;

    // Hostnames Windows comunes sin prefijo de cliente (p. ej. DESKTOP-ABC123).
    if (preg_match('/^(?:DESKTOP|LAPTOP|PC|WIN)-[A-Z0-9][A-Z0-9-]*$/iu', $n)) {
        return ['empresa' => null, 'tipo' => 'usuario', 'programa' => null];
    }
    // Hosts ESXi que no siguen el formato EMPRESA_SRV-... .
    if (preg_match('/^ESX(?:I)?[-_][A-Z0-9][A-Z0-9._-]*$/iu', $n)) {
        return ['empresa' => null, 'tipo' => 'servidor', 'programa' => null];
    }

    if (preg_match('/^(.+?)_PC-.+$/iu', $n, $m)) {
        return ['empresa' => ingesta_limpiar_empresa($m[1]), 'tipo' => 'usuario', 'programa' => null];
    }
    if (preg_match('/^(.+?)_(?:SRV|SERVIDOR|SERVER)(?:[-_ ].*)?$/iu', $n, $m)) {
        return ['empresa' => ingesta_limpiar_empresa($m[1]), 'tipo' => 'servidor', 'programa' => null];
    }
    $progs = implode('|', array_map('preg_quote', INGESTA_PROGRAMAS));
    if (preg_match('/^(.+?)_(' . $progs . ')(?:[-_ ].*)?$/iu', $n, $m)) {
        $canon = null;
        foreach (INGESTA_PROGRAMAS as $p)
            if (strcasecmp($p, $m[2]) === 0)
                $canon = $p;
        return ['empresa' => ingesta_limpiar_empresa($m[1]), 'tipo' => 'programa', 'programa' => $canon];
    }
    return $r;
}

function ingesta_texto($v, int $max, bool $obligatorio = false, string $campo = ''): ?string
{
    if ($v === null || $v === '') {
        if ($obligatorio)
            throw new InvalidArgumentException("Falta '$campo'");
        return null;
    }
    if (!is_scalar($v))
        throw new InvalidArgumentException("'$campo' debe ser texto");
    $t = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', mb_scrub((string) $v)));
    if ($t === '') {
        if ($obligatorio)
            throw new InvalidArgumentException("Falta '$campo'");
        return null;
    }
    return mb_substr($t, 0, $max);
}

/**
 * Saca software, nombre de equipo y cliente sugerido del ASUNTO, con las mismas reglas que el antiguo lector IMAP.
 * Así el nombre del equipo coincide con los que ya estaban asignados a un cliente en el panel.
 */
function ingesta_parsear_asunto(string $asunto): array
{
    $r = ['software' => null, 'nombre' => null, 'sugerido' => null];
    if (preg_match('/Notificaci[oó]n de Acronis True Image de\s+(.+)$/iu', $asunto, $m)) {
        $origen = trim($m[1]);
        $r['software'] = 'Acronis';
        $r['nombre'] = $origen;
        if (($emp = ingesta_clasificar_nombre($origen)['empresa']) !== null) {
            $r['sugerido'] = $emp;
        } elseif (preg_match('/^(.+?)\s+-\s+\S/u', $origen, $c)) {
            // "HORNO JOAQUIN - PC JOAQUIN": lo anterior al primer " - " es el cliente probable
            $r['sugerido'] = trim($c[1]);
        } elseif (preg_match('/^(.*?)[_\s-]*((?:DESKTOP|LAPTOP|PC|WIN)-[A-Z0-9]+)$/iu', $origen, $d)) {
            $prefijo = trim(str_replace('_', ' ', $d[1]));
            $r['sugerido'] = $prefijo !== '' ? $prefijo : null;
        }
    } elseif (preg_match('/^(Copia de seguridad .+?) - (.+) - Iperius Backup$/iu', $asunto, $m)) {
        $r['software'] = 'Iperius';
        $r['nombre'] = trim($m[2]);
        $r['sugerido'] = ingesta_clasificar_nombre($r['nombre'])['empresa'];
    } elseif (preg_match('/VMware|vSphere|ESXi/i', $asunto)) {
        $r['software'] = 'VMware';
    }
    return $r;
}

/**
 * Adapta el formato del flujo n8n (resultado CORRECTA / FALLO_LEVE / FALLO_GRAVE, cliente, asunto, motivo...)
 * al formato interno. Si el elemento ya trae 'estado', se deja tal cual.
 */
function ingesta_adaptar($it)
{
    if (!is_array($it) || isset($it['estado']))
        return $it;

    $res = strtoupper(trim((string) ($it['resultado'] ?? '')));
    $asunto = trim((string) preg_replace('/\s+/u', ' ', (string) ($it['asunto'] ?? '')));
    $cliente = trim((string) ($it['cliente'] ?? ''));
    if (strcasecmp($cliente, 'SIN_CLIENTE') === 0)
        $cliente = '';

    // Lo que no se pudo clasificar NO se oculta: entra como ERROR para que alguien lo revise.
    $etiquetas = ['FALLO_LEVE' => 'Fallo leve', 'FALLO_GRAVE' => 'Fallo grave', 'CORRECTA' => null];
    $estado = $res === 'CORRECTA' ? 'OK' : 'ERROR';
    $etiqueta = array_key_exists($res, $etiquetas) ? $etiquetas[$res] : 'Sin clasificar';

    $p = ingesta_parsear_asunto($asunto);

    // Software: primero el asunto (fiable), luego lo que diga la IA.
    $software = $p['software'];
    if ($software === null) {
        foreach (INGESTA_SOFTWARE as $sw) {
            if (strcasecmp((string) ($it['software'] ?? ''), $sw) === 0)
                $software = $sw;
        }
    }
    $software = $software ?? 'Otro';

    // Nombre del equipo: el del asunto; si no se reconoce, el que diga la IA, luego el cliente, luego el asunto.
    $equipo = trim((string) ($it['equipo'] ?? ''));
    $nombre = $p['nombre'] ?? ($equipo !== '' ? $equipo : ($cliente !== '' ? $cliente : $asunto));

    $motivo = trim((string) ($it['motivo'] ?? ''));
    $desc = $estado === 'ERROR' ? $etiqueta . ': ' . ($motivo !== '' ? $motivo : $asunto) : null;

    return [
        'message_id' => $it['message_id'] ?? null,
        'fecha' => $it['fecha'] ?? null,
        'software' => $software,
        'nombre' => $nombre,
        'origen' => $asunto !== '' ? $asunto : $nombre,
        'sugerido' => $cliente !== '' ? $cliente : (ingesta_clasificar_nombre($nombre)['empresa'] ?? $p['sugerido']),
        'estado' => $estado,
        'desc_error' => $desc,
        'tipo_equipo' => $it['tipo_equipo'] ?? null,
        'codigo' => $it['codigo'] ?? null,
        'destino' => $it['destino'] ?? null,
        'tamano' => $it['tamano'] ?? null,
        'duracion' => $it['duracion'] ?? null,
    ];
}

function ingesta_normalizar($it): array
{
    if (!is_array($it))
        throw new InvalidArgumentException('Cada elemento debe ser un objeto');

    $mid = ingesta_texto($it['message_id'] ?? null, 500, true, 'message_id');

    $software = null;
    foreach (INGESTA_SOFTWARE as $s) {
        if (strcasecmp((string) ($it['software'] ?? ''), $s) === 0)
            $software = $s;
    }
    if ($software === null)
        throw new InvalidArgumentException("'software' debe ser: " . implode(', ', INGESTA_SOFTWARE));

    $estado = strtoupper((string) ($it['estado'] ?? ''));
    if (!in_array($estado, ['OK', 'ERROR'], true))
        throw new InvalidArgumentException("'estado' debe ser OK o ERROR");

    $nombre = ingesta_texto($it['nombre'] ?? null, 200, true, 'nombre');

    $ts = strtotime((string) ($it['fecha'] ?? ''));
    if ($ts === false)
        throw new InvalidArgumentException("'fecha' no válida (usa ISO 8601, p. ej. 2026-11-09T03:15:00+01:00)");
    if ($ts > time() + 86400)
        throw new InvalidArgumentException("'fecha' está en el futuro");

    $duracion = ingesta_texto($it['duracion'] ?? null, 20, false, 'duracion');
    if ($duracion !== null && !preg_match('/^\d{1,3}:[0-5]\d:[0-5]\d$/', $duracion))
        throw new InvalidArgumentException("'duracion' debe tener formato HH:MM:SS");

    $cl = ingesta_clasificar_nombre($nombre);
    // Tipo: primero el formato del nombre; si no lo sigue, lo que haya dicho la IA (SERVIDOR / EQUIPO); VMware = servidor.
    $ia = strtoupper(trim((string) ($it['tipo_equipo'] ?? '')));
    $tipoIa = $ia === 'SERVIDOR' ? 'servidor' : ($ia === 'EQUIPO' ? 'usuario' : null);
    $tipo = $cl['tipo'] ?? $tipoIa ?? ($software === 'VMware' ? 'servidor' : null);

    return [
        'message_key' => sha1('n8n|' . strtolower($mid)),
        'software' => $software,
        'nombre' => $nombre,
        'tipo' => $tipo,
        'programa' => $cl['programa'],
        'origen' => ingesta_texto($it['origen'] ?? null, 200) ?? $nombre,
        'sugerido' => ingesta_texto($it['sugerido'] ?? null, 200) ?? $cl['empresa'],
        'estado' => $estado,
        'fecha' => date('Y-m-d H:i:s', $ts),
        'desc_error' => $estado === 'ERROR' ? ingesta_texto($it['desc_error'] ?? null, 2000) : null,
        'codigo' => ingesta_texto($it['codigo'] ?? null, 50),
        'destino' => ingesta_texto($it['destino'] ?? null, 500),
        'tamano' => ingesta_texto($it['tamano'] ?? null, 50),
        'duracion' => $duracion,
    ];
}

/** Guarda un aviso. Devuelve ['nuevo','equipo','software','asignado','cliente']. */
function ingesta_guardar(PDO $pdo, array $d): array
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO equipos (nombre,software,origen,sugerido,tipo,programa) VALUES (?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE origen=VALUES(origen), sugerido=COALESCE(VALUES(sugerido),sugerido),
                tipo=COALESCE(VALUES(tipo),tipo), programa=COALESCE(VALUES(programa),programa)')
            ->execute([$d['nombre'], $d['software'], $d['origen'], $d['sugerido'], $d['tipo'], $d['programa']]);
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
        $ins->execute([
            $d['message_key'],
            $eq['id'],
            $d['fecha'],
            $d['estado'],
            $d['desc_error'],
            $d['codigo'],
            $d['software'],
            $d['destino'],
            $d['tamano'],
            $d['duracion']
        ]);
        $nuevo = $ins->rowCount() > 0;
        if ($nuevo) {
            correo_importar_equipo((int) $eq['id'], $eq['cliente_id'] !== null ? (int) $eq['cliente_id'] : null, $pdo);
        }
        $clienteNombre = null;
        if ($eq['cliente_id'] !== null) {
            $q = $pdo->prepare('SELECT nombre FROM clientes WHERE id=?');
            $q->execute([(int) $eq['cliente_id']]);
            $clienteNombre = $q->fetchColumn() ?: null;
        }
        $pdo->commit();
        return [
            'nuevo' => $nuevo,
            'equipo' => $d['nombre'],
            'software' => $d['software'],
            'asignado' => $eq['cliente_id'] !== null,
            'cliente' => $clienteNombre
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        throw $e;
    }
}

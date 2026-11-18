<?php
declare(strict_types=1);

/**
 * Clasifica, adapta, valida y guarda atomicamente los avisos interpretados por n8n.
 */
/**
 * Recepción de resultados de copia ya interpretados (n8n + ChatGPT).
 * El panel NO se fía del contenido: todo se valida y normaliza aquí.
 */

const INGESTA_SOFTWARE = ['Acronis', 'Iperius', 'MicroFocus', 'Veeam', 'VMware', 'Otro'];
const INGESTA_MAX_ITEMS = 100;
// Programas (software de gestión del cliente) que se copian aparte de los equipos de usuario y los servidores.
const INGESTA_PROGRAMAS = ['EuroAgro', 'EuroPos', 'EuroExtintor', 'EuroMetrics', 'DivaCon', 'DivaGes', 'BDP'];

/** Normaliza el nombre de cliente extraido de un nombre de equipo. */
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

    // Hostnames comunes sin prefijo de cliente.
    if (preg_match('/^(?:DESKTOP|LAPTOP|PC|WIN|NOTEBOOK|PORTATIL)-[A-Z0-9][A-Z0-9-]*$/iu', $n)) {
        return ['empresa' => null, 'tipo' => 'usuario', 'programa' => null];
    }
    // Servidores con hostname directo, sin el formato EMPRESA_SRV-... .
    if (preg_match('/^(?:SRV|SERVER|SERVIDOR|DC|SQL|HV|ESX|ESXI|NAS|BCK|BACKUP|RDS|APP|FS|TS)(?:[-_ ]|\d)[A-Z0-9._ -]*$/iu', $n)) {
        return ['empresa' => null, 'tipo' => 'servidor', 'programa' => null];
    }

    if (preg_match('/^(.+?)_PC-.+$/iu', $n, $m)) {
        return ['empresa' => ingesta_limpiar_empresa($m[1]), 'tipo' => 'usuario', 'programa' => null];
    }
    if (preg_match('/^(.+?)_(?:SRV|SERVIDOR|SERVER|DC|SQL|HV|ESX|ESXI|NAS|BCK|BACKUP|RDS|APP|FS|TS)(?:[-_ ].*)?$/iu', $n, $m)) {
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

/** Limpia un valor recibido por API, comprueba que sea escalar y limita su longitud. */
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
    // El asunto suele ser mas estable que la IA para identificar software y nombre del equipo.
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
        $r['software'] = 'Iperius';
        $nombre = trim($m[2]);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $nombre)) {
            $r['nombre'] = $nombre;
            $r['sugerido'] = ingesta_clasificar_nombre($nombre)['empresa'];
        }
    } elseif (preg_match('/Micro\s*Focus/i', $asunto)) {
        $r['software'] = 'MicroFocus';
    } elseif (preg_match('/Veeam/i', $asunto)) {
        $r['software'] = 'Veeam';
    } elseif (preg_match('/VMware|vSphere|ESXi/i', $asunto)) {
        $r['software'] = 'VMware';
    }
    return $r;
}

/**
 * Adapta el formato del flujo n8n (resultado CORRECTA / FALLO_LEVE / FALLO_GRAVE / REVISION, ...)
 * al formato interno. Si el elemento ya trae 'estado', se deja tal cual.
 */
function ingesta_adaptar($it)
{
    // Si ya tiene el formato interno, no hace falta convertirlo desde el formato de n8n.
    if (!is_array($it) || isset($it['estado']))
        return $it;

    $res = strtoupper(trim((string) ($it['resultado'] ?? '')));
    $asunto = trim((string) preg_replace('/\s+/u', ' ', (string) ($it['asunto'] ?? '')));
    $cliente = trim((string) ($it['cliente'] ?? ''));
    if (strcasecmp($cliente, 'SIN_CLIENTE') === 0)
        $cliente = '';

    // Solo un fallo confirmado es ERROR. Resultados leves, contradictorios o desconocidos
    // se conservan como REVISION para no generar falsos fallos ni perder evidencia.
    $etiquetas = [
        'FALLO_LEVE' => 'Advertencia',
        'EN_CURSO' => 'Operacion en curso',
        'FALLO_GRAVE' => 'Fallo grave',
        'CORRECTA' => null,
        'REVISION' => 'Revisión necesaria',
    ];
    $estado = match ($res) {
        'CORRECTA' => 'OK',
        'FALLO_GRAVE' => 'ERROR',
        'FALLO_LEVE' => 'WARNING',
        'EN_CURSO' => 'RUNNING',
        default => 'REVISION',
    };
    $etiqueta = $etiquetas[$res] ?? 'Resultado sin clasificar';

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
    $desc = $estado !== 'OK' ? $etiqueta . ': ' . ($motivo !== '' ? $motivo : $asunto) : null;

    return [
        'message_id' => $it['message_id'] ?? null,
        'fecha' => $it['fecha'] ?? null,
        'software' => $software,
        'nombre' => $nombre,
        'origen' => $asunto !== '' ? $asunto : $nombre,
        'sugerido' => $cliente !== '' ? $cliente : (ingesta_clasificar_nombre($nombre)['empresa'] ?? $p['sugerido']),
        'estado' => $estado,
        'desc_error' => $desc,
        'clasificacion_motivo' => $motivo,
        'clasificacion_evidencia' => (string) ($it['evidencia'] ?? ''),
        'resultado_original' => $res,
        'clasificacion_fuente' => ingesta_texto($it['clasificacion_fuente'] ?? null, 40) ?? 'n8n_ai',
        'tipo_equipo' => $it['tipo_equipo'] ?? null,
        'codigo' => $it['codigo'] ?? null,
        'destino' => $it['destino'] ?? null,
        'tamano' => $it['tamano'] ?? null,
        'duracion' => $it['duracion'] ?? null,
    ];
}

/** Valida el elemento y devuelve el formato exacto que espera la base de datos. */
function ingesta_normalizar($it): array
{
    if (!is_array($it))
        throw new InvalidArgumentException('Cada elemento debe ser un objeto');

    // Se usa para deduplicar notificaciones aunque el flujo las reenvie.
    $mid = ingesta_texto($it['message_id'] ?? null, 500, true, 'message_id');

    $software = null;
    foreach (INGESTA_SOFTWARE as $s) {
        if (strcasecmp((string) ($it['software'] ?? ''), $s) === 0)
            $software = $s;
    }
    if ($software === null)
        throw new InvalidArgumentException("'software' debe ser: " . implode(', ', INGESTA_SOFTWARE));

    $estado = strtoupper((string) ($it['estado'] ?? ''));
    if (!in_array($estado, ['OK', 'ERROR', 'WARNING', 'RUNNING', 'REVISION'], true))
        throw new InvalidArgumentException("'estado' debe ser OK, ERROR, WARNING, RUNNING o REVISION");

    $nombre = ingesta_texto($it['nombre'] ?? null, 200, true, 'nombre');

    // Convierte la fecha y evita aceptar notificaciones con fecha futura no razonable.
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
    $resultadoOriginal = strtoupper((string) ($it['resultado_original'] ?? $it['resultado'] ?? $estado));
    if (!preg_match('/^[A-Z_]{1,24}$/D', $resultadoOriginal)) {
        $resultadoOriginal = $estado;
    }
    $fuenteClasificacion = ingesta_texto($it['clasificacion_fuente'] ?? null, 40) ?? 'api_directa';
    $motivoClasificacion = ingesta_texto($it['clasificacion_motivo'] ?? null, 500)
        ?? ($estado !== 'OK' ? ingesta_texto($it['desc_error'] ?? null, 500) : null);

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
        // Mantener estos límites alineados con correo_copias y copias en database/.
        'desc_error' => $estado !== 'OK' ? ingesta_texto($it['desc_error'] ?? null, 500) : null,
        'codigo' => ingesta_texto($it['codigo'] ?? null, 40),
        'resultado_original' => $resultadoOriginal,
        'clasificacion_fuente' => $fuenteClasificacion,
        'clasificacion_evidencia' => ingesta_texto($it['clasificacion_evidencia'] ?? $it['evidencia'] ?? null, 600) ?? '',
        'clasificacion_motivo' => $motivoClasificacion,
        'destino' => ingesta_texto($it['destino'] ?? null, 120),
        'tamano' => ingesta_texto($it['tamano'] ?? null, 40),
        'duracion' => $duracion,
    ];
}

/** Guarda un aviso. Devuelve ['nuevo','equipo','software','asignado','cliente']. */
function ingesta_guardar(PDO $pdo, array $d): array
{
    // Equipo, asignacion y copia se guardan en una transaccion para evitar datos parciales.
    $pdo->beginTransaction();
    try {
        $deletedMessage = $pdo->prepare('SELECT equipo_id FROM correo_copias_eliminadas WHERE message_key=? FOR UPDATE');
        $deletedMessage->execute([$d['message_key']]);
        $deletedEquipmentId = $deletedMessage->fetchColumn();
        if ($deletedEquipmentId !== false && $deletedEquipmentId !== null) {
            $assignment = $pdo->prepare('SELECT e.cliente_id,c.nombre AS cliente FROM equipos e LEFT JOIN clientes c ON c.id=e.cliente_id WHERE e.id=?');
            $assignment->execute([(int) $deletedEquipmentId]);
            $existing = $assignment->fetch() ?: [];
            $pdo->commit();
            return [
                'nuevo' => false,
                'equipo' => $d['nombre'],
                'software' => $d['software'],
                'asignado' => !empty($existing['cliente_id']),
                'cliente' => $existing['cliente'] ?? null,
                'errores_eliminados' => 0,
            ];
        }

        $st = $pdo->prepare('SELECT id,cliente_id FROM equipos
            WHERE nombre=? AND monitor_machine_id IS NOT NULL LIMIT 2 FOR UPDATE');
        $st->execute([$d['nombre']]);
        $monitorMatches = $st->fetchAll();
        if (count($monitorMatches) === 1) {
            $eq = $monitorMatches[0];
            $pdo->prepare('UPDATE equipos SET sugerido=COALESCE(?,sugerido),
                    tipo=COALESCE(tipo_manual,COALESCE(?,tipo)),programa=COALESCE(?,programa) WHERE id=?')
                ->execute([$d['sugerido'], $d['tipo'], $d['programa'], $eq['id']]);
        } else {
            // Una copia posterior se asocia a la ficha ya creada por Monitor-Equipos.
            $pdo->prepare('INSERT INTO equipos (nombre,software,origen,sugerido,tipo,programa) VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE origen=VALUES(origen), sugerido=COALESCE(VALUES(sugerido),sugerido),
                    tipo=COALESCE(tipo_manual,COALESCE(VALUES(tipo),tipo)), programa=COALESCE(VALUES(programa),programa)')
                ->execute([$d['nombre'], $d['software'], $d['origen'], $d['sugerido'], $d['tipo'], $d['programa']]);
            $st = $pdo->prepare('SELECT id, cliente_id FROM equipos WHERE software=? AND nombre=?');
            $st->execute([$d['software'], $d['nombre']]);
            $eq = $st->fetch();
        }
        if (!$eq) {
            throw new RuntimeException('No se pudo recuperar el equipo después de insertarlo.');
        }

        // Si el equipo aún no tiene cliente y el nombre sugerido (el de las llaves del asunto) coincide
        // con UN solo cliente activo, se asigna solo. Si no coincide, queda "Sin asignar" con la sugerencia.
        if ($eq['cliente_id'] === null && $d['sugerido'] !== null) {
            $q = $pdo->prepare('SELECT id FROM clientes WHERE activo=1 AND tiene_satbk=1 AND LOWER(nombre)=LOWER(?)');
            $q->execute([$d['sugerido']]);
            $ids = $q->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids) === 1) {
                $pdo->prepare('UPDATE equipos SET cliente_id=? WHERE id=?')->execute([(int) $ids[0], $eq['id']]);
                $eq['cliente_id'] = (int) $ids[0];
            }
        }

        // Los reenvios de una copia existente se ignoran y se informan como duplicados.
        $ins = $pdo->prepare('INSERT IGNORE INTO correo_copias
            (message_key,equipo_id,fecha,estado,desc_error,codigo,resultado_original,clasificacion_fuente,
                clasificacion_evidencia,software,destino,tamano,duracion)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $ins->execute([
            $d['message_key'],
            $eq['id'],
            $d['fecha'],
            $d['estado'],
            $d['desc_error'],
            $d['codigo'],
            $d['resultado_original'],
            $d['clasificacion_fuente'],
            $d['clasificacion_evidencia'],
            $d['software'],
            $d['destino'],
            $d['tamano'],
            $d['duracion']
        ]);
        $nuevo = $ins->rowCount() > 0;
        $erroresEliminados = 0;
        if ($nuevo) {
            correo_importar_equipo((int) $eq['id'], $eq['cliente_id'] !== null ? (int) $eq['cliente_id'] : null, $pdo);
            if ($d['estado'] === 'OK') {
                $review = $pdo->prepare('SELECT error_reviewed_at FROM equipos WHERE id=? FOR UPDATE');
                $review->execute([(int) $eq['id']]);
                $reviewedAt = $review->fetchColumn();
                if ($reviewedAt !== false && $reviewedAt !== null) {
                    $rememberDeleted = $pdo->prepare("INSERT IGNORE INTO correo_copias_eliminadas (message_key,equipo_id)
                        SELECT message_key,equipo_id FROM correo_copias WHERE equipo_id=? AND estado='ERROR'
                        UNION
                        SELECT huella,equipo_id FROM copias WHERE equipo_id=? AND estado='ERROR' AND huella IS NOT NULL");
                    $rememberDeleted->execute([(int) $eq['id'], (int) $eq['id']]);
                    $deleteHistory = $pdo->prepare("DELETE FROM copias WHERE equipo_id=? AND estado='ERROR'");
                    $deleteHistory->execute([(int) $eq['id']]);
                    $erroresEliminados = $deleteHistory->rowCount();

                    // Retira también staging para que errores aún no importados no reaparezcan.
                    $deleteStaging = $pdo->prepare("DELETE FROM correo_copias WHERE equipo_id=? AND estado='ERROR'");
                    $deleteStaging->execute([(int) $eq['id']]);
                    $pdo->prepare('UPDATE equipos SET error_reviewed_at=NULL,error_reviewed_by=NULL,error_review_desc=NULL WHERE id=?')
                        ->execute([(int) $eq['id']]);
                }
            }
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
            'cliente' => $clienteNombre,
            'errores_eliminados' => $erroresEliminados,
        ];
    } catch (Throwable $e) {
        // Si algo falla, revierte tambien equipo y asignacion creados en esta transaccion.
        if ($pdo->inTransaction())
            $pdo->rollBack();
        throw $e;
    }
}

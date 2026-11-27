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

/** Conflicto de datos que debe resolver una persona (p. ej. fichas duplicadas); no es un fallo interno. */
final class IngestaConflicto extends RuntimeException
{
}

/**
 * Los informes de VM Explorer agrupan varias maquinas virtuales en un unico correo.
 * Cada resultado se guarda como una copia independiente, vinculada al equipo padre.
 *
 * La clave identifica la EJECUCION (equipo + VM + tarea + inicio), no el correo ni el informe:
 * el mismo informe reenviado, o dos informes que cubren la misma ejecucion, generan la misma
 * clave y el panel los trata como duplicados en vez de crear filas repetidas.
 */
function ingesta_expandir_maquinas(array $items): array
{
    $texto = static fn($v): string => is_scalar($v) ? mb_strtolower(trim((string) $v)) : '';
    $expandidos = [];
    foreach ($items as $item) {
        $maquinas = is_array($item) ? ($item['maquinas'] ?? null) : null;
        if (!is_array($maquinas) || $maquinas === []) {
            $expandidos[] = $item;
            continue;
        }
        foreach ($maquinas as $maquina) {
            if (!is_array($maquina)) {
                $expandidos[] = $maquina;
                continue;
            }
            $fecha = $maquina['fecha'] ?? $item['fecha'] ?? '';
            $fecha = is_scalar($fecha) ? trim((string) $fecha) : '';
            // Mismo instante con distinto formato (Z / +01:00) => misma clave.
            $ts = $fecha !== '' ? strtotime($fecha) : false;
            $fechaClave = $ts !== false ? gmdate('c', $ts) : $fecha;
            $identidad = implode("\0", [
                $texto($item['equipo'] ?? $item['nombre'] ?? ''),
                $texto($maquina['nombre'] ?? ''),
                $texto($maquina['tarea'] ?? ''),
                $fechaClave,
            ]);
            $expandidos[] = array_replace($item, [
                'message_id' => 'vmx:' . hash('sha256', $identidad),
                'maquina_virtual' => $maquina['nombre'] ?? null,
                'tarea' => $maquina['tarea'] ?? $item['tarea'] ?? null,
                'resultado' => $maquina['resultado'] ?? null,
                'motivo' => $maquina['motivo'] ?? '',
                'fecha' => $maquina['fecha'] ?? $item['fecha'] ?? null,
                'maquinas' => [],
            ]);
        }
    }
    return $expandidos;
}

/** Detecta avisos de prueba sin confundir nombres de equipos o pruebas de restauracion. */
function ingesta_es_prueba($item): bool
{
    if (!is_array($item)) {
        return false;
    }
    foreach (['es_prueba', 'is_test', 'test_message'] as $field) {
        if (in_array($item[$field] ?? null, [true, 1, '1', 'true'], true)) {
            return true;
        }
    }
    foreach (['resultado', 'resultado_original', 'tipo_mensaje', 'message_type'] as $field) {
        $value = $item[$field] ?? null;
        if (is_string($value) && in_array(strtoupper(trim($value)), ['TEST', 'PRUEBA', 'TEST_MESSAGE', 'TEST_EMAIL', 'TEST_NOTIFICATION'], true)) {
            return true;
        }
    }
    // El asunto original debe llegar desde n8n, incluso si la IA indica CORRECTA.
    foreach (['asunto', 'subject', 'origen', 'cuerpo', 'body', 'texto', 'text', 'html', 'textPlain', 'textHtml', 'evidencia', 'clasificacion_evidencia', 'motivo', 'desc_error'] as $field) {
        if (!isset($item[$field]) || !is_string($item[$field])) {
            continue;
        }
        $text = html_entity_decode(strip_tags($item[$field]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        if (preg_match('/\b(?:mensaje|correo|email|e-mail|notificaci(?:[oó]|\x{FFFD}|Ã³)n|env[ií]o)\s+(?:de\s+)?prueba\b|\btest\s+(?:e-?mail|message|notification)\b|\b(?:e-?mail|message|notification)\s+test\b/iu', $text)) {
            return true;
        }
        if (in_array($field, ['asunto', 'subject', 'origen'], true)
            && preg_match('/^\s*(?:\[(?:TEST|PRUEBA)\]|(?:TEST|PRUEBA)\s*:)|^\s*(?:TEST|PRUEBA)\s*$|(?:^|\s)[|–—-]\s*(?:TEST|PRUEBA)\s*$/iu', $text)) {
            return true;
        }
    }
    return false;
}

/** Filtra antes de normalizar o abrir una transaccion: una prueba nunca genera datos. */
function ingesta_recibir(PDO $pdo, $item): array
{
    if (ingesta_es_prueba($item)) {
        return ['ignorado' => true, 'motivo' => 'mensaje_de_prueba'];
    }
    $datos = ingesta_normalizar(ingesta_adaptar($item));
    // InnoDB puede abortar una de varias peticiones simultaneas que bloquean
    // las mismas fichas. El guardado revierte la transaccion antes de propagar
    // el error; repetir es seguro porque message_key hace idempotente la ingesta.
    for ($intento = 0; ; $intento++) {
        try {
            return ingesta_guardar($pdo, $datos);
        } catch (PDOException $e) {
            $errorMysql = (int) ($e->errorInfo[1] ?? 0);
            if ($intento >= 3 || ($e->getCode() !== '40001' && $errorMysql !== 1213)) {
                throw $e;
            }
            usleep((50_000 * (2 ** $intento)) + random_int(0, 50_000));
        }
    }
}
// Programas (software de gestión del cliente) que se copian aparte de los equipos de usuario y los servidores.
const INGESTA_PROGRAMAS = ['EuroAgro', 'EuroPos', 'EuroExtintor', 'EuroMetrics', 'DivaCon', 'DivaGes', 'BDP'];

/** Limpia sufijos de dominio local y espacios sobrantes en hostnames recibidos. */
function ingesta_limpiar_hostname(?string $nombre): ?string
{
    $n = trim((string) preg_replace('/\s+/u', ' ', (string) $nombre));
    $n = trim($n, " \t\n\r\0\x0B\"':");
    if ($n === '') {
        return null;
    }
    if (!filter_var($n, FILTER_VALIDATE_IP)) {
        $sinDominio = preg_replace('/\.(?:[a-z0-9-]+\.)?(?:local|lan|internal|localdomain|ad|corp|home)$/iu', '', $n);
        if (is_string($sinDominio) && trim($sinDominio) !== '') {
            $n = trim($sinDominio);
        }
    }
    return $n;
}

/** Clave normalizada para comparar nombres de equipo sin depender de guiones, espacios o tildes. */
function ingesta_normalizar_clave_equipo(string $nombre): string
{
    $limpio = ingesta_limpiar_hostname($nombre) ?? $nombre;
    $ascii = strtr($limpio, [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
    ]);
    return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $ascii));
}

/** Normaliza un nombre de empresa eliminando tildes y formas jurídicas comunes. */
function ingesta_normalizar_cliente(string $nombre): string
{
    $ascii = strtr(mb_strtolower(trim($nombre), 'UTF-8'), [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
    ]);
    $sinForma = preg_replace('/\b(?:s\.?\s*l\.?\s*u\.?|s\.?\s*l\.?|s\.?\s*a\.?|s\.?\s*c\.?|coop(?:erativa)?)\b/iu', ' ', $ascii);
    return trim((string) preg_replace('/[^a-z0-9]+/i', ' ', (string) $sinForma));
}

/** Busca un único cliente activo con SATBK, primero exacto y si no, por nombre normalizado inequívoco. */
function ingesta_buscar_cliente_unico(PDO $pdo, ?string $sugerido): ?int
{
    $sug = trim((string) $sugerido);
    if ($sug === '') {
        return null;
    }
    $q = $pdo->prepare('SELECT id FROM clientes WHERE activo=1 AND tiene_satbk=1 AND LOWER(nombre)=LOWER(?)');
    $q->execute([$sug]);
    $exactos = $q->fetchAll(PDO::FETCH_COLUMN);
    if (count($exactos) === 1) {
        return (int) $exactos[0];
    }

    $normSug = ingesta_normalizar_cliente($sug);
    if ($normSug === '') {
        return null;
    }
    $todos = $pdo->query('SELECT id, nombre FROM clientes WHERE activo=1 AND tiene_satbk=1')->fetchAll();
    $coincidenciasExactasNorm = [];
    $coincidenciasPalabra = [];
    foreach ($todos as $fila) {
        $normCli = ingesta_normalizar_cliente((string) $fila['nombre']);
        if ($normCli === '') {
            continue;
        }
        if ($normCli === $normSug) {
            $coincidenciasExactasNorm[] = (int) $fila['id'];
        } elseif (mb_strlen($normSug, 'UTF-8') >= 4 && preg_match('/(?:^|\s)' . preg_quote($normSug, '/') . '(?:\s|$)/', $normCli)) {
            $coincidenciasPalabra[] = (int) $fila['id'];
        }
    }
    if (count($coincidenciasExactasNorm) === 1) {
        return $coincidenciasExactasNorm[0];
    }
    if (count($coincidenciasExactasNorm) === 0 && count($coincidenciasPalabra) === 1) {
        return $coincidenciasPalabra[0];
    }
    return null;
}

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

function ingesta_severidad(string $estado, ?string $desc, ?string $codigo, $valor = null): string
{
    if ($estado !== 'ERROR') {
        return 'normal';
    }
    $raw = is_scalar($valor) ? mb_strtolower(trim((string) $valor), 'UTF-8') : '';
    $map = [
        'leve' => 'leve', 'low' => 'leve', 'menor' => 'leve',
        'normal' => 'normal', 'medio' => 'normal', 'media' => 'normal',
        'grave' => 'grave', 'high' => 'grave', 'alto' => 'grave', 'alta' => 'grave',
        'critico' => 'critico', 'crítico' => 'critico', 'critical' => 'critico', 'urgente' => 'critico',
    ];
    if (isset($map[$raw])) {
        return $map[$raw];
    }
    $texto = mb_strtolower(trim((string) $codigo . ' ' . (string) $desc), 'UTF-8');
    foreach (['sin_copia', 'critico', 'crítico', 'critical', 'corrupt', 'corrupción', 'ransomware', 'cifrado'] as $needle) {
        if (str_contains($texto, $needle)) {
            return 'critico';
        }
    }
    foreach (['not enough space', 'falta de espacio', 'sin espacio', 'disco lleno', 'space',
        'destino', 'destination', 'conectar', 'connect', 'servidor remoto',
        'access denied', 'acceso denegado', 'permission', 'permiso', 'timeout', 'tiempo de espera', 'vss', 'snapshot'] as $needle) {
        if (str_contains($texto, $needle)) {
            return 'grave';
        }
    }
    return 'normal';
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
        // Sin host en el asunto (p. ej. "... de fallo 2026-04-15") no se inventa un equipo.
        if (preg_match('/\b\d{4}-\d{2}-\d{2}\b/', $origen)) {
            return $r;
        }
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
        $nombre = trim($m[2]);
        // Si el "nombre" es solo una fecha, el asunto no identifica ningun equipo.
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

    // Nombre del equipo: prioriza el hostname real del cuerpo si n8n lo marcó explícitamente;
    // en caso contrario usa el asunto determinista, luego el equipo enviado, cliente o asunto.
    $equipo = ingesta_limpiar_hostname((string) ($it['equipo'] ?? '')) ?? '';
    $equipoOrigen = strtolower(trim((string) ($it['equipo_origen'] ?? '')));
    if ($equipoOrigen === 'cuerpo' && $equipo !== '') {
        $nombre = $equipo;
    } else {
        $nombre = ingesta_limpiar_hostname($p['nombre']) ?? ($equipo !== '' ? $equipo : ($cliente !== '' ? $cliente : $asunto));
    }

    $motivo = trim((string) preg_replace('/\s+/u', ' ', (string) ($it['motivo'] ?? '')));
    $desc = $estado !== 'OK' ? ($motivo !== '' ? $motivo : $etiqueta . ': ' . $asunto) : null;

    return [
        'message_id' => $it['message_id'] ?? null,
        'fecha' => $it['fecha'] ?? null,
        'software' => $software,
        'nombre' => $nombre,
        'maquina_virtual' => $it['maquina_virtual'] ?? null,
        'tarea' => $it['tarea'] ?? null,
        'origen' => $asunto !== '' ? $asunto : $nombre,
        'sugerido' => $cliente !== '' ? $cliente : (ingesta_clasificar_nombre($nombre)['empresa'] ?? $p['sugerido']),
        'estado' => $estado,
        'severidad' => ingesta_severidad($estado, $desc, $it['codigo'] ?? null, $it['severidad'] ?? ($res === 'FALLO_GRAVE' ? 'grave' : null)),
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
    $maquinaVirtual = ingesta_texto($it['maquina_virtual'] ?? null, 200);
    $tarea = ingesta_texto($it['tarea'] ?? null, 200);

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
    $descError = $estado !== 'OK' ? ingesta_texto($it['desc_error'] ?? null, 500) : null;
    $codigo = ingesta_texto($it['codigo'] ?? null, 40);

    return [
        'message_key' => sha1('n8n|' . strtolower($mid)),
        'software' => $software,
        'nombre' => $nombre,
        'maquina_virtual' => $maquinaVirtual,
        'tarea' => $tarea,
        'tipo' => $tipo,
        'programa' => $cl['programa'],
        'origen' => ingesta_texto($it['origen'] ?? null, 200) ?? $nombre,
        'sugerido' => ingesta_texto($it['sugerido'] ?? null, 200) ?? $cl['empresa'],
        'estado' => $estado,
        'severidad' => ingesta_severidad($estado, $descError, $codigo, $it['severidad'] ?? $it['severity'] ?? null),
        'fecha' => date('Y-m-d H:i:s', $ts),
        // Mantener estos límites alineados con correo_copias y copias en database/.
        'desc_error' => $descError,
        'codigo' => $codigo,
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

        $matchesById = [];
        $collectMatches = static function (array $rows) use (&$matchesById): void {
            foreach ($rows as $row) {
                $matchesById[(int) $row['id']] = $row;
            }
        };
        $alias = $pdo->prepare("SELECT DISTINCT e.id,e.cliente_id FROM equipo_nombres n JOIN equipos e ON e.id=n.equipo_id
            WHERE n.nombre=? AND (n.software=? OR n.software='MonitorAgent') LIMIT 3 FOR UPDATE");
        $alias->execute([$d['nombre'], $d['software']]);
        $collectMatches($alias->fetchAll());

        // Recupera alias que todavía no existían cuando se combinó el equipo.
        // Ambos campos conservan el nombre tal como llegó en el correo.
        $history = $pdo->prepare('SELECT DISTINCT e.id,e.cliente_id FROM copias cp
            JOIN equipos e ON e.id=cp.equipo_id WHERE cp.equipo=? AND cp.software=? LIMIT 3 FOR UPDATE');
        $history->execute([$d['nombre'], $d['software']]);
        $collectMatches($history->fetchAll());
        $stagedHistory = $pdo->prepare('SELECT DISTINCT e.id,e.cliente_id FROM correo_copias cc
            JOIN equipos e ON e.id=cc.equipo_id WHERE cc.nombre_recibido=? AND cc.software=? LIMIT 3 FOR UPDATE');
        $stagedHistory->execute([$d['nombre'], $d['software']]);
        $collectMatches($stagedHistory->fetchAll());

        // El hostname del agente también puede ser un alias válido para el equipo combinado.
        $monitor = $pdo->prepare('SELECT id,cliente_id FROM equipos
            WHERE nombre=? AND monitor_machine_id IS NOT NULL LIMIT 3 FOR UPDATE');
        $monitor->execute([$d['nombre']]);
        $collectMatches($monitor->fetchAll());

        // Algunos avisos añaden el cliente al final del hostname, por ejemplo
        // "BACKUP_XFRAMED-RADIO ORIHUELA". Solo se acepta si el cliente existe
        // con ese nombre y la ficha base pertenece a él de forma inequívoca.
        $suggestedCustomer = trim((string) ($d['sugerido'] ?? ''));
        if ($suggestedCustomer !== '') {
            $customerQuery = $pdo->prepare('SELECT id,nombre FROM clientes WHERE LOWER(nombre)=LOWER(?) LIMIT 2 FOR UPDATE');
            $customerQuery->execute([$suggestedCustomer]);
            $customers = $customerQuery->fetchAll();
            if (count($customers) === 1) {
                $customerName = preg_replace('/\s+/u', ' ', trim((string) $customers[0]['nombre']));
                $receivedName = preg_replace('/\s+/u', ' ', trim((string) $d['nombre']));
                $pattern = '/^(.+?)[\\s_-]+' . preg_quote($customerName, '/') . '$/iu';
                if (preg_match($pattern, $receivedName, $parts)) {
                    $baseName = trim($parts[1]);
                    $byCustomer = $pdo->prepare('SELECT id,cliente_id FROM equipos
                        WHERE cliente_id=? AND nombre=? LIMIT 3 FOR UPDATE');
                    $byCustomer->execute([(int) $customers[0]['id'], $baseName]);
                    $collectMatches($byCustomer->fetchAll());
                }
            }
        }

        // Si n8n no envió el cliente sugerido, prueba las separaciones del nombre
        // contra clientes reales. La pareja base + cliente debe ser exacta.
        $compositeQuery = $pdo->prepare('SELECT e.id,e.cliente_id FROM equipos e
            JOIN clientes c ON c.id=e.cliente_id
            WHERE e.nombre=? AND LOWER(c.nombre)=LOWER(?) LIMIT 3 FOR UPDATE');
        $receivedName = preg_replace('/\s+/u', ' ', trim((string) $d['nombre']));
        if (preg_match_all('/[-_\s]+/u', $receivedName, $separators, PREG_OFFSET_CAPTURE)) {
            foreach ($separators[0] as [$separator, $offset]) {
                $baseName = trim(substr($receivedName, 0, $offset), " \t\n\r\0\x0B_-");
                $customerName = trim(substr($receivedName, $offset + strlen($separator)));
                if ($baseName === '' || $customerName === '') {
                    continue;
                }
                $compositeQuery->execute([$baseName, $customerName]);
                $collectMatches($compositeQuery->fetchAll());
            }
        }

        $matches = array_values($matchesById);
        $clienteSugeridoId = ingesta_buscar_cliente_unico($pdo, $d['sugerido']);
        if (!$matches) {
            // El mismo hostname puede recibir copias de distintos programas. Reutiliza
            // la ficha existente solo si el nombre identifica un unico equipo.
            $sameName = $pdo->prepare('SELECT id,cliente_id FROM equipos WHERE nombre=? LIMIT 3 FOR UPDATE');
            $sameName->execute([$d['nombre']]);
            $matches = $sameName->fetchAll();
        }
        if (!$matches) {
            // Respaldo por clave normalizada (sin guiones, espacios ni sufijos) solo si identifica una única ficha.
            $claveBuscada = ingesta_normalizar_clave_equipo($d['nombre']);
            if ($claveBuscada !== '') {
                $candidatosSql = $clienteSugeridoId !== null
                    ? 'SELECT id,cliente_id,nombre FROM equipos WHERE cliente_id=?'
                    : 'SELECT id,cliente_id,nombre FROM equipos';
                $candidatosSt = $pdo->prepare($candidatosSql);
                $candidatosSt->execute($clienteSugeridoId !== null ? [$clienteSugeridoId] : []);
                $normMatches = [];
                foreach ($candidatosSt->fetchAll() as $cand) {
                    if (ingesta_normalizar_clave_equipo((string) $cand['nombre']) === $claveBuscada) {
                        $normMatches[] = ['id' => $cand['id'], 'cliente_id' => $cand['cliente_id']];
                    }
                }
                if (count($normMatches) === 1) {
                    $matches = $normMatches;
                }
            }
        }
        if (count($matches) > 1 && $clienteSugeridoId !== null) {
            $delCliente = array_values(array_filter(
                $matches,
                static fn(array $m): bool => (int) ($m['cliente_id'] ?? 0) === $clienteSugeridoId
            ));
            if (count($delCliente) === 1) {
                $matches = $delCliente;
            }
        }
        if (count($matches) > 1) {
            throw new IngestaConflicto('El nombre de recepción «' . $d['nombre'] . '» coincide con varias fichas. Hay que combinar o revisar esos equipos antes de importar más copias.');
        }
        if (count($matches) === 1) {
            $eq = $matches[0];
            $pdo->prepare('UPDATE equipos SET sugerido=COALESCE(?,sugerido),
                    tipo=COALESCE(tipo_manual,COALESCE(?,tipo)),programa=COALESCE(?,programa) WHERE id=?')
                ->execute([$d['sugerido'], $d['tipo'], $d['programa'], $eq['id']]);
            // Guarda también el nombre recibido para este software como alias estable.
            $saveAlias = $pdo->prepare('INSERT IGNORE INTO equipo_nombres (software,nombre,equipo_id) VALUES (?,?,?)');
            $saveAlias->execute([$d['software'], $d['nombre'], $eq['id']]);
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

        // Si el equipo aún no tiene cliente y el nombre sugerido coincide
        // con UN solo cliente activo con SATBK (exacto o normalizado), se asigna solo.
        if ($eq['cliente_id'] === null && $d['sugerido'] !== null) {
            $clienteAutoId = $clienteSugeridoId ?? ingesta_buscar_cliente_unico($pdo, $d['sugerido']);
            if ($clienteAutoId !== null) {
                $pdo->prepare('UPDATE equipos SET cliente_id=? WHERE id=?')->execute([$clienteAutoId, $eq['id']]);
                $eq['cliente_id'] = $clienteAutoId;
            }
        }

        // Los reenvios de una copia existente se ignoran y se informan como duplicados.
        $ins = $pdo->prepare('INSERT IGNORE INTO correo_copias
            (message_key,equipo_id,nombre_recibido,maquina_virtual,tarea,fecha,estado,severidad,desc_error,codigo,resultado_original,clasificacion_fuente,
                clasificacion_evidencia,software,destino,tamano,duracion)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $ins->execute([
            $d['message_key'],
            $eq['id'],
            $d['nombre'],
            $d['maquina_virtual'] ?? null,
            $d['tarea'] ?? null,
            $d['fecha'],
            $d['estado'],
            $d['severidad'] ?? 'normal',
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
                $erroresEliminados = correo_resolver_errores_equipo((int) $eq['id'], $pdo, $d['maquina_virtual'] ?? null, $d['tarea'] ?? null);
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

<?php
declare(strict_types=1);
// Endpoint para n8n. Sin sesión ni cookies: solo token Bearer.
define('SIN_SESION', true);
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/equipos.php';
require __DIR__ . '/src/tokens.php';
require __DIR__ . '/src/ingesta.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respuesta(array $d, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        respuesta(['error' => 'Método no permitido'], 405);
    }

    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $rateKey = rate_limit_bucket('ingest-ip', $ip);
    if (rate_limit_blocked($rateKey)) {
        respuesta(['error' => 'Demasiados intentos. Espera un minuto.'], 429);
    }

    // Token fijo de config.php ('ingest_token', mínimo 32 caracteres); si no coincide, se prueba con los de tokens.php.
    $enviado = token_de_peticion();
    $fijo = (string) cfg('ingest_token', '');
    $token = (strlen($fijo) >= 32 && is_string($enviado) && hash_equals($fijo, $enviado))
        ? ['nombre' => 'config']
        : token_validar($enviado);
    if (!$token) {
        rate_limit_failure($rateKey, 10, 60);
        error_log('[copias] intento de ingesta con token no válido, IP ' . $ip);
        header('WWW-Authenticate: Bearer');
        respuesta(['error' => 'Token no válido'], 401);
    }
    rate_limit_clear([$rateKey]);

    $raw = (string) file_get_contents('php://input', false, null, 0, 1048577);
    if (strlen($raw) > 1048576)
        respuesta(['error' => 'Cuerpo demasiado grande (máx. 1 MB)'], 413);
    $data = json_decode($raw, true);
    if (!is_array($data))
        respuesta(['error' => 'JSON no válido'], 400);

    // Acepta un objeto, una lista, o {"copias":[...]}
    $esLista = $data === [] || array_keys($data) === range(0, count($data) - 1);
    $items = isset($data['copias']) && is_array($data['copias']) ? $data['copias']
        : ($esLista ? $data : [$data]);
    if (!$items)
        respuesta(['error' => 'No hay copias que procesar'], 400);
    if (count($items) > INGESTA_MAX_ITEMS)
        respuesta(['error' => 'Máximo ' . INGESTA_MAX_ITEMS . ' copias por petición'], 413);

    $pdo = db();
    asegurar_tablas_equipos($pdo);

    $nuevos = $duplicados = 0;
    $errores = [];
    $detalle = [];
    foreach ($items as $i => $item) {
        try {
            $r = ingesta_guardar($pdo, ingesta_normalizar(ingesta_adaptar($item)));
            $r['nuevo'] ? $nuevos++ : $duplicados++;
            $detalle[] = [
                'equipo' => $r['equipo'],
                'software' => $r['software'],
                'asignado' => $r['asignado'],
                'cliente' => $r['cliente'],
                'nuevo' => $r['nuevo']
            ];
        } catch (InvalidArgumentException $e) {
            $errores[] = ['indice' => $i, 'error' => $e->getMessage()];
        } catch (Throwable $e) {
            error_log('[copias] ingesta: ' . $e->getMessage());
            $errores[] = ['indice' => $i, 'error' => 'Error interno al guardar'];
        }
    }

    // Registro de la última recepción (lo muestra la pantalla de equipos)
    try {
        $msg = mb_substr("token «{$token['nombre']}» · recibidos " . count($items) . " · duplicados $duplicados"
            . ($errores ? ' · rechazados ' . count($errores) . ': ' . $errores[0]['error'] : ''), 0, 500);
        $pdo->prepare("INSERT INTO correo_sync_log (inicio,fin,ok,nuevos,ignorados,mensaje,origen) VALUES (NOW(),NOW(),?,?,?,?,'n8n')")
            ->execute([$errores ? 0 : 1, $nuevos, count($errores), $msg]);
    } catch (Throwable $e) {
        error_log('[copias] log ingesta: ' . $e->getMessage());
    }

    respuesta([
        'ok' => !$errores,
        'recibidos' => count($items),
        'nuevos' => $nuevos,
        'duplicados' => $duplicados,
        'detalle' => $detalle,
        'errores' => $errores
    ], $errores ? 422 : 200);
} catch (Throwable $e) {
    error_log('[copias] ingest: ' . $e->getMessage());
    if ($e instanceof PDOException || str_contains($e->getMessage(), 'migrate.php')) {
        respuesta([
            'error' => 'Base de datos no disponible o con migraciones pendientes. Ejecuta php migrate.php por CLI y comprueba la conexión de la base de datos.'
        ], 503);
    }
    respuesta(['error' => cfg('debug') ? $e->getMessage() : 'Error interno del servidor'], 500);
}
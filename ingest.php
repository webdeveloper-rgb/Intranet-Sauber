<?php
declare(strict_types=1);
// Endpoint para n8n. Sin sesión ni cookies: solo token Bearer.
define('SIN_SESION', true);
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/equipos.php';
require __DIR__ . '/src/tokens.php';
require_once __DIR__ . '/src/ingesta.php';

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

    $token = token_autenticar();
    if (!$token) {
        usleep(300000); // frena la fuerza bruta
        header('WWW-Authenticate: Bearer');
        respuesta(['error' => 'Token no válido'], 401);
    }

    $raw = (string) file_get_contents('php://input', false, null, 0, 1048577);
    if (strlen($raw) > 1048576)
        respuesta(['error' => 'Cuerpo demasiado grande (máx. 1 MB)'], 413);
    $data = json_decode($raw, true);
    if (!is_array($data))
        respuesta(['error' => 'JSON no válido'], 400);

    // Acepta un objeto, una lista, o {"copias":[...]}
    $items = isset($data['copias']) && is_array($data['copias']) ? $data['copias']
        : (array_is_list($data) ? $data : [$data]);
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
            ingesta_marcar_ignorado($pdo, $item);
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
    ], $nuevos + $duplicados > 0 || !$errores ? 200 : 422);
} catch (Throwable $e) {
    error_log('[copias] ingest: ' . $e->getMessage());
    respuesta(['error' => cfg('debug') ? $e->getMessage() : 'Error interno del servidor'], 500);
}
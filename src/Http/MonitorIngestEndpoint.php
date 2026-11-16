<?php
declare(strict_types=1);

define('SIN_SESION', true);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../Security/ApiTokens.php';
require __DIR__ . '/../Monitoring/MonitorService.php';
require __DIR__ . '/JsonResponse.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function monitor_responder(array $data, int $status = 200): void
{
    JsonResponse::send($data, $status);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        monitor_responder(['error' => 'Método no permitido'], 405);
    }

    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $rateKey = rate_limit_bucket('monitor-ip', $ip);
    if (rate_limit_blocked($rateKey)) {
        monitor_responder(['error' => 'Demasiados intentos. Espera un minuto.'], 429);
    }
    $token = token_autenticar();
    if (!$token) {
        rate_limit_failure($rateKey, 10, 60);
        error_log('[monitor] intento de ingesta con token no válido, IP ' . $ip);
        header('WWW-Authenticate: Bearer');
        monitor_responder(['error' => 'Token no válido'], 401);
    }
    rate_limit_clear([$rateKey]);

    $raw = (string) file_get_contents('php://input', false, null, 0, 2097153);
    if (strlen($raw) > 2097152) {
        monitor_responder(['error' => 'Cuerpo demasiado grande (máx. 2 MB)'], 413);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        monitor_responder(['error' => 'JSON no válido'], 400);
    }
    try {
        $report = monitor_normalizar_informe($data, (string) ($_SERVER['HTTP_X_MACHINE_ID'] ?? ''));
    } catch (InvalidArgumentException $e) {
        monitor_responder(['error' => $e->getMessage()], 422);
    }

    $equipmentId = monitor_guardar_informe(db(), $report);
    monitor_responder([
        'ok' => true,
        'equipo_id' => $equipmentId,
        'alertas_activas' => count(monitor_evaluar_alertas($report)),
    ]);
} catch (Throwable $e) {
    error_log('[monitor] ingesta: ' . $e->getMessage());
    monitor_responder(['error' => cfg('debug') ? $e->getMessage() : 'Error interno del servidor'], 500);
}

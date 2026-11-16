<?php
declare(strict_types=1);

define('SIN_SESION', true);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../Security/ApiTokens.php';
require __DIR__ . '/../Monitoring/MonitorService.php';
require __DIR__ . '/JsonResponse.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function monitor_token_responder(array $data, int $status = 200): void
{
    JsonResponse::send($data, $status);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        monitor_token_responder(['error' => 'Método no permitido'], 405);
    }

    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $authRateKey = rate_limit_bucket('monitor-token-auth', $ip);
    if (rate_limit_blocked($authRateKey)) {
        monitor_token_responder(['error' => 'Demasiados intentos. Espera un minuto.'], 429);
    }

    $raw = (string) file_get_contents('php://input', false, null, 0, 16385);
    if (strlen($raw) > 16384) {
        monitor_token_responder(['error' => 'Cuerpo demasiado grande'], 413);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        monitor_token_responder(['error' => 'JSON no válido'], 400);
    }
    try {
        $machineId = monitor_texto($data['machineId'] ?? null, 128, true, 'machineId');
        $headerId = monitor_texto($_SERVER['HTTP_X_MACHINE_ID'] ?? null, 128, true, 'X-Machine-Id');
        if (!hash_equals($machineId, $headerId)) {
            throw new InvalidArgumentException('X-Machine-Id no coincide con machineId');
        }
    } catch (InvalidArgumentException $e) {
        monitor_token_responder(['error' => $e->getMessage()], 422);
    }

    $pdo = db();
    if (!monitor_agente_autenticar($pdo, token_de_peticion(), $machineId)) {
        rate_limit_failure($authRateKey, 10, 60);
        error_log('[monitor] solicitud de token con credencial de agente no válida, IP ' . $ip);
        header('WWW-Authenticate: Bearer');
        monitor_token_responder(['error' => 'Credencial de agente no válida'], 401);
    }
    rate_limit_clear([$authRateKey]);

    $issueRateKey = rate_limit_bucket('monitor-token-issue', $ip);
    if (rate_limit_blocked($issueRateKey)) {
        monitor_token_responder(['error' => 'Límite de solicitudes de token alcanzado'], 429);
    }
    rate_limit_failure($issueRateKey, 120, 60);

    $token = monitor_token_emitir($pdo, $machineId);
    monitor_token_responder(['token' => $token, 'expires_in' => 300]);
} catch (Throwable $e) {
    error_log('[monitor] emision de token temporal: ' . $e->getMessage());
    monitor_token_responder(['error' => cfg('debug') ? $e->getMessage() : 'Error interno del servidor'], 500);
}

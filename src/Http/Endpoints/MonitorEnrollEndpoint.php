<?php
declare(strict_types=1);

define('SIN_SESION', true);
require __DIR__ . '/../../bootstrap.php';
require __DIR__ . '/../../Monitoring/MonitorService.php';
require __DIR__ . '/../JsonResponse.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function monitor_enroll_responder(array $data, int $status = 200) : never
{
    JsonResponse::send($data, $status);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        monitor_enroll_responder(['error' => 'Método no permitido'], 405);
    }

    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $rateKey = rate_limit_bucket('monitor-enrollment', $ip);
    if (rate_limit_blocked($rateKey)) {
        monitor_enroll_responder(['error' => 'Demasiados intentos. Espera un minuto.'], 429);
    }

    $raw = (string) file_get_contents('php://input', false, null, 0, 16385);
    if (strlen($raw) > 16384) {
        monitor_enroll_responder(['error' => 'Cuerpo demasiado grande'], 413);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        monitor_enroll_responder(['error' => 'JSON no válido'], 400);
    }

    try {
        $machineId = monitor_texto($data['machineId'] ?? null, 128, true, 'machineId');
        $headerId = monitor_texto($_SERVER['HTTP_X_MACHINE_ID'] ?? null, 128, true, 'X-Machine-Id');
        if (!hash_equals($machineId, $headerId)) {
            throw new InvalidArgumentException('X-Machine-Id no coincide con machineId');
        }
    } catch (InvalidArgumentException $e) {
        monitor_enroll_responder(['error' => $e->getMessage()], 422);
    }

    if (!monitor_enrollment_redimir(
        db(),
        is_string($data['code'] ?? null) ? $data['code'] : null,
        $machineId,
        is_string($data['deviceToken'] ?? null) ? $data['deviceToken'] : null
    )) {
        rate_limit_failure($rateKey, 10, 60);
        monitor_enroll_responder(['error' => 'Código de alta no válido, caducado o ya utilizado'], 401);
    }

    rate_limit_clear([$rateKey]);
    monitor_enroll_responder(['ok' => true]);
} catch (Throwable $e) {
    error_log('[monitor] alta de agente: ' . $e->getMessage());
    monitor_enroll_responder(['error' => cfg('debug') ? $e->getMessage() : 'Error interno del servidor'], 500);
}

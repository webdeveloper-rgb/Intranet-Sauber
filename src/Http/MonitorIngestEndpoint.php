<?php
declare(strict_types=1);

define('SIN_SESION', true);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../Security/ApiTokens.php';
require __DIR__ . '/../Monitoring/MonitorService.php';
require_once __DIR__ . '/../Backup/EquipmentService.php';
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

    $raw = (string) file_get_contents('php://input', false, null, 0, 2097153);
    if (strlen($raw) > 2097152) {
        monitor_responder(['error' => 'Cuerpo demasiado grande (máx. 2 MB)'], 413);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        monitor_responder(['error' => 'JSON no válido'], 400);
    }
    $rustDeskPassword = $data['rustDeskPassword'] ?? null;
    if ($rustDeskPassword !== null && (!is_string($rustDeskPassword)
        || preg_match('/\A[A-Za-z0-9!@#$%^&*()_+=.,?-]{12,64}\z/', $rustDeskPassword) !== 1)) {
        monitor_responder(['error' => 'La contraseña de RustDesk no tiene un formato válido.'], 422);
    }
    try {
        $machineId = (string) ($_SERVER['HTTP_X_MACHINE_ID'] ?? '');
        $report = monitor_normalizar_informe($data, $machineId);
    } catch (InvalidArgumentException $e) {
        monitor_responder(['error' => $e->getMessage()], 422);
    }

    $pdo = db();
    if (!monitor_token_consumir($pdo, token_de_peticion(), $report['machineId'])) {
        rate_limit_failure($rateKey, 10, 60);
        error_log('[monitor] intento de ingesta con token temporal no válido, IP ' . $ip);
        header('WWW-Authenticate: Bearer');
        monitor_responder(['error' => 'Token temporal no válido, caducado o ya utilizado'], 401);
    }
    rate_limit_clear([$rateKey]);

    $equipmentId = monitor_guardar_informe($pdo, $report, $rustDeskPassword);
    monitor_responder([
        'ok' => true,
        'equipo_id' => $equipmentId,
        'event_extraction_mode' => monitor_event_mode_obtener($pdo, $equipmentId),
        'alertas_activas' => count(monitor_evaluar_alertas($report)),
    ]);
} catch (Throwable $e) {
    error_log('[monitor] ingesta: ' . $e->getMessage());
    monitor_responder(['error' => cfg('debug') ? $e->getMessage() : 'Error interno del servidor'], 500);
}

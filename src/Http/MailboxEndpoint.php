<?php
declare(strict_types=1);
// Endpoint para n8n: devuelve los correos de copias (leídos o no) que el panel aún no conoce. Solo token, sin sesión.
//   GET buzon.php?dias=3&max=20
define('SIN_SESION', true);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../Backup/EquipmentService.php';
require __DIR__ . '/../Security/ApiTokens.php';
require __DIR__ . '/../Backup/MailboxService.php';
require __DIR__ . '/JsonResponse.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respuesta(array $d, int $code = 200): void
{
    JsonResponse::send($d, $code);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        header('Allow: GET');
        respuesta(['error' => 'Método no permitido'], 405);
    }
    if (!token_autenticar()) {
        usleep(300000);
        header('WWW-Authenticate: Bearer');
        respuesta(['error' => 'Token no válido'], 401);
    }

    $dias = max(1, min(60, (int) ($_GET['dias'] ?? 3)));
    $max = max(1, min(50, (int) ($_GET['max'] ?? 20)));

    $pdo = db();
    asegurar_tablas_equipos($pdo);
    if (!(int) $pdo->query("SELECT GET_LOCK('buzon_leer', 0)")->fetchColumn())
        respuesta(['correos' => [], 'ocupado' => true]);
    try {
        respuesta(buzon_leer($pdo, $dias, $max));
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('buzon_leer')");
    }
} catch (BuzonError $e) {
    respuesta(['error' => $e->getMessage()], 502);
} catch (Throwable $e) {
    error_log('[copias] buzon: ' . $e->getMessage());
    respuesta(['error' => cfg('debug') ? $e->getMessage() : 'Error interno del servidor'], 500);
}

<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../Integration/ClientApi.php';
require __DIR__ . '/../Backup/EquipmentService.php';
require __DIR__ . '/../Security/ApiTokens.php';
require __DIR__ . '/../Backup/CopyRepository.php';
require __DIR__ . '/JsonResponse.php';
require __DIR__ . '/Controllers/ReadController.php';
require __DIR__ . '/Controllers/HistoryController.php';
require __DIR__ . '/Controllers/ClientController.php';
require __DIR__ . '/Controllers/AdminController.php';
require __DIR__ . '/Controllers/EquipmentController.php';
require __DIR__ . '/Controllers/CopyReviewController.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const MIN_PALABRAS = 15;

function out($data, int $status = 200): void
{
    JsonResponse::send($data, $status);
}

function fail(string $message, int $status = 400): void
{
    out(['error' => $message], $status);
}

function exigir_admin(array $user): void
{
    if (($user['rol'] ?? '') !== 'admin') {
        fail('Solo los administradores pueden hacer esto', 403);
    }
}

function palabras(string $text): int
{
    return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY));
}

function txt($value, int $max): string
{
    return mb_substr(trim((string) $value), 0, $max);
}

$action = (string) ($_GET['action'] ?? '');
$decodedInput = json_decode((string) file_get_contents('php://input'), true);
$input = is_array($decodedInput) ? $decodedInput : [];

try {
    $user = usuario_actual();
    if (!$user) {
        fail('Sesión caducada', 401);
    }

    $st = db()->prepare('SELECT nombre, rol, activo FROM usuarios WHERE id = ?');
    $st->execute([$user['id']]);
    $currentUser = $st->fetch();
    if (!$currentUser || (int) $currentUser['activo'] !== 1) {
        $_SESSION = [];
        fail('Tu usuario está desactivado', 401);
    }
    $user['rol'] = $currentUser['rol'];
    $user['nombre'] = $currentUser['nombre'];

    if (ReadController::handle($action, $user)) {
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fail('Método no permitido', 405);
    }
    if (!csrf_ok($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        fail('Token de seguridad no válido, recarga la página', 403);
    }

    $handlers = [
        HistoryController::class,
        ClientController::class,
        AdminController::class,
        EquipmentController::class,
        CopyReviewController::class,
    ];
    foreach ($handlers as $handler) {
        if ($handler::handle($action, $input, $user)) {
            exit;
        }
    }

    fail('Acción desconocida', 404);
} catch (Throwable $ex) {
    error_log('[copias] ' . $ex->getMessage());
    fail(cfg('debug') ? $ex->getMessage() : 'Error interno del servidor', 500);
}

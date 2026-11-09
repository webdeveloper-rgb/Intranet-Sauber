<?php
declare(strict_types=1);

$cfgFile = __DIR__ . '/../config/config.php';
if (!is_file($cfgFile)) {
    http_response_code(500);
    exit('Falta config/config.php. Copia config/config.example.php como config/config.php y rellena los datos de la base de datos.');
}
$GLOBALS['cfg'] = require $cfgFile;

date_default_timezone_set(cfg('timezone', 'Europe/Madrid'));
ini_set('display_errors', cfg('debug') ? '1' : '0');
error_reporting(E_ALL);

session_name('copias_sess');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
// ingest.php (llamado por n8n) no usa sesión: se autentica solo con token.
if (!defined('SIN_SESION')) {
    session_start();
}

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function cfg(string $k, $default = null)
{
    return $GLOBALS['cfg'][$k] ?? $default;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = cfg('db');
        $pdo = new PDO(
            "mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4",
            $c['user'],
            $c['pass'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
        $pdo->exec("SET time_zone = '" . date('P') . "'");
        if (empty($_SESSION['mig_usuarios'])) migrar_usuarios($pdo);
    }
    return $pdo;
}

// Instalaciones anteriores: añade las columnas rol/activo y hace admin al primer usuario
function migrar_usuarios(PDO $pdo): void
{
    $q = "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'usuarios'";
    if (!(int)$pdo->query($q)->fetchColumn()) return; // aún no instalado
    if (!(int)$pdo->query($q . " AND column_name = 'rol'")->fetchColumn()) {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN rol ENUM('admin','usuario') NOT NULL DEFAULT 'usuario' AFTER nombre,
                    ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1 AFTER rol");
        $pdo->exec("UPDATE usuarios SET rol = 'admin' WHERE id = (SELECT id FROM (SELECT MIN(id) AS id FROM usuarios) x)");
    }
    $_SESSION['mig_usuarios'] = 1;
}

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_ok(?string $t): bool
{
    return is_string($t) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}

function usuario_actual(): ?array
{
    return $_SESSION['user'] ?? null;
}

function exigir_login(): array
{
    $u = usuario_actual();
    if (!$u) {
        header('Location: login.php');
        exit;
    }
    return $u;
}

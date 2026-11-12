<?php
declare(strict_types=1);

$cfgFile = __DIR__ . '/../config/config.php';
if (!is_file($cfgFile)) {
    http_response_code(500);
    exit('Falta config/config.php. Copia config/config.example.php como config/config.php y rellena los datos de la base de datos.');
}
$GLOBALS['cfg'] = require $cfgFile;

const SCHEMA_VERSION_REQUERIDA = 2;

date_default_timezone_set(cfg('timezone', 'Europe/Madrid'));
ini_set('display_errors', cfg('debug') ? '1' : '0');
error_reporting(E_ALL);

$https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
$https = $https || (cfg('trust_proxy_https', false)
    && strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0])) === 'https');
session_name('copias_sess');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $https,
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
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'");
if ($https) {
    header('Strict-Transport-Security: max-age=31536000');
}

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
        if (!defined('SCHEMA_MIGRATING')) {
            schema_exigir_migraciones($pdo);
        }
    }
    return $pdo;
}

function schema_exigir_migraciones(PDO $pdo): void
{
    static $comprobado = false;
    if ($comprobado || defined('SCHEMA_MIGRATING')) {
        return;
    }
    try {
        $version = $pdo->query('SELECT version FROM schema_version WHERE id=1')->fetchColumn();
    } catch (PDOException $e) {
        throw new RuntimeException('Falta ejecutar `php migrate.php` para preparar la base de datos.', 0, $e);
    }
    if ((int) $version < SCHEMA_VERSION_REQUERIDA) {
        throw new RuntimeException('La base de datos tiene migraciones pendientes. Ejecuta `php migrate.php`.');
    }
    $comprobado = true;
}

function rate_limit_bucket(string $scope, string $identity): string
{
    $cfgDb = (array) cfg('db', []);
    $secret = (string) cfg('rate_limit_secret', $cfgDb['pass'] ?? '');
    return hash_hmac('sha256', $scope . "\0" . $identity, $secret);
}

function rate_limit_blocked(string $bucket): bool
{
    $st = db()->prepare('SELECT blocked_until FROM rate_limits WHERE bucket=? AND blocked_until>NOW()');
    $st->execute([$bucket]);
    return (bool) $st->fetchColumn();
}

function rate_limit_failure(string $bucket, int $maxAttempts, int $windowSeconds): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('INSERT INTO rate_limits (bucket,attempts,window_started) VALUES (?,0,NOW())
            ON DUPLICATE KEY UPDATE bucket=bucket');
        $st->execute([$bucket]);
        $st = $pdo->prepare('SELECT attempts,window_started FROM rate_limits WHERE bucket=? FOR UPDATE');
        $st->execute([$bucket]);
        $row = $st->fetch();
        $expired = strtotime((string) $row['window_started']) <= time() - $windowSeconds;
        $attempts = $expired ? 1 : (int) $row['attempts'] + 1;
        $blocked = $attempts >= $maxAttempts ? date('Y-m-d H:i:s', time() + $windowSeconds) : null;
        $st = $pdo->prepare('UPDATE rate_limits SET attempts=?,window_started=?,blocked_until=? WHERE bucket=?');
        $st->execute([
            $attempts,
            $expired ? date('Y-m-d H:i:s') : $row['window_started'],
            $blocked,
            $bucket,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function rate_limit_clear(array $buckets): void
{
    if (!$buckets) {
        return;
    }
    $in = implode(',', array_fill(0, count($buckets), '?'));
    db()->prepare("DELETE FROM rate_limits WHERE bucket IN ($in)")->execute($buckets);
}

// Ejecutada exclusivamente por migrate.php.
function migrar_usuarios(PDO $pdo): void
{
    $q = "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'usuarios'";
    if (!(int)$pdo->query($q)->fetchColumn()) return; // aún no instalado
    if (!(int)$pdo->query($q . " AND column_name = 'rol'")->fetchColumn()) {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN rol ENUM('admin','usuario') NOT NULL DEFAULT 'usuario' AFTER nombre,
                    ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1 AFTER rol");
        $pdo->exec("UPDATE usuarios SET rol = 'admin' WHERE id = (SELECT id FROM (SELECT MIN(id) AS id FROM usuarios) x)");
    }
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

<?php
declare(strict_types=1);

/**
 * Punto de entrada que conserva la raiz web actual y delega las rutas a public/.
 */
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($path) ? $path : '/';
$publicRoot = realpath(__DIR__ . '/public');
$staticFile = $publicRoot === false
    ? false
    : realpath($publicRoot . DIRECTORY_SEPARATOR . ltrim(rawurldecode($path), '/\\'));
if (PHP_SAPI === 'cli-server'
    && $publicRoot !== false
    && $staticFile !== false
    && str_starts_with($staticFile, $publicRoot . DIRECTORY_SEPARATOR)
    && preg_match('/\.(?:css|js|png|jpe?g|gif|svg|ico|woff2?)$/i', $staticFile) === 1) {
    $contentTypes = [
        'css' => 'text/css; charset=utf-8',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'js' => 'application/javascript; charset=utf-8',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];
    $extension = strtolower(pathinfo($staticFile, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($contentTypes[$extension] ?? 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    readfile($staticFile);
    exit;
}
$entry = strtolower(basename($path));
$routes = [
    'api.php' => 'api.php',
    'clientes.php' => 'clientes.php',
    'ingest.php' => 'ingest.php',
    'login.php' => 'login.php',
    'logout.php' => 'logout.php',
    'monitor-enroll.php' => 'monitor-enroll.php',
    'monitor-ingest.php' => 'monitor-ingest.php',
    'monitor-token.php' => 'monitor-token.php',
];

if (isset($routes[$entry])) {
    require __DIR__ . '/public/' . $routes[$entry];
    exit;
}

require __DIR__ . '/public/index.php';

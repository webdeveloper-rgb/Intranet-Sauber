<?php
declare(strict_types=1);

/**
 * Shell HTML autenticado que carga los recursos compilados de React y establece la base de rutas.
 */
require __DIR__ . '/../../bootstrap.php';
exigir_login();

// Evita que una recarga del panel reutilice un shell HTML con URLs de recursos antiguas.
header('Cache-Control: no-cache, must-revalidate');

$js = __DIR__ . '/../../../build/app.js';
$css = __DIR__ . '/../../../build/app.css';
$basePath = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
$baseHref = ($basePath === '' || $basePath === '.') ? '/' : $basePath . '/';
$compilado = is_file($js) && is_file($css);
$versionJs = $compilado ? substr(hash_file('sha256', $js), 0, 16) : '';
$versionCss = $compilado ? substr(hash_file('sha256', $css), 0, 16) : '';
?><!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <base href="<?= e($baseHref) ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow">
    <title><?= e(cfg('titulo')) ?> · Sauber Ofimática</title>
    <?php if ($compilado): ?>
        <link rel="stylesheet" href="build/app.css?v=<?= e($versionCss) ?>">
    <?php endif; ?>
</head>

<body>
    <div id="root"></div>
    <?php if ($compilado): ?>
        <script type="module" src="build/app.js?v=<?= e($versionJs) ?>"></script>
    <?php else: ?>
        <p style="font:16px system-ui;padding:24px">Falta compilar el frontend: ejecuta
            <code>npm install &amp;&amp; npm run build</code> en la carpeta <code>frontend/</code> (ver LEEME.md).</p>
    <?php endif; ?>
</body>

</html>

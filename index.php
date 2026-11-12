<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
exigir_login();

$js = __DIR__ . '/build/app.js';
$css = __DIR__ . '/build/app.css';
$basePath = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
$baseHref = ($basePath === '' || $basePath === '.') ? '/' : $basePath . '/';
$compilado = is_file($js) && is_file($css);
?><!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <base href="<?= e($baseHref) ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow">
    <title><?= e(cfg('titulo')) ?> · Sauber Ofimática</title>
    <?php if ($compilado): ?>
        <link rel="stylesheet" href="build/app.css?v=<?= filemtime($css) ?>">
    <?php endif; ?>
</head>

<body>
    <div id="root"></div>
    <?php if ($compilado): ?>
        <script src="build/app.js?v=<?= filemtime($js) ?>"></script>
    <?php else: ?>
        <p style="font:16px system-ui;padding:24px">Falta compilar el frontend: ejecuta
            <code>npm install &amp;&amp; npm run build</code> en la carpeta <code>frontend/</code> (ver LEEME.md).</p>
    <?php endif; ?>
</body>

</html>

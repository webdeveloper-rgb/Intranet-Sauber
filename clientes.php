<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
exigir_login();

$basePath = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
header('Location: ' . ($basePath === '' || $basePath === '.' ? '' : $basePath) . '/clientes', true, 302);
exit;

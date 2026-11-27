<?php
declare(strict_types=1);

/**
 * Autocarga de funciones globales del proyecto.
 * Composer ejecuta este archivo una sola vez por petición (autoload.files).
 */
foreach (glob(__DIR__ . '/*/*.php') ?: [] as $file) {
    if (basename($file) === 'bootstrap.php' || basename($file) === 'autoload.php') {
        continue;
    }
    require_once $file;
}
foreach (glob(__DIR__ . '/*/*/*.php') ?: [] as $file) {
    require_once $file;
}
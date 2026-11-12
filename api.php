<?php
declare(strict_types=1);


/**
 * Entrada HTTP compatible del panel; delega el procesamiento JSON en src/Http/ApiEndpoint.php.
 */
// URL pública conservada para el panel. La implementación vive en src/Http.
require __DIR__ . '/src/Http/ApiEndpoint.php';

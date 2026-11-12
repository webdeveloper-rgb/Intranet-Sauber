<?php
declare(strict_types=1);


/**
 * Entrada HTTP del workflow n8n; delega autenticacion e ingesta en src/Http/IngestEndpoint.php.
 */
// URL pública conservada para n8n. La implementación vive en src/Http.
require __DIR__ . '/src/Http/IngestEndpoint.php';

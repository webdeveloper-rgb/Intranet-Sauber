<?php
declare(strict_types=1);


/**
 * Entrada CLI de mantenimiento; aplica la politica de retencion a logs y datos temporales.
 */
// Removes expired logs and imported staging data.
require dirname(__DIR__) . '/src/Console/Purge.php';

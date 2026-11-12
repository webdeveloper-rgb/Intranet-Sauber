<?php
declare(strict_types=1);


/**
 * Entrada CLI de mantenimiento; aplica la politica de retencion a logs y datos temporales.
 */
// Comando de mantenimiento conservado en la raíz para las tareas de Plesk.
require __DIR__ . '/src/Console/Purge.php';

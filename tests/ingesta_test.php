<?php
declare(strict_types=1);

require __DIR__ . '/../src/Backup/IngestionService.php';

$checks = 0;
$same = static function ($expected, $actual, string $case) use (&$checks): void {
    $checks++;
    if ($expected !== $actual) {
        fwrite(STDERR, "{$case}: esperado " . var_export($expected, true) . ', recibido ' . var_export($actual, true) . "\n");
        exit(1);
    }
};
$same(
    ['empresa' => 'HORNO JOAQUIN', 'tipo' => 'usuario', 'programa' => null],
    ingesta_clasificar_nombre('HORNO_JOAQUIN_PC-JOAQUIN'),
    'PC de usuario'
);
$same('servidor', ingesta_clasificar_nombre('ACME_SRV-SQL01')['tipo'], 'servidor SRV');
$same('servidor', ingesta_clasificar_nombre('ACME_SERVIDOR')['tipo'], 'servidor español');
$same('EuroAgro', ingesta_clasificar_nombre('ACME_EuroAgro_v2')['programa'], 'nombre de programa');
$same('usuario', ingesta_clasificar_nombre('DESKTOP-AI8MRE3')['tipo'], 'equipo Windows sin prefijo de cliente');
$same('usuario', ingesta_clasificar_nombre('LAPTOP-LUCIA')['tipo'], 'portátil Windows sin prefijo de cliente');
$same('servidor', ingesta_clasificar_nombre('ESXI-HOST01')['tipo'], 'host VMware sin prefijo de cliente');
$same(null, ingesta_clasificar_nombre('copia nocturna 2026-04-15')['tipo'], 'nombre irreconocible');

$acronis = ingesta_parsear_asunto('Notificación de Acronis True Image de ACME_PC-WS01');
$same('Acronis', $acronis['software'], 'asunto Acronis');
$same('ACME_PC-WS01', $acronis['nombre'], 'equipo Acronis');
$same('ACME', $acronis['sugerido'], 'cliente sugerido Acronis');
$same(null, ingesta_parsear_asunto('Notificación de Acronis True Image de fallo 2026-04-15')['nombre'], 'Acronis sin equipo');

$iperius = ingesta_parsear_asunto('Copia de seguridad diaria - ACME_SERVER-SQL01 - Iperius Backup');
$same('Iperius', $iperius['software'], 'asunto Iperius');
$same('ACME_SERVER-SQL01', $iperius['nombre'], 'equipo Iperius');
$same(null, ingesta_parsear_asunto('Copia de seguridad diaria - 2026-04-15 - Iperius Backup')['nombre'], 'Iperius sin equipo');

$vmware = ingesta_parsear_asunto('VMware ESXi backup completed');
$same('VMware', $vmware['software'], 'asunto VMware');
$same(null, $vmware['nombre'], 'VMware requiere nombre explícito');

echo "{$checks} comprobaciones de ingesta correctas.\n";

<?php
declare(strict_types=1);

/** Pruebas unitarias simples para las reglas puras de nombres y asuntos de ingesta. */
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

$baseAviso = [
    'message_id' => '<acronis-demo@example.test>',
    'asunto' => 'Notificacion de Acronis True Image de ACME_SRV-ARCHIVO',
    'fecha' => '2026-11-17T01:22:28+01:00',
    'software' => 'Acronis',
    'equipo' => 'ACME_SRV-ARCHIVO',
    'tipo_equipo' => 'SERVIDOR',
    'clasificacion_fuente' => 'n8n_ai_gpt4o_mini',
    'evidencia' => 'La imagen aparece como creada; el error posterior ocurre al consolidar un archivo.',
    'motivo' => 'La copia se creó, pero hay un error de archivo durante la consolidación.',
    'codigo' => '0xb03e9',
];
$ambiguo = ingesta_normalizar(ingesta_adaptar($baseAviso + ['resultado' => 'REVISION']));
$same('REVISION', $ambiguo['estado'], 'el error posterior a una copia creada queda para revisión');
$same('REVISION', $ambiguo['resultado_original'], 'se conserva la clasificación original');
$same('n8n_ai_gpt4o_mini', $ambiguo['clasificacion_fuente'], 'se conserva la fuente de clasificación');
$same($baseAviso['evidencia'], $ambiguo['clasificacion_evidencia'], 'se conserva la evidencia del correo');
$same('ERROR', ingesta_normalizar(ingesta_adaptar($baseAviso + ['resultado' => 'FALLO_GRAVE']))['estado'], 'fallo grave confirmado');
$same('WARNING', ingesta_normalizar(ingesta_adaptar($baseAviso + ['resultado' => 'FALLO_LEVE']))['estado'], 'fallo leve queda como advertencia y no como fallo');
$same('RUNNING', ingesta_normalizar(ingesta_adaptar($baseAviso + ['resultado' => 'EN_CURSO']))['estado'], 'una copia que sigue ejecutandose conserva su estado');
$same('OK', ingesta_normalizar(ingesta_adaptar($baseAviso + ['resultado' => 'CORRECTA']))['estado'], 'copia correcta confirmada');
$same('REVISION', ingesta_normalizar(ingesta_adaptar($baseAviso + ['resultado' => 'DESCONOCIDO']))['estado'], 'resultado sin clasificar pasa a revision');

$same('SRV-DATOS', ingesta_limpiar_hostname('SRV-DATOS.empresa.local'), 'limpia sufijo .local en hostname');
$same('192.168.1.10', ingesta_limpiar_hostname('192.168.1.10'), 'conserva IPv4 intacta');
$same('srvsql01', ingesta_normalizar_clave_equipo('SRV-SQL_01.empresa.local'), 'normaliza clave de equipo');
$same('el cortijo', ingesta_normalizar_cliente('El Cortijo S.L.U.'), 'normaliza nombre de cliente sin forma jurídica');

$cuerpoPrioridad = ingesta_normalizar(ingesta_adaptar([
    'message_id' => '<iperius-cuerpo@example.test>',
    'asunto' => 'Copia de seguridad diaria - TAREA-CLIENTE - Iperius Backup',
    'fecha' => '2026-11-17T01:22:28+01:00',
    'software' => 'Iperius',
    'equipo' => 'COPIAS-PC.dominio.local',
    'equipo_origen' => 'cuerpo',
    'resultado' => 'FALLO_GRAVE',
    'motivo' => 'Error de corrupción en archivo destino',
]));
$same('COPIAS-PC', $cuerpoPrioridad['nombre'], 'prioriza equipo del cuerpo y limpia dominio');
$same('critico', $cuerpoPrioridad['severidad'], 'escala a crítico ante corrupción');
$same('Error de corrupción en archivo destino', $cuerpoPrioridad['desc_error'], 'desc_error sin prefijo redundante cuando hay motivo');

echo "{$checks} comprobaciones de ingesta correctas.\n";

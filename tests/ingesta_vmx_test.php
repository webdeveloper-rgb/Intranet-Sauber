<?php
declare(strict_types=1);

/** Informes de VM Explorer: expansion por VM y clave estable por ejecucion (no por correo). */
require __DIR__ . '/../src/Backup/IngestionService.php';

set_error_handler(static function (int $no, string $msg): never {
    throw new ErrorException($msg, 0, $no);
});

$checks = 0;
$same = static function ($expected, $actual, string $case) use (&$checks): void {
    $checks++;
    if ($expected !== $actual) {
        fwrite(STDERR, "{$case}: esperado " . var_export($expected, true) . ', recibido ' . var_export($actual, true) . "\n");
        exit(1);
    }
};
$clave = static fn(array $item): string => ingesta_normalizar(ingesta_adaptar($item))['message_key'];

$maquinas = [
    ['nombre' => 'SRVREMOTO', 'tarea' => 'COPIA SRVREMOTE', 'resultado' => 'CORRECTA', 'motivo' => '', 'fecha' => '2026-11-25T04:00:02.000Z'],
    ['nombre' => 'W2008', 'tarea' => 'COPIA ORACLE', 'resultado' => 'FALLO_LEVE', 'motivo' => 'backup antiguo no consistente', 'fecha' => '2026-11-25T00:30:01.000Z'],
];
$informe = static fn(string $id, string $generado, array $m = null) => [
    'message_id' => $id, 'fecha' => $generado, 'asunto' => 'Copias Cortijo Nuevos', 'cliente' => 'CORTIJO',
    'resultado' => 'FALLO_LEVE', 'software' => 'MicroFocus', 'equipo' => 'WIN-3LBFLGPMBFQ', 'tipo_equipo' => 'SERVIDOR',
    'maquinas' => $m ?? $maquinas,
];

// Una fila por VM, con su propio resultado y tarea
$orig = ingesta_expandir_maquinas([$informe('VMX|WIN|2026-11-25T08:00:03.000Z|WIN', '2026-11-25T08:00:03.000Z')]);
$same(2, count($orig), 'una fila por VM');
$same('SRVREMOTO', $orig[0]['maquina_virtual'], 'nombre de VM');
$same('COPIA ORACLE', $orig[1]['tarea'], 'tarea de la VM');
$same('FALLO_LEVE', $orig[1]['resultado'], 'resultado propio de la VM');
$same([], $orig[0]['maquinas'], 'la fila expandida no vuelve a expandirse');

// El mismo informe con otro id de correo / otra hora de generacion => mismas claves
$reenvio = ingesta_expandir_maquinas([$informe('<otro-message-id@x>', '2026-11-25T08:05:00.000Z')]);
foreach ([0, 1] as $i) {
    $same($clave($orig[$i]), $clave($reenvio[$i]), "reenvio o informe repetido, VM $i");
}

// Mismo instante con otro formato horario => misma clave
$otroFormato = $maquinas;
$otroFormato[0]['fecha'] = '2026-11-25T05:00:02+01:00';
$mf = ingesta_expandir_maquinas([$informe('x', '2026-11-25T08:00:03.000Z', $otroFormato)]);
$same($clave($orig[0]), $clave($mf[0]), 'Z y +01:00 son el mismo instante');

// Mayusculas/espacios no cambian la identidad
$espacios = $maquinas;
$espacios[0]['nombre'] = '  srvremoto ';
$sp = ingesta_expandir_maquinas([$informe('x', '2026-11-25T08:00:03.000Z', $espacios)]);
$same($clave($orig[0]), $clave($sp[0]), 'normalizacion de nombre');

// Cambia VM, tarea, equipo o inicio => clave distinta
$same(true, $clave($orig[0]) !== $clave($orig[1]), 'VM distinta');
$otraTarea = $maquinas; $otraTarea[0]['tarea'] = 'COPIA EMI';
$same(true, $clave(ingesta_expandir_maquinas([$informe('x', '2026-11-25T08:00:03.000Z', $otraTarea)])[0]) !== $clave($orig[0]), 'tarea distinta');
$otroInicio = $maquinas; $otroInicio[0]['fecha'] = '2026-11-26T04:00:02.000Z';
$same(true, $clave(ingesta_expandir_maquinas([$informe('x', '2026-11-26T08:00:03.000Z', $otroInicio)])[0]) !== $clave($orig[0]), 'otro dia');
$otroEquipo = $informe('x', '2026-11-25T08:00:03.000Z'); $otroEquipo['equipo'] = 'DESKTOP-66H4NQ6';
$same(true, $clave(ingesta_expandir_maquinas([$otroEquipo])[0]) !== $clave($orig[0]), 'otro host');

// Sin fecha por VM se usa la del informe; datos no escalares no provocan avisos
$sinFecha = $maquinas; unset($sinFecha[0]['fecha']);
$sf = ingesta_expandir_maquinas([$informe('x', '2026-11-25T08:00:03.000Z', $sinFecha)]);
$same('2026-11-25T08:00:03.000Z', $sf[0]['fecha'], 'fecha del informe como respaldo');
$raro = $informe('x', '2026-11-25T08:00:03.000Z', [['nombre' => ['no', 'texto'], 'tarea' => null, 'resultado' => 'CORRECTA']]);
$raro['equipo'] = ['tampoco'];
$same(1, count(ingesta_expandir_maquinas([$raro])), 'valores no escalares tolerados');

// Items sin maquinas (Acronis, Iperius...) pasan intactos
$normal = ['message_id' => '<a@b>', 'asunto' => 'Notificacion de Acronis True Image de ACME_PC-WS01', 'resultado' => 'CORRECTA'];
$same([$normal], ingesta_expandir_maquinas([$normal]), 'aviso sin maquinas intacto');
$same([$normal + ['maquinas' => []]], ingesta_expandir_maquinas([$normal + ['maquinas' => []]]), 'lista de maquinas vacia intacta');

echo "{$checks} comprobaciones de informes VM Explorer correctas.\n";

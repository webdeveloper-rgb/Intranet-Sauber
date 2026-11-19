<?php
declare(strict_types=1);

require __DIR__ . '/../src/Backup/IngestionService.php';

$cases = [
    [['cuerpo' => 'Este es un mensaje de notificación de prueba'], true],
    [['textPlain' => "Este es un mensaje de notificaci\u{FFFD}n de prueba"], true],
    [['body' => 'Este es un mensaje de notificaciÃ³n de prueba'], true],
    [['textHtml' => '<p>Este es un mensaje de notificaci&oacute;n de <b>prueba</b></p>'], true],
    [['asunto' => 'Test email from Acronis', 'resultado' => 'CORRECTA'], true],
    [['subject' => '[TEST] Iperius Backup'], true],
    [['origen' => 'Iperius Backup - Prueba'], true],
    [['body' => 'This is a test notification.'], true],
    [['es_prueba' => true], true],
    [['resultado' => 'PRUEBA'], true],
    [['is_test' => false, 'test_message' => 'false'], false],
    [['nombre' => 'PC-TEST', 'equipo' => 'TEST-SERVER', 'cliente' => 'Prueba SL'], false],
    [['asunto' => 'Copia de seguridad diaria - PC-TEST - Iperius Backup'], false],
    [['asunto' => 'Copia correcta', 'cuerpo' => 'Prueba de restauración realizada correctamente.'], false],
    [['asunto' => 'Backup failed', 'body' => 'Connection test failed.'], false],
    [['cuerpo' => ['not valid text']], false],
    [null, false],
];

// Ninguna prueba debe llegar a iniciar una transaccion ni necesitar campos de copia.
$pdo = new class extends PDO {
    public function __construct() {}
    public function beginTransaction(): bool { throw new RuntimeException('Se intento guardar una prueba'); }
};
foreach ($cases as $i => [$item, $expected]) {
    if (ingesta_es_prueba($item) !== $expected) {
        throw new RuntimeException('Deteccion incorrecta, caso ' . $i);
    }
    if ($expected && ingesta_recibir($pdo, $item) !== ['ignorado' => true, 'motivo' => 'mensaje_de_prueba']) {
        throw new RuntimeException('No se ignoro la prueba, caso ' . $i);
    }
}
echo count($cases) . " casos correctos; mensajes de prueba ignorados sin escribir datos.\n";

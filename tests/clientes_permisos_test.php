<?php
declare(strict_types=1);

require __DIR__ . '/../src/Http/Controllers/ClientController.php';

final class ApiError extends RuntimeException {}
final class ClientResponse extends RuntimeException
{
    public function __construct(public array $data, public int $status) { parent::__construct(); }
}
function out(array $data, int $status = 200): void { throw new ClientResponse($data, $status); }
function fail(string $message, int $status = 400): void { out(['error' => $message], $status); }
function exigir_admin(array $user): void
{
    if (($user['rol'] ?? '') !== 'admin') fail('Solo los administradores pueden hacer esto', 403);
}
function api_sync(string $source): array
{
    $GLOBALS['sync_calls']++;
    if ($source !== 'web') throw new RuntimeException('Origen incorrecto');
    if ($GLOBALS['api_error']) throw new ApiError('Fallo simulado de API externa');
    return ['clientes' => 12];
}
$GLOBALS['sync_calls'] = 0;
$GLOBALS['api_error'] = false;
$cases = [
    [[], ['rol'=>'admin'], 200],
    [[], ['rol'=>'usuario'], 403],
    [['rol'=>'admin'], ['rol'=>'usuario'], 403],
    [['rol'=>'usuario'], ['rol'=>'admin'], 200],
];
foreach ($cases as [$input, $user, $expected]) {
    try {
        // El mismo orden de argumentos que utiliza el despachador de la API.
        ClientController::handle('sync', $input, $user);
        throw new RuntimeException('No hubo respuesta');
    } catch (ClientResponse $r) {
        if ($r->status !== $expected) throw new RuntimeException('Permiso incorrecto');
    }
}
if ($GLOBALS['sync_calls'] !== 2) throw new RuntimeException('Se sincronizo sin permiso');
$GLOBALS['api_error'] = true;
try { ClientController::handle('sync', [], ['rol'=>'admin']); }
catch (ClientResponse $r) {
    if ($r->status !== 502) throw new RuntimeException('Error de API externa mal comunicado');
}
if (ClientController::handle('otra_accion', [], ['rol'=>'admin']) !== false) throw new RuntimeException('Accion ajena capturada');
echo "Permisos de sincronizacion verificados: administrador, usuario, rol falsificado y error externo.\n";

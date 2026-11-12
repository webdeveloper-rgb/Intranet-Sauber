<?php
declare(strict_types=1);


/**
 * Acciones administrativas relacionadas con sincronizar el catalogo de clientes.
 */
final class ClientController
{
    public static function handle(string $action, array $user): bool
    {
        if ($action === 'sync') {
            exigir_admin($user);
            try {
                out(['ok' => true] + (api_sync('web') ?? []));
            } catch (ApiError $ex) {
                fail($ex->getMessage(), 502);
            }
        }

        return false;
    }
}

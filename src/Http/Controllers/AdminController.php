<?php
declare(strict_types=1);

final class AdminController
{
    public static function handle(string $action, array $input, array $user): bool
    {
        if ($action === 'token_crear') {
            exigir_admin($user);
            $nombre = txt($input['nombre'] ?? '', 100);
            if ($nombre === '') {
                fail('Pon un nombre al token (por ejemplo: n8n producción)');
            }
            $dias = (int) ($input['dias'] ?? 0);
            out(['ok' => true] + token_crear($nombre, $user['usuario'], $dias > 0 ? min(3650, $dias) : null), 201);
        }

        if ($action === 'token_revocar') {
            exigir_admin($user);
            tokens_asegurar_tabla(db());
            db()->prepare('UPDATE api_tokens SET activo = 0 WHERE id = ?')->execute([(int) ($input['id'] ?? 0)]);
            out(['ok' => true]);
        }

        if (in_array($action, ['mi_password', 'usuario_crear', 'usuario_editar'], true)) {
            fail('Los accesos se administran desde la API de usuarios autorizados.', 403);
        }

        return false;
    }
}

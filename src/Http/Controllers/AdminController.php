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

        if (in_array($action, ['mi_password', 'usuario_crear'], true)) {
            fail('Las credenciales se gestionan en la API; los usuarios autorizados se aprovisionan al iniciar sesión.');
        }

        if ($action !== 'usuario_editar') {
            return false;
        }

        exigir_admin($user);
        $uid = (int) ($input['id'] ?? 0);
        $st = db()->prepare('SELECT id, rol, activo FROM usuarios WHERE id = ?');
        $st->execute([$uid]);
        $target = $st->fetch();
        if (!$target) {
            fail('Usuario no encontrado', 404);
        }

        $isSelf = $uid === (int) $user['id'];
        $set = [];
        $values = [];
        if (array_key_exists('nombre', $input)) {
            $set[] = 'nombre = ?';
            $values[] = txt($input['nombre'], 100);
        }
        if (array_key_exists('rol', $input)) {
            if (!in_array($input['rol'], ['admin', 'usuario'], true)) {
                fail('Rol no válido');
            }
            if ($isSelf && $input['rol'] !== 'admin') {
                fail('No puedes quitarte a ti mismo el rol de administrador');
            }
            $set[] = 'rol = ?';
            $values[] = $input['rol'];
        }
        if (array_key_exists('activo', $input)) {
            if ($isSelf && !$input['activo']) {
                fail('No puedes desactivarte a ti mismo');
            }
            $set[] = 'activo = ?';
            $values[] = $input['activo'] ? 1 : 0;
        }
        if (!empty($input['password'])) {
            if (mb_strlen((string) $input['password']) < MIN_PASSWORD) {
                fail('La contraseña debe tener al menos ' . MIN_PASSWORD . ' caracteres');
            }
            $set[] = 'password_hash = ?';
            $values[] = password_hash((string) $input['password'], PASSWORD_DEFAULT);
        }
        if (!$set) {
            fail('Nada que cambiar');
        }

        $values[] = $uid;
        db()->prepare('UPDATE usuarios SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($values);
        out(['ok' => true]);
        return true;
    }
}

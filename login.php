<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/Integration/ClientApi.php';

if (usuario_actual()) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identificador = is_string($_POST['usuario'] ?? null) ? trim($_POST['usuario']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $rateKeys = [
        rate_limit_bucket('login-ip', $ip),
        rate_limit_bucket('login-user', mb_strtolower($identificador, 'UTF-8')),
    ];
    try {
        $rateBlocked = rate_limit_blocked($rateKeys[0]) || rate_limit_blocked($rateKeys[1]);
    } catch (Throwable $ex) {
        error_log('[copias] no se pudo comprobar el límite de acceso: ' . $ex->getMessage());
        http_response_code(503);
        $error = 'El servicio de acceso no está disponible. Contacta con el administrador.';
        $rateBlocked = true;
    }
    if ($rateBlocked && $error !== '') {
        http_response_code(503);
    } elseif (!csrf_ok($_POST['csrf'] ?? null)) {
        $error = 'Sesión caducada, inténtalo de nuevo.';
    } elseif ($rateBlocked) {
        $error = 'Demasiados intentos. Espera un minuto.';
    } elseif ($identificador === '' || strlen($identificador) > 150 || $password === '') {
        try {
            foreach ($rateKeys as $key) {
                rate_limit_failure($key, 5, 60);
            }
            $error = 'Usuario o contraseña incorrectos.';
        } catch (Throwable $ex) {
            error_log('[copias] no se pudo registrar el intento de acceso: ' . $ex->getMessage());
            http_response_code(503);
            $error = 'El servicio de acceso no está disponible. Contacta con el administrador.';
        }
    } else {
        try {
            $identidad = api_autenticar_usuario($identificador, $password);
            if ($identidad) {
                $pdo = db();
                $apiUserId = (string) $identidad['api_user_id'];
                $aliases = array_values(array_unique(array_filter(
                    array_map('strval', (array) ($identidad['aliases'] ?? [])),
                    static fn($alias) => $alias !== '' && mb_strlen($alias, 'UTF-8') <= 50
                )));
                $where = ['api_user_id=?'];
                $params = [$apiUserId];
                if ($aliases) {
                    $where[] = 'usuario IN (' . implode(',', array_fill(0, count($aliases), '?')) . ')';
                    array_push($params, ...$aliases);
                }
                $pdo->beginTransaction();
                try {
                    $st = $pdo->prepare('SELECT id,usuario,nombre,rol,activo,api_user_id FROM usuarios WHERE '
                        . implode(' OR ', $where) . ' FOR UPDATE');
                    $st->execute($params);
                    $users = $st->fetchAll();
                    foreach ($users as $existing) {
                        if ($existing['api_user_id'] !== null && !hash_equals((string) $existing['api_user_id'], $apiUserId)) {
                            throw new RuntimeException('El identificador de acceso coincide con otra cuenta local.');
                        }
                    }

                    $u = null;
                    foreach ($users as $existing) {
                        if ($existing['api_user_id'] !== null && hash_equals((string) $existing['api_user_id'], $apiUserId)) {
                            $u = $existing;
                            break;
                        }
                    }
                    $u ??= $users[0] ?? null;
                    if ($u) {
                        $otherNames = [];
                        $isAdmin = $u['rol'] === 'admin';
                        $isActive = (int) $u['activo'] === 1;
                        $adminIds = array_map(
                            static fn($v) => mb_strtolower(trim((string) $v), 'UTF-8'),
                            (array) cfg('admins', [])
                        );
                        foreach ($aliases as $alias) {
                            $isAdmin = $isAdmin || in_array(mb_strtolower($alias, 'UTF-8'), $adminIds, true);
                        }
                        foreach ($users as $existing) {
                            if ((int) $existing['id'] === (int) $u['id']) {
                                continue;
                            }
                            $otherNames[] = $existing['usuario'];
                            $isAdmin = $isAdmin || $existing['rol'] === 'admin';
                            $isActive = $isActive && (int) $existing['activo'] === 1;
                        }
                        foreach (['copias.revisado_por', 'notas.usuario', 'api_tokens.creado_por'] as $reference) {
                            [$table, $column] = explode('.', $reference, 2);
                            if ($otherNames) {
                                $st = $pdo->prepare("UPDATE `$table` SET `$column`=? WHERE `$column` IN ("
                                    . implode(',', array_fill(0, count($otherNames), '?')) . ')');
                                $st->execute(array_merge([$u['usuario']], $otherNames));
                            }
                        }
                        if ($otherNames) {
                            $st = $pdo->prepare('DELETE FROM usuarios WHERE id IN ('
                                . implode(',', array_fill(0, count($users) - 1, '?')) . ')');
                            $st->execute(array_values(array_map(
                                static fn($existing) => (int) $existing['id'],
                                array_filter($users, static fn($existing) => (int) $existing['id'] !== (int) $u['id'])
                            )));
                        }
                        $role = $isAdmin ? 'admin' : 'usuario';
                        $active = $isActive ? 1 : 0;
                        $st = $pdo->prepare('UPDATE usuarios SET api_user_id=?,nombre=?,rol=?,activo=? WHERE id=?');
                        $st->execute([$apiUserId, $identidad['nombre'], $role, $active, $u['id']]);
                        $username = $u['usuario'];
                    } else {
                        $username = $aliases[0] ?? (string) $identidad['usuario'];
                        $admins = array_map(
                            static fn($v) => mb_strtolower(trim((string) $v), 'UTF-8'),
                            (array) cfg('admins', [])
                        );
                        $isAdmin = in_array(mb_strtolower($username, 'UTF-8'), $admins, true);
                        foreach ($aliases as $alias) {
                            $isAdmin = $isAdmin || in_array(mb_strtolower($alias, 'UTF-8'), $admins, true);
                        }
                        $role = $isAdmin ? 'admin' : 'usuario';
                        $hashLocal = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
                        $st = $pdo->prepare('INSERT INTO usuarios (usuario,api_user_id,nombre,rol,activo,password_hash)
                            VALUES (?,?,?,?,1,?)');
                        $st->execute([$username, $apiUserId, $identidad['nombre'], $role, $hashLocal]);
                    }
                    $st = $pdo->prepare('SELECT id,usuario,nombre,rol,activo FROM usuarios WHERE usuario=?');
                    $st->execute([$username]);
                    $u = $st->fetch();
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
            } else {
                $u = null;
            }
            if ($u && (int) $u['activo'] === 1) {
                session_regenerate_id(true);
                $_SESSION['user'] = ['id' => (int) $u['id'], 'usuario' => $u['usuario'], 'nombre' => $u['nombre'], 'rol' => $u['rol']];
                rate_limit_clear($rateKeys);
                header('Location: index.php');
                exit;
            }
            foreach ($rateKeys as $key) {
                rate_limit_failure($key, 5, 60);
            }
            $error = 'Usuario o contraseña incorrectos.';
        } catch (ApiError $ex) {
            error_log('[copias] autenticación de usuario API: ' . $ex->getMessage());
            $error = 'No se pudo comprobar el acceso con la API. Inténtalo de nuevo más tarde.';
        } catch (Throwable $ex) {
            error_log('[copias] ' . $ex->getMessage());
            $error = cfg('debug') ? $ex->getMessage() : 'No se pudo comprobar el acceso. Inténtalo de nuevo más tarde.';
        }
    }
}
?><!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Acceso · <?= e(cfg('titulo')) ?></title>
    <link rel="stylesheet" href="assets/login.css">
</head>

<body>
    <main class="login">
        <div class="logo"><img src="assets/logo.png" alt="Sauber Ofimática"
                onerror="this.parentNode.style.display='none'"></div>
        <h1><?= e(cfg('titulo')) ?></h1>
        <?php if ($error): ?>
            <div class="errbox"><?= e($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="on">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <label>DNI o correo autorizado <input name="usuario" type="text" inputmode="text" maxlength="150" required
                    autofocus autocomplete="username"></label>
            <label>Contraseña <input name="password" type="password" required autocomplete="current-password"></label>
            <button class="btn" type="submit">Entrar</button>
        </form>
    </main>
</body>

</html>

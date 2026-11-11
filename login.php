<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/api_utilities.php';

if (usuario_actual()) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bloqueo = $_SESSION['bloqueo'] ?? 0;
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        $error = 'Sesión caducada, inténtalo de nuevo.';
    } elseif ($bloqueo > time()) {
        $error = 'Demasiados intentos. Espera un minuto.';
    } else {
        try {
            $identificador = trim((string) ($_POST['usuario'] ?? ''));
            $identidad = api_autenticar_usuario($identificador, (string) ($_POST['password'] ?? ''));
            if ($identidad) {
                $hashLocal = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
                $st = db()->prepare("INSERT INTO usuarios (usuario,nombre,rol,activo,password_hash) VALUES (?,?, 'usuario',1,?)
                    ON DUPLICATE KEY UPDATE nombre=VALUES(nombre)");
                $st->execute([$identidad['usuario'], $identidad['nombre'], $hashLocal]);
                // Administradores fijos (config.php → 'admins'); ya no se gestionan desde el panel.
                $admins = array_map(static fn($v) => mb_strtoupper(trim((string) $v)), (array) cfg('admins', []));
                if (in_array(mb_strtoupper((string) $identidad['usuario']), $admins, true)) {
                    db()->prepare("UPDATE usuarios SET rol='admin' WHERE usuario=?")->execute([$identidad['usuario']]);
                }
                $st = db()->prepare('SELECT id, usuario, nombre, rol, activo FROM usuarios WHERE usuario = ?');
                $st->execute([$identidad['usuario']]);
                $u = $st->fetch();
            } else {
                $u = null;
            }
            if ($u && (int) $u['activo'] === 1) {
                session_regenerate_id(true);
                $_SESSION['user'] = ['id' => (int) $u['id'], 'usuario' => $u['usuario'], 'nombre' => $u['nombre'], 'rol' => $u['rol']];
                unset($_SESSION['intentos'], $_SESSION['bloqueo']);
                header('Location: index.php');
                exit;
            }
            $_SESSION['intentos'] = ($_SESSION['intentos'] ?? 0) + 1;
            if ($_SESSION['intentos'] >= 5) {
                $_SESSION['bloqueo'] = time() + 60;
                $_SESSION['intentos'] = 0;
            }
            sleep(1);
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
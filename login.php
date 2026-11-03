<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

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
            $st = db()->prepare('SELECT id, usuario, nombre, rol, activo, password_hash FROM usuarios WHERE usuario = ?');
            $st->execute([trim((string)($_POST['usuario'] ?? ''))]);
            $u = $st->fetch();
            // Se verifica siempre un hash para no revelar si el usuario existe
            $hash = $u['password_hash'] ?? '$2y$10$usesomesillystringforsalt.invalidhashinvalidhashinvalidha';
            if (password_verify((string)($_POST['password'] ?? ''), $hash) && $u && (int)$u['activo'] === 1) {
                session_regenerate_id(true);
                $_SESSION['user'] = ['id' => (int)$u['id'], 'usuario' => $u['usuario'], 'nombre' => $u['nombre'], 'rol' => $u['rol']];
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
        } catch (Throwable $ex) {
            error_log('[copias] ' . $ex->getMessage());
            $error = cfg('debug') ? $ex->getMessage() : 'Error de conexión con la base de datos.';
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
  <div class="logo"><img src="assets/logo.png" alt="Sauber Ofimática" onerror="this.parentNode.style.display='none'"></div>
  <h1><?= e(cfg('titulo')) ?></h1>
  <?php if ($error): ?><div class="errbox"><?= e($error) ?></div><?php endif; ?>
  <form method="post" autocomplete="on">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <label>Usuario <input name="usuario" required autofocus autocomplete="username"></label>
    <label>Contraseña <input name="password" type="password" required autocomplete="current-password"></label>
    <button class="btn" type="submit">Entrar</button>
  </form>
</main>
</body>
</html>

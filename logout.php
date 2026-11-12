<?php
declare(strict_types=1);

/**
 * Cierra la sesion web y elimina la cookie de sesion del navegador.
 */
require __DIR__ . '/src/bootstrap.php';

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
}
session_destroy();
header('Location: login.php');
exit;

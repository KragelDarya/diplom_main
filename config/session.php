<?php
declare(strict_types=1);

// Подключать ДО любого HTML/echo
if (session_status() === PHP_SESSION_ACTIVE) {
    return;
}

// Важно: не меняй имя сессии, если уже было стандартное PHPSESSID,
// иначе все текущие логины слетят.
// session_name('PHPSESSID');

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

// Эти параметры НЕ МЕНЯЙ, если хочешь сохранить текущие сессии:
// path должен быть '/', домен пустой, lifetime 0.
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();
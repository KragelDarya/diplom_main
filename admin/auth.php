<?php
declare(strict_types=1);
session_start();

function admin_require(): void {
    if (empty($_SESSION['is_admin'])) {
        header('Location: login.php');
        exit;
    }
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_admin'])) {
        $_SESSION['csrf_admin'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf_admin'];
}

function csrf_check(): void {
    $t = $_POST['csrf'] ?? '';
    if (!$t || empty($_SESSION['csrf_admin']) || !hash_equals($_SESSION['csrf_admin'], $t)) {
        http_response_code(403);
        exit('CSRF mismatch');
    }
}

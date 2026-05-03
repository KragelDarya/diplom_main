<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/auth.php';

$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $login = trim((string)($_POST['login'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');

    $ADMIN_LOGIN = 'admin';
    $ADMIN_PASS  = 'admin123';

    if ($login === $ADMIN_LOGIN && $pass === $ADMIN_PASS) {
        $_SESSION['is_admin'] = 1;
        header('Location: patterns.php');
        exit;
    } else {
        $err = 'Неверный логин или пароль';
    }
}

$csrf = csrf_token();
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>Admin Login</title>
  <style>
    body{font-family:Inter,Arial;background:#faf4ff;margin:0;padding:40px}
    .box{max-width:420px;margin:0 auto;background:#fff;border-radius:16px;padding:24px;box-shadow:0 4px 16px rgba(0,0,0,.08)}
    input{width:100%;padding:12px;margin:8px 0;border:2px solid #e5d6ff;border-radius:10px}
    button{width:100%;padding:12px;border:0;border-radius:10px;background:#7b3efc;color:#fff;font-weight:700;cursor:pointer}
    .err{color:#d62828;font-weight:700}
  </style>
</head>
<body>
  <div class="box">
    <h2>Вход в админ-панель</h2>

    <?php if ($err): ?><p class="err"><?=h($err)?></p><?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf" value="<?=h($csrf)?>">
      <input name="login" placeholder="Логин" required>
      <input name="password" type="password" placeholder="Пароль" required>
      <button type="submit">Войти</button>
    </form>
  </div>
</body>
</html>

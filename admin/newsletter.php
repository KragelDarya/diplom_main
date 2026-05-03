<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
admin_require();

require_once __DIR__ . '/../config/db.php';
$db = getDB();

$csrf  = csrf_token();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'send_newsletter')) {
   
    csrf_check();

    $subject = trim((string)($_POST['subject'] ?? ''));
    $message = trim((string)($_POST['message'] ?? ''));

    if ($subject === '' || $message === '') {
        $flash = ['type' => 'error', 'text' => 'Заполни тему и текст сообщения.'];
    } else {
        $emails = $db->query("SELECT email, name FROM user ORDER BY user_id DESC")
            ->fetchAll(PDO::FETCH_ASSOC);

        if (!$emails) {
            $flash = ['type' => 'error', 'text' => 'Пользователей не найдено.'];
        } else {
            require_once __DIR__ . '/../vendor/autoload.php';
            $mailConfig = require __DIR__ . '/../config/mail.php';

            $sent   = 0;
            $failed = 0;
            $errors = [];

            foreach ($emails as $u) {
                $toEmail = trim((string)($u['email'] ?? ''));
                $toName  = (string)($u['name'] ?? '');

                if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                    $failed++;
                    $errors[] = "Неверный email: " . $toEmail;
                    continue;
                }

                try {
                    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                    $mail->CharSet = 'UTF-8';

                    $mail->isSMTP();
                    $mail->Host     = (string)($mailConfig['host'] ?? '');
                    $mail->SMTPAuth = true;
                    $mail->Username = (string)($mailConfig['username'] ?? '');
                    $mail->Password = (string)($mailConfig['password'] ?? '');

                    $port = (int)($mailConfig['port'] ?? 465);
                    $mail->Port = $port;

                    if ($port === 465) {
                        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                    } else {
                        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                    }



                    $mail->setFrom(
                        (string)($mailConfig['from_email'] ?? $mail->Username),
                        (string)($mailConfig['from_name'] ?? 'PixelCraft')
                    );

                    $mail->addAddress($toEmail, $toName);

                    $mail->Subject = $subject;
                    $mail->isHTML(false);
                    $mail->Body = $message;

                    $mail->send();
                    $sent++;
                } catch (Throwable $e) {
                    $failed++;
                    $errors[] = "[" . $toEmail . "] " . $e->getMessage();
                }
            }

            $flash = [
                'type'   => $failed > 0 ? 'error' : 'success',
                'text'   => "Готово! Отправлено: $sent, ошибок: $failed",
                'errors' => $errors,
            ];
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>Admin — Рассылка</title>
  <style>
    body{font-family:Inter,Arial;background:#faf4ff;margin:0;padding:24px}
    .top{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}
    .btn{padding:10px 14px;border-radius:10px;border:0;cursor:pointer;font-weight:700}
    .btn-primary{background:#7b3efc;color:#fff}
    .btn-soft{background:#fff;border:2px solid #e5d6ff;color:#4b1370}
    .card{background:#fff;border-radius:16px;padding:16px;box-shadow:0 4px 16px rgba(0,0,0,.08);max-width:720px}
    input,textarea{width:100%;padding:10px;border-radius:10px;border:2px solid #e5d6ff;box-sizing:border-box}
    textarea{min-height:180px;resize:vertical}
    .muted{color:#7a5e9d}
  </style>
</head>
<body>

<div class="top">
  <h2>Админ-панель: рассылка</h2>
  <div style="display:flex;gap:10px;">
    <a class="btn btn-soft" href="patterns.php" style="text-decoration:none;display:inline-flex;align-items:center;">Схемы</a>
    <a class="btn btn-soft" href="../index.php" style="text-decoration:none;display:inline-flex;align-items:center;">На сайт</a>
    <a class="btn btn-soft" href="logout.php" style="text-decoration:none;display:inline-flex;align-items:center;">Выйти</a>
  </div>
</div>

<?php if ($flash): ?>
  <div class="card" style="<?= ($flash['type'] ?? '') === 'error' ? 'border:2px solid #ffb3b3;' : 'border:2px solid #bff0c9;' ?>">
    <b style="<?= ($flash['type'] ?? '') === 'error' ? 'color:#b10016;' : 'color:#117a2a;' ?>">
      <?= h((string)$flash['text']) ?>
    </b>

    <?php if (!empty($flash['errors']) && is_array($flash['errors'])): ?>
      <div style="margin-top:10px;color:#b10016;">
        <b>Причины ошибок (первые 3):</b><br>
        <?php foreach (array_slice($flash['errors'], 0, 3) as $err): ?>
          <?= h((string)$err) ?><br>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <div style="height:12px;"></div>
<?php endif; ?>

<div class="card">
  <div class="muted" style="margin-bottom:10px;">
    Рассылка отправит письмо всем email из таблицы <b>user</b>.
  </div>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="send_newsletter">

    <label class="muted" for="subject">Тема</label>
    <input id="subject" name="subject" required placeholder="Например: Обновление PixelCraft">

    <div style="height:10px;"></div>

    <label class="muted" for="message">Текст сообщения</label>
    <textarea id="message" name="message" required placeholder="Введите текст..."></textarea>

    <div style="height:14px;"></div>

    <button class="btn btn-primary" type="submit" onclick="return confirm('Отправить письмо всем пользователям?');">
      Отправить всем
    </button>
  </form>
</div>

</body>
</html>
<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
admin_require();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$db = getDB();

/**
 * Берем пользователей и считаем:
 * - collections_count: сколько коллекций у пользователя
 * - patterns_count: сколько УНИКАЛЬНЫХ схем в его коллекциях (DISTINCT pattern_id)
 *
 * Если у пользователя 2 коллекции и в обе добавлена одна и та же схема,
 * в patterns_count она будет посчитана 1 раз (это обычно логичнее).
 */
$rows = $db->query("
    SELECT
        u.user_id,
        u.name,
        u.email,
        COUNT(DISTINCT c.collection_id) AS collections_count,
        COUNT(DISTINCT cp.pattern_id)   AS patterns_count
    FROM user u
    LEFT JOIN collection c ON c.user_id = u.user_id
    LEFT JOIN collection_pattern cp ON cp.collection_id = c.collection_id
    GROUP BY u.user_id, u.name, u.email
    ORDER BY patterns_count DESC, u.user_id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$totalUsers = count($rows);
$totalPatternsSum = 0;
foreach ($rows as $r) {
    $totalPatternsSum += (int)$r['patterns_count'];
}

$generatedAt = date('Y-m-d H:i:s');

/* ================= PDF режим ================= */

if (isset($_GET['pdf'])) {

    // Формируем HTML для PDF отдельно (без кнопок/стилей страницы)
    ob_start();
    ?>
    <h1>Отчет по коллекциям пользователей</h1>
    <p>Сформировано: <?= htmlspecialchars($generatedAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>

    <p><b>Пользователей:</b> <?= (int)$totalUsers ?></p>
    <p><b>Сумма схем в коллекциях (по пользователям):</b> <?= (int)$totalPatternsSum ?></p>
    <hr>

    <?php if (!$rows): ?>
        <p>Нет данных.</p>
    <?php else: ?>
        <table width="100%" cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;">
            <thead>
                <tr style="background:#f2f2f2;">
                    <th align="left">ID</th>
                    <th align="left">Пользователь</th>
                    <th align="left">Email</th>
                    <th align="right">Коллекций</th>
                    <th align="right">Схем в коллекции</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= (int)$r['user_id'] ?></td>
                        <td><?= htmlspecialchars((string)$r['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$r['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                        <td align="right"><?= (int)$r['collections_count'] ?></td>
                        <td align="right"><b><?= (int)$r['patterns_count'] ?></b></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php
    $html = ob_get_clean();

    $options = new Options();
    $options->set('defaultFont', 'DejaVu Sans'); // чтобы русский точно отображался

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="users_collections_report_' . date('Y-m-d') . '.pdf"');
    echo $dompdf->output();
    exit;
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>Отчет по коллекциям пользователей</title>
  <style>
    body{font-family:Inter,Arial,sans-serif;background:#ffffff;margin:0;padding:24px;color:#111}
    h1{margin:0 0 6px 0}
    .meta{color:#555;margin-bottom:18px}
    .summary{background:#f6f1ff;border:1px solid #e5d6ff;border-radius:12px;padding:12px;margin:16px 0}
    .toolbar{margin-top:10px}
    .btn{display:inline-block;padding:10px 14px;border-radius:10px;border:1px solid #e5d6ff;background:#f6f1ff;color:#4b1370;text-decoration:none;font-weight:700}
    .btn + .btn{margin-left:8px}
    table{width:100%;border-collapse:collapse;margin-top:12px}
    th,td{border:1px solid #eee;padding:10px 12px;text-align:left}
    th{background:#faf4ff}
    .num{text-align:right;white-space:nowrap}
    .muted{color:#555}
  </style>
</head>
<body>

<h1>Отчет по коллекциям пользователей</h1>
<div class="meta">
  Сформировано: <b><?= htmlspecialchars($generatedAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></b>

  <div class="toolbar">
    <a class="btn" href="patterns.php">Назад</a>
    <a class="btn" href="report2.php?pdf=1">Скачать PDF</a>
  </div>
</div>

<div class="summary">
  <div><b>Пользователей:</b> <?= (int)$totalUsers ?></div>
  <div><b>Сумма схем в коллекциях (по пользователям):</b> <?= (int)$totalPatternsSum ?></div>
  <div class="muted">Количество схем у пользователя считается как <b>уникальные</b> схемы (DISTINCT) во всех его коллекциях.</div>
</div>

<?php if (!$rows): ?>
  <div class="muted">Нет данных.</div>
<?php else: ?>
  <table>
    <thead>
      <tr>
        <th style="width:90px;">ID</th>
        <th>Пользователь</th>
        <th>Email</th>
        <th class="num" style="width:140px;">Коллекций</th>
        <th class="num" style="width:190px;">Схем в коллекции</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= (int)$r['user_id'] ?></td>
          <td><?= htmlspecialchars((string)$r['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
          <td><?= htmlspecialchars((string)$r['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
          <td class="num"><?= (int)$r['collections_count'] ?></td>
          <td class="num"><b><?= (int)$r['patterns_count'] ?></b></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

</body>
</html>
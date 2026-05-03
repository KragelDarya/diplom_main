<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
admin_require();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$db = getDB();

// Категории + схемы
$rows = $db->query("
    SELECT
        c.category_id,
        c.category_name,
        p.pattern_id,
        p.title,
        p.is_active
    FROM category c
    LEFT JOIN pattern p ON p.category_id = c.category_id
    ORDER BY c.category_name ASC, p.title ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Схемы без категории
$uncat = $db->query("
    SELECT pattern_id, title, is_active
    FROM pattern
    WHERE category_id IS NULL
    ORDER BY title ASC
")->fetchAll(PDO::FETCH_ASSOC);

$byCat = [];
foreach ($rows as $r) {
    $cid = (int)$r['category_id'];
    $cname = (string)$r['category_name'];

    if (!isset($byCat[$cid])) {
        $byCat[$cid] = [
            'name' => $cname,
            'items' => [],
        ];
    }

    if (!empty($r['pattern_id'])) {
        $byCat[$cid]['items'][] = [
            'id' => (int)$r['pattern_id'],
            'title' => (string)$r['title'],
            'is_active' => (int)$r['is_active'] === 1,
        ];
    }
}

$totalPatterns = 0;
foreach ($byCat as $c) {
    $totalPatterns += count($c['items']);
}
$totalPatterns += count($uncat);

$generatedAt = date('Y-m-d H:i:s');

/* ================= PDF режим ================= */

if (isset($_GET['pdf'])) {

    $html = ob_get_clean(); // очистим буфер если есть

    ob_start();
    ?>
    <h1>Отчет по схемам</h1>
    <p>Сформировано: <?= htmlspecialchars($generatedAt) ?></p>
    <p><b>Итого схем:</b> <?= (int)$totalPatterns ?></p>
    <hr>

    <?php foreach ($byCat as $cat): ?>
        <h2><?= htmlspecialchars($cat['name']) ?> (<?= count($cat['items']) ?>)</h2>
        <?php if (!$cat['items']): ?>
            <p>Нет схем</p>
        <?php else: ?>
            <ul>
                <?php foreach ($cat['items'] as $it): ?>
                    <li>
                        <?= htmlspecialchars($it['title']) ?>
                        — <?= $it['is_active'] ? 'активна' : 'выкл' ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endforeach; ?>

    <h2>Без категории (<?= count($uncat) ?>)</h2>
    <?php if (!$uncat): ?>
        <p>Нет схем</p>
    <?php else: ?>
        <ul>
            <?php foreach ($uncat as $it): ?>
                <li>
                    <?= htmlspecialchars($it['title']) ?>
                    — <?= (int)$it['is_active'] === 1 ? 'активна' : 'выкл' ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php
    $html = ob_get_clean();

    $options = new Options();
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="patterns_report_' . date('Y-m-d') . '.pdf"');
    echo $dompdf->output();
    exit;
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>Отчет по схемам</title>
  <style>
    body{font-family:Inter,Arial,sans-serif;background:#ffffff;margin:0;padding:24px;color:#111}
    h1{margin:0 0 6px 0}
    .meta{color:#555;margin-bottom:18px}
    .summary{background:#f6f1ff;border:1px solid #e5d6ff;border-radius:12px;padding:12px;margin:16px 0}
    .cat{margin:18px 0;padding:14px;border:1px solid #eee;border-radius:12px}
    .cat h2{margin:0 0 10px 0;font-size:18px}
    ul{margin:0;padding-left:18px}
    li{margin:4px 0}
    .badge{display:inline-block;font-size:12px;font-weight:700;padding:2px 8px;border-radius:999px;margin-left:8px}
    .on{background:#e9fff0;color:#117a2a}
    .off{background:#ffeaea;color:#b10016}
    .toolbar{margin-top:10px}
    .btn{display:inline-block;padding:10px 14px;border-radius:10px;border:1px solid #e5d6ff;background:#f6f1ff;color:#4b1370;text-decoration:none;font-weight:700}
    .btn + .btn{margin-left:8px}
    .muted{color:#555}
  </style>
</head>
<body>

<h1>Отчет по схемам</h1>
<div class="meta">
  Сформировано: <b><?= htmlspecialchars($generatedAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></b>

  <div class="toolbar">
    <a class="btn" href="patterns.php">Назад</a>
    <a class="btn" href="report.php?pdf=1">Скачать PDF</a>
  </div>
</div>

<div class="summary">
  <div><b>Итого схем:</b> <?= (int)$totalPatterns ?></div>
  <div class="muted">В отчете указано, какие схемы находятся в каждой категории.</div>
</div>

<?php foreach ($byCat as $cat): ?>
  <div class="cat">
    <h2>
      <?= htmlspecialchars($cat['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
      <span class="muted">(<?= count($cat['items']) ?>)</span>
    </h2>

    <?php if (!$cat['items']): ?>
      <div class="muted">В этой категории пока нет схем.</div>
    <?php else: ?>
      <ul>
        <?php foreach ($cat['items'] as $it): ?>
          <li>
            <?= htmlspecialchars($it['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            <?php if ($it['is_active']): ?>
              <span class="badge on">активна</span>
            <?php else: ?>
              <span class="badge off">выкл</span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<div class="cat">
  <h2>Без категории <span class="muted">(<?= count($uncat) ?>)</span></h2>

  <?php if (!$uncat): ?>
    <div class="muted">Нет схем без категории.</div>
  <?php else: ?>
    <ul>
      <?php foreach ($uncat as $it): ?>
        <li>
          <?= htmlspecialchars((string)$it['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          <?php if ((int)$it['is_active'] === 1): ?>
            <span class="badge on">активна</span>
          <?php else: ?>
            <span class="badge off">выкл</span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

</body>
</html>
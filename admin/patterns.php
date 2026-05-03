<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
admin_require();

require_once __DIR__ . '/../config/db.php';
$db = getDB();



function difficultyLabel(string $d): string {
    return match($d) {
        'beginner' => 'Легкий',
        'intermediate' => 'Средний',
        'advanced' => 'Сложный',
        default => $d
    };
}

$csrf = csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'toggle_active') {
        $pid = (int)($_POST['pattern_id'] ?? 0);
        $stmt = $db->prepare("UPDATE pattern SET is_active = IF(is_active=1,0,1) WHERE pattern_id=:id");
        $stmt->execute([':id' => $pid]);
        header('Location: patterns.php');
        exit;
    }

    if ($action === 'delete') {
        $pid = (int)($_POST['pattern_id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM pattern WHERE pattern_id=:id");
        $stmt->execute([':id' => $pid]);
        header('Location: patterns.php');
        exit;
    }

    if ($action === 'save') {
        $pid = (int)($_POST['pattern_id'] ?? 0);

        $title = trim((string)($_POST['title'] ?? ''));
        $category_id = (int)($_POST['category_id'] ?? 0);
        $difficulty = (string)($_POST['difficulty'] ?? 'beginner');
        $width = (int)($_POST['width'] ?? 0);
        $height = (int)($_POST['height'] ?? 0);
        $color_count = (int)($_POST['color_count'] ?? 0);
        $tags = trim((string)($_POST['tags'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $is_active = (int)($_POST['is_active'] ?? 1);

        if ($title === '' || $width <= 0 || $height <= 0 || $color_count < 0) {
            header('Location: patterns.php?err=Введите корректные данные');
            exit;
        }

        $newImagePath = null;

        if (!empty($_FILES['image']) && is_array($_FILES['image'])) {
            $upErr = (int)($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE);

            if ($upErr !== UPLOAD_ERR_NO_FILE) {
                if ($upErr !== UPLOAD_ERR_OK) {
                    header('Location: patterns.php?err=Ошибка загрузки изображения');
                    exit;
                }

                $uploadDir = __DIR__ . '/../images/';
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0777, true);
                }

                $origName = (string)($_FILES['image']['name'] ?? '');
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                $allowed = ['png', 'jpg', 'jpeg', 'webp'];

                if (!in_array($ext, $allowed, true)) {
                    header('Location: patterns.php?err=Недопустимый формат (png/jpg/jpeg/webp)');
                    exit;
                }

                $tmp = (string)($_FILES['image']['tmp_name'] ?? '');
                if ($tmp === '' || @getimagesize($tmp) === false) {
                    header('Location: patterns.php?err=Файл не похож на изображение');
                    exit;
                }

                $fileName = 'pattern_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $dest = $uploadDir . $fileName;

                if (!move_uploaded_file($tmp, $dest)) {
                    header('Location: patterns.php?err=Не удалось сохранить файл');
                    exit;
                }

                $newImagePath = 'images/' . $fileName; 
            }
        }


        $finalImagePath = $newImagePath;
        if ($pid > 0 && $finalImagePath === null) {
            $st = $db->prepare("SELECT image_path FROM pattern WHERE pattern_id = :id LIMIT 1");
            $st->execute([':id' => $pid]);
            $old = $st->fetchColumn();
            $finalImagePath = ($old !== false && $old !== '') ? (string)$old : null;
        }

        if ($pid > 0) {
            $stmt = $db->prepare("
                UPDATE pattern
                SET title=:title,
                    image_path=:image_path,
                    category_id=:category_id,
                    difficulty=:difficulty,
                    width=:width,
                    height=:height,
                    color_count=:color_count,
                    tags=:tags,
                    description=:description,
                    is_active=:is_active
                WHERE pattern_id=:id
            ");
            $stmt->execute([
                ':title'       => $title,
                ':image_path'  => ($finalImagePath !== '' ? $finalImagePath : null),
                ':category_id' => ($category_id > 0 ? $category_id : null),
                ':difficulty'  => $difficulty,
                ':width'       => $width,
                ':height'      => $height,
                ':color_count' => $color_count,
                ':tags'        => ($tags !== '' ? $tags : null),
                ':description' => ($description !== '' ? $description : null),
                ':is_active'   => $is_active ? 1 : 0,
                ':id'          => $pid
            ]);
        } else {
            $stmt = $db->prepare("
                INSERT INTO pattern
                    (title, image_path, category_id, difficulty, width, height, total_pixels, color_count, tags, description, is_active)
                VALUES
                    (:title, :image_path, :category_id, :difficulty, :width, :height, :total_pixels, :color_count, :tags, :description, :is_active)
            ");
            $stmt->execute([
                ':title'        => $title,
                ':image_path'   => ($finalImagePath !== '' ? $finalImagePath : null),
                ':category_id'  => ($category_id > 0 ? $category_id : null),
                ':difficulty'   => $difficulty,
                ':width'        => $width,
                ':height'       => $height,
                ':total_pixels' => $width * $height, 
                ':color_count'  => $color_count,
                ':tags'         => ($tags !== '' ? $tags : null),
                ':description'  => ($description !== '' ? $description : null),
                ':is_active'    => $is_active ? 1 : 0,
            ]);
        }

        header('Location: patterns.php');
        exit;
    }
}

$err = (string)($_GET['err'] ?? '');

$cats = $db->query("SELECT category_id, category_name FROM category ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC);

$patterns = $db->query("
    SELECT p.*, c.category_name
    FROM pattern p
    LEFT JOIN category c ON c.category_id = p.category_id
    ORDER BY p.created_at DESC, p.pattern_id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$editId = (int)($_GET['edit'] ?? 0);
$edit = null;
if ($editId > 0) {
    $st = $db->prepare("SELECT * FROM pattern WHERE pattern_id=:id");
    $st->execute([':id' => $editId]);
    $edit = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>Admin — Схемы</title>
  <style>
    body{font-family:Inter,Arial;background:#faf4ff;margin:0;padding:24px}
    .top{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}
    .btn{padding:10px 14px;border-radius:10px;border:0;cursor:pointer;font-weight:700}
    .btn-primary{background:#7b3efc;color:#fff}
    .btn-soft{background:#fff;border:2px solid #e5d6ff;color:#4b1370}
    .btn-danger{background:#ff4757;color:#fff}
    .grid{display:grid;grid-template-columns: 420px 1fr;gap:18px}
    .card{background:#fff;border-radius:16px;padding:16px;box-shadow:0 4px 16px rgba(0,0,0,.08)}
    input,select,textarea{width:100%;padding:10px;border-radius:10px;border:2px solid #e5d6ff;box-sizing:border-box}
    textarea{min-height:90px;resize:vertical}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid #f0eaff;text-align:left;vertical-align:top}
    .badge{display:inline-block;padding:4px 10px;border-radius:999px;font-weight:700;font-size:12px}
    .on{background:#e9fff0;color:#117a2a}
    .off{background:#ffeaea;color:#b10016}
    .muted{color:#7a5e9d}
    .row-actions{display:flex;gap:8px;flex-wrap:wrap}
    .small{font-size:12px}
  </style>
</head>
<body>

<div class="top">
  <h2>Админ-панель: схемы</h2>
  <div style="display:flex;gap:10px;">
    <a class="btn btn-soft" href="report.php" style="text-decoration:none;display:inline-flex;align-items:center;">Отчет по категориям</a>
    <a class="btn btn-soft" href="report2.php" style="text-decoration:none;display:inline-flex;align-items:center;">Отчет по коллекциям</a>
    <a class="btn btn-soft" href="newsletter.php" style="text-decoration:none;display:inline-flex;align-items:center;">Рассылка</a>
    <a class="btn btn-soft" href="../index.php" style="text-decoration:none;display:inline-flex;align-items:center;">На сайт</a>
    <a class="btn btn-danger" href="logout.php" style="text-decoration:none;display:inline-flex;align-items:center;">Выйти</a>
  </div>
</div>

<?php if ($err): ?>
  <div class="card" style="border:2px solid #ffb3b3;">
    <b style="color:#b10016;"><?=h($err)?></b>
  </div>
  <div style="height:10px;"></div>
<?php endif; ?>

<div class="grid">


  <div class="card">
    <h3><?= $edit ? 'Редактировать схему' : 'Добавить новую схему' ?></h3>


    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?=h($csrf)?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="pattern_id" value="<?= (int)($edit['pattern_id'] ?? 0) ?>">

      <label class="small muted">Название</label>
      <input name="title" required value="<?=h((string)($edit['title'] ?? ''))?>">

      <div style="height:10px;"></div>


      <label class="small muted">Изображение схемы (png/jpg/jpeg/webp)</label>
      <input type="file" name="image" accept=".png,.jpg,.jpeg,.webp">

      <?php if (!empty($edit['image_path'])): ?>
        <div style="margin-top:10px;">
          <div class="small muted">Текущее:</div>
          <img src="/<?= h((string)$edit['image_path']) ?>" style="max-width:220px;border-radius:12px;">
          <div class="small muted" style="margin-top:6px;"><?= h((string)$edit['image_path']) ?></div>
        </div>
      <?php endif; ?>

      <div style="height:10px;"></div>

      <label class="small muted">Категория</label>
      <select name="category_id">
        <option value="0">— без категории —</option>
        <?php foreach ($cats as $c): ?>
          <option value="<?= (int)$c['category_id'] ?>"
            <?= (int)($edit['category_id'] ?? 0) === (int)$c['category_id'] ? 'selected' : '' ?>>
            <?=h((string)$c['category_name'])?>
          </option>
        <?php endforeach; ?>
      </select>

      <div style="height:10px;"></div>

      <label class="small muted">Сложность</label>
      <select name="difficulty">
        <?php
          $curD = (string)($edit['difficulty'] ?? 'beginner');
          foreach (['beginner','intermediate','advanced'] as $d):
        ?>
          <option value="<?=$d?>" <?= $curD===$d?'selected':'' ?>><?=h(difficultyLabel($d))?></option>
        <?php endforeach; ?>
      </select>

      <div style="height:10px;"></div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div>
          <label class="small muted">Ширина</label>
          <input type="number" name="width" min="1" required value="<?=h((string)($edit['width'] ?? ''))?>">
        </div>
        <div>
          <label class="small muted">Высота</label>
          <input type="number" name="height" min="1" required value="<?=h((string)($edit['height'] ?? ''))?>">
        </div>
      </div>

      <div style="height:10px;"></div>

      <label class="small muted">Количество цветов</label>
      <input type="number" name="color_count" min="0" required value="<?=h((string)($edit['color_count'] ?? 0))?>">

      <div style="height:10px;"></div>

      <label class="small muted">Теги (через запятую или JSON)</label>
      <input name="tags" value="<?=h((string)($edit['tags'] ?? ''))?>">

      <div style="height:10px;"></div>

      <label class="small muted">Описание</label>
      <textarea name="description"><?=h((string)($edit['description'] ?? ''))?></textarea>

      <div style="height:10px;"></div>

      <label class="small muted">Статус</label>
      <select name="is_active">
        <?php $curA = (int)($edit['is_active'] ?? 1); ?>
        <option value="1" <?= $curA===1?'selected':'' ?>>Показывать на сайте</option>
        <option value="0" <?= $curA===0?'selected':'' ?>>Скрыть с сайта</option>
      </select>

      <div style="height:14px;"></div>

      <button class="btn btn-primary" type="submit">
        <?= $edit ? 'Сохранить' : 'Добавить' ?>
      </button>

      <?php if ($edit): ?>
        <a class="btn btn-soft" href="patterns.php" style="text-decoration:none;display:inline-flex;align-items:center;margin-left:10px;">
          Отмена
        </a>
      <?php endif; ?>
    </form>
  </div>

  <!-- LIST -->
  <div class="card">
    <h3>Все схемы (<?=count($patterns)?>)</h3>

    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Название</th>
          <th>Категория</th>
          <th>Параметры</th>
          <th>Статус</th>
          <th>Действия</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($patterns as $p): ?>
        <tr>
          <td><?= (int)$p['pattern_id'] ?></td>
          <td>
            <b><?=h((string)$p['title'])?></b><br>
            <span class="muted small"><?=h((string)($p['image_path'] ?? ''))?></span>
          </td>
          <td><?=h((string)($p['category_name'] ?? '—'))?></td>
          <td class="small">
            <?=h(difficultyLabel((string)$p['difficulty']))?> •
            <?= (int)$p['width'] ?>×<?= (int)$p['height'] ?> •
            🎨 <?= (int)$p['color_count'] ?>
          </td>
          <td>
            <?php if ((int)$p['is_active'] === 1): ?>
              <span class="badge on">АКТИВНА</span>
            <?php else: ?>
              <span class="badge off">СКРЫТА</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="row-actions">
              <a class="btn btn-soft" href="patterns.php?edit=<?= (int)$p['pattern_id'] ?>" style="text-decoration:none;">Редактировать</a>

              <form method="post" style="margin:0;">
                <input type="hidden" name="csrf" value="<?=h($csrf)?>">
                <input type="hidden" name="action" value="toggle_active">
                <input type="hidden" name="pattern_id" value="<?= (int)$p['pattern_id'] ?>">
                <button class="btn btn-soft" type="submit">
                  <?= (int)$p['is_active']===1 ? 'Скрыть' : 'Показать' ?>
                </button>
              </form>

              <form method="post" style="margin:0;" onsubmit="return confirm('Точно удалить схему?');">
                <input type="hidden" name="csrf" value="<?=h($csrf)?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="pattern_id" value="<?= (int)$p['pattern_id'] ?>">
                <button class="btn btn-danger" type="submit">Удалить</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</div>

</body>
</html>

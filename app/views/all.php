<?php
declare(strict_types=1);
// session_start();

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/db.php';
$db = getDB();

const ITEMS_PER_PAGE = 12;

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function redirect(string $url): void { header("Location: $url"); exit; }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_check(): void {
    $t = (string)($_POST['csrf'] ?? '');
    if (!$t || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) {
        http_response_code(403);
        exit('CSRF token mismatch');
    }
}

function difficultyLabel(string $d): string {
    return match($d) {
        'beginner' => 'Легкий',
        'intermediate' => 'Средний',
        'advanced' => 'Сложный',
        default => $d
    };
}
function difficultyTagClass(string $d): string {
    // под твой style.css (tag easy/medium/hard)
    return match($d) {
        'beginner' => 'easy',
        'intermediate' => 'medium',
        'advanced' => 'hard',
        default => 'medium'
    };
}

/**
 * Нормализация путей картинок из БД.
 * В БД может быть "../images/xxx.png", а на странице в корне нужно "/images/xxx.png".
 */
function normalize_image_path(?string $path): string {
    if (!$path) return '/images/default-scheme.png';
    $p = trim($path);

    if (str_starts_with($p, '../images/')) {
        return '/images/' . substr($p, strlen('../images/'));
    }
    if (str_starts_with($p, 'images/')) {
        return '/' . $p;
    }
    if (str_starts_with($p, '/images/')) {
        return $p;
    }
    if (!str_contains($p, '/')) {
        return '/images/' . $p;
    }
    return $p;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$csrf = csrf_token();

/* =========================
   POST: ADD / REMOVE COLLECTION
========================= */
$flash = null;

if ($userId && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if (in_array($action, ['add_to_collection', 'remove_from_collection'], true)) {
        csrf_check();

        $patternId = (int)($_POST['pattern_id'] ?? 0);
        if ($patternId <= 0) {
            $flash = ['type' => 'error', 'text' => 'Некорректный pattern_id'];
        } else {

            // доп. безопасность: схема должна существовать и быть активной
            $chk = $db->prepare("SELECT 1 FROM pattern WHERE pattern_id = :pid AND is_active = 1 LIMIT 1");
            $chk->execute([':pid' => $patternId]);
            if (!$chk->fetchColumn()) {
                $flash = ['type' => 'error', 'text' => 'Схема скрыта или не найдена'];
            } else {

                if ($action === 'add_to_collection') {
                    // гарантируем коллекцию
                    $stmt = $db->prepare("SELECT collection_id FROM collection WHERE user_id = :uid ORDER BY created_at DESC LIMIT 1");
                    $stmt->execute([':uid' => $userId]);
                    $collectionId = (int)($stmt->fetchColumn() ?: 0);

                    if ($collectionId === 0) {
                        $ins = $db->prepare("INSERT INTO collection (user_id, name, description) VALUES (:uid, :name, :desc)");
                        $ins->execute([':uid' => $userId, ':name' => 'Моя коллекция', ':desc' => '']);
                        $collectionId = (int)$db->lastInsertId();
                    }

                    $ins = $db->prepare("
                        INSERT INTO collection_pattern (collection_id, pattern_id, added_at, is_favorite, notes)
                        VALUES (:cid, :pid, NOW(), 0, NULL)
                        ON DUPLICATE KEY UPDATE added_at = added_at
                    ");
                    $ins->execute([':cid' => $collectionId, ':pid' => $patternId]);

                    $flash = ['type' => 'success', 'text' => 'Схема добавлена в коллекцию'];
                }

                if ($action === 'remove_from_collection') {
                    // удаляем схему из ЛЮБОЙ коллекции этого пользователя (надежнее)
                    $del = $db->prepare("
                        DELETE cp
                        FROM collection_pattern cp
                        JOIN collection c ON c.collection_id = cp.collection_id
                        WHERE c.user_id = :uid
                          AND cp.pattern_id = :pid
                    ");
                    $del->execute([':uid' => $userId, ':pid' => $patternId]);

                    $flash = ['type' => 'success', 'text' => 'Схема удалена из коллекции'];
                }
            }
        }

        $back = (string)($_POST['back'] ?? 'all.php');
        redirect($back);
    }
}

/* =========================
   GET: FILTERS
========================= */
$q = trim((string)($_GET['q'] ?? ''));
$category = trim((string)($_GET['category'] ?? 'Все')); // category_name
$difficulty = trim((string)($_GET['difficulty'] ?? 'Все')); // Легкий/Средний/Сложный/Все
$widthMax = (int)($_GET['width'] ?? 150);
$heightMax = (int)($_GET['height'] ?? 150);
$colorsMax = (int)($_GET['colors'] ?? 50);

$page = max(1, (int)($_GET['page'] ?? 1));

// нормализация
$widthMax = max(0, min(500, $widthMax));
$heightMax = max(0, min(500, $heightMax));
$colorsMax = max(0, min(500, $colorsMax));

$diffDb = match($difficulty) {
    'Легкий' => 'beginner',
    'Средний' => 'intermediate',
    'Сложный' => 'advanced',
    default => ''
};

// категории для select
$cats = $db->query("SELECT category_name FROM category ORDER BY category_name ASC")->fetchAll(PDO::FETCH_COLUMN);

// WHERE
$where = [];
$params = [];

// ✅ ВАЖНО: показываем только активные схемы
$where[] = "p.is_active = 1";

if ($q !== '') {
    $where[] = "(p.title LIKE :q OR p.description LIKE :q OR p.tags LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
if ($category !== '' && $category !== 'Все') {
    $where[] = "cat.category_name = :cat";
    $params[':cat'] = $category;
}
if ($diffDb !== '') {
    $where[] = "p.difficulty = :diff";
    $params[':diff'] = $diffDb;
}
$where[] = "p.width <= :wmax";
$params[':wmax'] = $widthMax;

$where[] = "p.height <= :hmax";
$params[':hmax'] = $heightMax;

$where[] = "p.color_count <= :cmax";
$params[':cmax'] = $colorsMax;

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* =========================
   COUNT
========================= */
$countStmt = $db->prepare("
    SELECT COUNT(*)
    FROM pattern p
    LEFT JOIN category cat ON cat.category_id = p.category_id
    $whereSql
");
$countStmt->execute($params);
$totalItems = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalItems / ITEMS_PER_PAGE));
$page = min($page, $totalPages);
$offset = ($page - 1) * ITEMS_PER_PAGE;

/* =========================
   LIST
========================= */
$listStmt = $db->prepare("
    SELECT
        p.pattern_id, p.title, p.image_path, p.width, p.height, p.total_pixels, p.color_count, p.difficulty,
        cat.category_name
    FROM pattern p
    LEFT JOIN category cat ON cat.category_id = p.category_id
    $whereSql
    ORDER BY p.created_at DESC
    LIMIT :lim OFFSET :off
");
foreach ($params as $k => $v) $listStmt->bindValue($k, $v);
$listStmt->bindValue(':lim', ITEMS_PER_PAGE, PDO::PARAM_INT);
$listStmt->bindValue(':off', $offset, PDO::PARAM_INT);
$listStmt->execute();
$patterns = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// какие схемы уже в коллекции пользователя (чтобы менять кнопку)
$inCollection = [];
if ($userId && $patterns) {
    $ids = array_map(fn($r) => (int)$r['pattern_id'], $patterns);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $sql = "
        SELECT DISTINCT cp.pattern_id
        FROM collection_pattern cp
        JOIN collection c ON c.collection_id = cp.collection_id
        WHERE c.user_id = ?
          AND cp.pattern_id IN ($placeholders)
    ";
    $st = $db->prepare($sql);
    $st->execute(array_merge([$userId], $ids));
    $inCollection = array_fill_keys(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), true);
}

// текущий URL (чтобы возвращаться после POST)
$backUrl = 'all.php?' . http_build_query([
    'q' => $q,
    'category' => $category,
    'difficulty' => $difficulty,
    'width' => $widthMax,
    'height' => $heightMax,
    'colors' => $colorsMax,
    'page' => $page
]);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Все схемы — PixelCraft</title>

    <link rel="stylesheet" href="/assets/css/style.css?v=2">
    <link rel="stylesheet" href="/assets/css/styleall.css?v=2">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>

<body>

<header class="header">
    <a href="/index.php" class="logo-block" style="text-decoration:none; color:inherit;">
        <img src="/assets/images/logo.png" alt="Logo" class="header-logo-img">
        <div class="logo-text">
            <div class="logo-name">PixelCraft</div>
            <div class="logo-desc">Ваш персональный помощник для<br>работы с пиксельными схемами</div>
        </div>
    </a>

    <nav class="menu" id="topMenu">
        <a href="/index.php">Главная</a>
        <a href="/app/views/all.php" class="active-page">Все схемы</a>
        <a href="/app/views/myCollection.php">Моя коллекция</a>
        <a href="#">Создать схему</a>

        <?php if (!$userId): ?>
            <a href="/index.php" class="register-btn-header">Зарегистрироваться</a>
        <?php else: ?>
            <div class="user-info" style="margin-left: 35px;">
                <div class="user-avatar"><?= h(mb_strtoupper(mb_substr((string)($_SESSION['user_name'] ?? 'U'), 0, 1))) ?></div>
                <div class="user-name"><?= h((string)($_SESSION['user_name'] ?? 'User')) ?></div>
                <a class="logout-btn" href="/index.php?api=logout">Выйти</a>
            </div>
        <?php endif; ?>
    </nav>
</header>

<!-- ======== ПОИСК (делаем через GET) ======== -->
<section class="search-block-all">
    <div class="search-container-all">
        <span class="search-icon">🔍</span>
        <form method="get" action="all.php" style="display:flex;flex:1;">
            <input id="searchInput" type="text" name="q" value="<?=h($q)?>" placeholder="Название схемы…">
            <input type="hidden" name="category" value="<?=h($category)?>">
            <input type="hidden" name="difficulty" value="<?=h($difficulty)?>">
            <input type="hidden" name="width" value="<?=$widthMax?>">
            <input type="hidden" name="height" value="<?=$heightMax?>">
            <input type="hidden" name="colors" value="<?=$colorsMax?>">
        </form>
    </div>
</section>

<section class="all-wrapper">

    <aside class="filters">
        <h3>⚙ Фильтры</h3>

        <form id="filtersForm" method="get" action="all.php">
            <label>Категория</label>
            <select id="category-filter" name="category">
                <option value="Все" <?= $category==='Все' ? 'selected' : '' ?>>Все</option>
                <?php foreach ($cats as $cn): ?>
                    <option value="<?=h((string)$cn)?>" <?= $category===(string)$cn ? 'selected' : '' ?>>
                        <?=h((string)$cn)?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label>Размер</label>

            <div class="range-block">
                <div style="display:flex;justify-content:space-between;margin-top:8px;">
                    <b style="color:#4b1770;">Ширина:</b>
                    <b id="widthValue" style="color:#5a0ea6;"><?= (int)$widthMax ?></b>
                </div>
                <div style="display:flex;justify-content:space-between;color:#7a5e9d;font-size:13px;margin:4px 0 6px;">
                    <span id="widthMin">0</span><span id="widthMax">150</span>
                </div>
                <input id="width-filter" name="width" type="range" min="0" max="150" value="<?= (int)$widthMax ?>">

                <div style="display:flex;justify-content:space-between;margin-top:14px;">
                    <b style="color:#4b1770;">Высота:</b>
                    <b id="heightValue" style="color:#5a0ea6;"><?= (int)$heightMax ?></b>
                </div>
                <div style="display:flex;justify-content:space-between;color:#7a5e9d;font-size:13px;margin:4px 0 6px;">
                    <span id="heightMin">0</span><span id="heightMax">150</span>
                </div>
                <input id="height-filter" name="height" type="range" min="0" max="150" value="<?= (int)$heightMax ?>">
            </div>

            <label>Сложность</label>
            <select id="difficulty-filter" name="difficulty">
                <option value="Все" <?= $difficulty==='Все' ? 'selected' : '' ?>>Все</option>
                <option value="Легкий" <?= $difficulty==='Легкий' ? 'selected' : '' ?>>Легкий</option>
                <option value="Средний" <?= $difficulty==='Средний' ? 'selected' : '' ?>>Средний</option>
                <option value="Сложный" <?= $difficulty==='Сложный' ? 'selected' : '' ?>>Сложный</option>
            </select>

            <label>Количество цветов</label>
            <div style="display:flex;justify-content:space-between;margin-top:8px;">
                <b style="color:#4b1770;">Цветов:</b>
                <b id="colorsValue" style="color:#5a0ea6;"><?= (int)$colorsMax ?></b>
            </div>
            <div style="display:flex;justify-content:space-between;color:#7a5e9d;font-size:13px;margin:4px 0 6px;">
                <span id="colorsMin">0</span><span id="colorsMax">50</span>
            </div>
            <input id="colors-filter" name="colors" type="range" min="0" max="50" value="<?= (int)$colorsMax ?>">

            <input type="hidden" name="q" value="<?=h($q)?>">
            <input type="hidden" name="page" value="1">

            <button class="reset-btn" type="button" id="resetBtn">Сбросить фильтры</button>
        </form>
    </aside>

    <main class="all-schemes">

        <div class="found-count">Найдено схем: <?= (int)$totalItems ?></div>

        <?php if ($flash): ?>
            <div style="margin-bottom:14px;padding:12px 14px;border-radius:12px;background:#fff;box-shadow:0 4px 12px rgba(0,0,0,0.05);color:#3c1461;">
                <?= h($flash['text']) ?>
            </div>
        <?php endif; ?>

        <?php if (!$patterns): ?>
            <div style="background:#fff;padding:20px;border-radius:16px;box-shadow:0 4px 12px rgba(0,0,0,0.05);">
                Ничего не найдено. Попробуйте изменить фильтры.
            </div>
        <?php else: ?>

        <div class="cards-grid">
            <?php foreach ($patterns as $p):
                $pid = (int)$p['pattern_id'];
                $title = (string)$p['title'];

                // ✅ нормализуем картинку
                $img = normalize_image_path($p['image_path'] ?? null);

                $w = (int)$p['width'];
                $hgt = (int)$p['height'];
                $totalPixels = (int)$p['total_pixels'];
                $colors = (int)$p['color_count'];
                $diff = (string)$p['difficulty'];
                $diffLabel = difficultyLabel($diff);
                $tagClass = difficultyTagClass($diff);

                $already = $userId ? isset($inCollection[$pid]) : false;
            ?>
            <div class="scheme-card">
                <img src="<?=h($img)?>" alt="<?=h($title)?>">
                <div class="card-content">
                    <div class="card-title-row">
                        <h3><?=h($title)?></h3>
                        <span class="tag <?=h($tagClass)?>"><?=h($diffLabel)?></span>
                    </div>

                    <div class="info-line"><span class="icon">▦</span> <?=$w?>×<?=$hgt?> • <?=$totalPixels?> пикселей</div>
                    <div class="info-line"><span class="icon">🎨</span> <?=$colors?> цветов</div>

                    <div class="buttons">
                        <button class="btn start" onclick="location.href='scheme.php?pattern_id=<?=$pid?>'">Начать</button>

                        <?php if (!$userId): ?>
                            <button class="btn add" onclick="location.href='/index.php'">Добавить в коллекцию</button>
                        <?php else: ?>
                            <form method="post" style="flex:1;">
                                <input type="hidden" name="csrf" value="<?=h($csrf)?>">
                                <input type="hidden" name="action" value="<?= $already ? 'remove_from_collection' : 'add_to_collection' ?>">
                                <input type="hidden" name="pattern_id" value="<?=$pid?>">
                                <input type="hidden" name="back" value="<?=h($backUrl)?>">

                                <button class="btn add <?= $already ? 'added' : '' ?>" type="submit">
                                    <?= $already ? 'Уже в коллекции' : 'Добавить в коллекцию' ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination" style="display:flex;justify-content:center;gap:10px;margin:30px 0;">
                <?php
                for ($i=1; $i<=$totalPages; $i++):
                    $link = 'all.php?' . http_build_query([
                        'q'=>$q,'category'=>$category,'difficulty'=>$difficulty,'width'=>$widthMax,'height'=>$heightMax,'colors'=>$colorsMax,'page'=>$i
                    ]);
                ?>
                    <a class="page-number <?= $i===$page ? 'active' : '' ?>"
                       href="<?=h($link)?>"
                       style="text-decoration:none;<?= $i===$page ? '' : 'background:#fff;border:2px solid #e5d6ff;' ?>">
                        <?=$i?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>

        <?php endif; ?>

    </main>

</section>

<footer class="footer">
    <div class="footer-content">
        <div class="footer-logo">
            <img src="/assets/images/logo.png" alt="PixelCraft Logo" class="footer-img">
            <div class="footer-text">
                <div class="footer-title">PixelCraft</div>
                <div class="footer-sub">
                    Ваш персональный помощник для<br>работы с пиксельными схемами
                </div>
            </div>
        </div>
    </div>
</footer>

<script>
(function () {
  const form = document.getElementById('filtersForm');
  if (!form) return;

  const width = document.getElementById('width-filter');
  const height = document.getElementById('height-filter');
  const colors = document.getElementById('colors-filter');

  const widthValue = document.getElementById('widthValue');
  const heightValue = document.getElementById('heightValue');
  const colorsValue = document.getElementById('colorsValue');

  const set = (el, target) => { if (el && target) target.textContent = el.value; };

  function bindSlider(el, target) {
    if (!el) return;
    el.addEventListener('input', () => set(el, target));
    el.addEventListener('change', () => form.submit());
  }

  bindSlider(width, widthValue);
  bindSlider(height, heightValue);
  bindSlider(colors, colorsValue);

  const cat = document.getElementById('category-filter');
  const diff = document.getElementById('difficulty-filter');
  if (cat) cat.addEventListener('change', () => form.submit());
  if (diff) diff.addEventListener('change', () => form.submit());

  const resetBtn = document.getElementById('resetBtn');
  if (resetBtn) {
    resetBtn.addEventListener('click', () => {
      if (cat) cat.value = 'Все';
      if (diff) diff.value = 'Все';

      if (width) width.value = width.max || 150;
      if (height) height.value = height.max || 150;
      if (colors) colors.value = colors.max || 50;

      set(width, widthValue);
      set(height, heightValue);
      set(colors, colorsValue);

      const q = form.querySelector('input[name="q"]');
      if (q) q.value = '';

      form.submit();
    });
  }
})();
</script>

</body>
</html>

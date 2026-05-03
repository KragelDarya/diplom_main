<?php
declare(strict_types=1);
// session_start();

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/db.php';
$db = getDB();

/* =========================
   НАСТРОЙКИ
========================= */
const ITEMS_PER_PAGE = 9;

/* =========================
   УТИЛИТЫ
========================= */
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function redirect(string $url): void { header("Location: $url"); exit; }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}
function csrf_check(): void {
    $token = $_POST['csrf'] ?? '';
    if (!$token || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
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

/* =========================
   AUTH: LOGIN / LOGOUT
========================= */
$authError = null;

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    redirect('myCollection.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    csrf_check();

    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $authError = 'Введите email и пароль.';
    } else {
        // ВАЖНО: сейчас у тебя пароль хранится в plain text.
        // Позже можно заменить на password_hash()/password_verify().
        $stmt = $db->prepare("SELECT user_id, name, email, password FROM user WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || $user['password'] !== $password) {
            $authError = 'Неверный email или пароль.';
        } else {
            $_SESSION['user_id'] = (int)$user['user_id'];
            $_SESSION['user_name'] = (string)$user['name'];
            $_SESSION['user_email'] = (string)$user['email'];
            $_SESSION['user'] = [
                'user_id' => (int)$user['user_id'],
                'email'   => (string)$user['email'],
                'name'    => (string)$user['name'],
            ];
            redirect('myCollection.php');
        }
        
    }
}

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

/* =========================
   ACTIONS (без JS)
========================= */
if ($userId && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if (in_array($action, ['toggle_favorite', 'save_notes', 'remove_item'], true)) {
        csrf_check();

        $collectionId = (int)($_POST['collection_id'] ?? 0);
        $patternId = (int)($_POST['pattern_id'] ?? 0);

        // проверка: коллекция принадлежит пользователю
        $own = $db->prepare("SELECT collection_id FROM collection WHERE collection_id = :cid AND user_id = :uid LIMIT 1");
        $own->execute([':cid' => $collectionId, ':uid' => $userId]);
        if (!$own->fetchColumn()) {
            http_response_code(403);
            exit('Forbidden: collection does not belong to user');
        }

        if ($action === 'toggle_favorite') {
            $stmt = $db->prepare("
                UPDATE collection_pattern
                SET is_favorite = IF(is_favorite=1, 0, 1)
                WHERE collection_id = :cid AND pattern_id = :pid
            ");
            $stmt->execute([':cid' => $collectionId, ':pid' => $patternId]);
        }

        if ($action === 'save_notes') {
            $notes = (string)($_POST['notes'] ?? '');
            $stmt = $db->prepare("
                UPDATE collection_pattern
                SET notes = :notes
                WHERE collection_id = :cid AND pattern_id = :pid
            ");
            $stmt->execute([':notes' => $notes, ':cid' => $collectionId, ':pid' => $patternId]);
        }

        if ($action === 'remove_item') {
            $stmt = $db->prepare("
                DELETE FROM collection_pattern
                WHERE collection_id = :cid AND pattern_id = :pid
            ");
            $stmt->execute([':cid' => $collectionId, ':pid' => $patternId]);

            // опционально: если хочешь удалять прогресс при удалении из коллекции — раскомментируй
            // $stmt2 = $db->prepare("DELETE FROM progress WHERE user_id = :uid AND pattern_id = :pid");
            // $stmt2->execute([':uid' => $userId, ':pid' => $patternId]);
        }

        // Возвращаемся на страницу с теми же query параметрами
        $back = (string)($_POST['back'] ?? 'myCollection.php');
        redirect($back);
    }
}

/* =========================
   ЕСЛИ НЕ ЗАЛОГИНЕН — ПОКАЗЫВАЕМ ФОРМУ
========================= */
if (!$userId) {
    $csrf = csrf_token();
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Моя коллекция — PixelCraft</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/stylemyCollection.css">
</head>
<body>

<header class="header">
    <div class="logo-block">
        <img src="/assets/images/logo.png" class="header-logo-img" alt="Logo">
        <div class="logo-text">
            <div class="logo-name">PixelCraft</div>
            <div class="logo-desc">Ваш персональный помощник для<br>работы с пиксельными схемами</div>
        </div>
    </div>

    <nav class="menu">
        <a href="../../index.php">Главная</a>
        <a href="all.php">Все схемы</a>
        <a href="#" class="active-page">Моя коллекция</a>
    </nav>
</header>

<section class="collection-container">
    <div class="guest-message">
        <div class="message-content">
            <h2>Войдите, чтобы открыть коллекцию</h2>
            

            <?php if ($authError): ?>
                <p style="color:#d62828; font-weight:600;"><?=h($authError)?></p>
            <?php endif; ?>

            <form method="post" style="max-width:420px;margin:0 auto;text-align:left;">
                <input type="hidden" name="csrf" value="<?=h($csrf)?>">
                <input type="hidden" name="action" value="login">

                <div class="form-group">
                    <label style="display:block;margin:8px 0 6px;color:#4b1370;">Email</label>
                    <input type="email" name="email" required placeholder="example@mail.com">
                </div>

                <div class="form-group">
                    <label style="display:block;margin:8px 0 6px;color:#4b1370;">Пароль</label>
                    <input type="password" name="password" required placeholder="Пароль">
                </div>

                <button class="auth-btn" type="submit" style="width:100%;margin-top:10px;">Войти</button>
            </form>
        </div>
    </div>
</section>

<footer class="footer">
    <div class="footer-content">
        <div class="footer-logo">
            <img src="/assets/images/logo.png" class="footer-img" alt="">
            <div class="footer-text">
                <div class="footer-title">PixelCraft</div>
                <div class="footer-sub">Ваш персональный помощник для<br>работы с пиксельными схемами</div>
            </div>
        </div>
    </div>
</footer>

</body>
</html>
<?php
    exit;
}

/* =========================
   ДАННЫЕ ДЛЯ СТРАНИЦЫ
========================= */
$csrf = csrf_token();

// Список коллекций пользователя
$stmt = $db->prepare("SELECT collection_id, name, description, created_at FROM collection WHERE user_id = :uid ORDER BY created_at DESC");
$stmt->execute([':uid' => $userId]);
$userCollections = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Если у пользователя нет коллекций — создадим одну «Моя коллекция»
if (!$userCollections) {
    $ins = $db->prepare("INSERT INTO collection (user_id, name, description) VALUES (:uid, :name, :desc)");
    $ins->execute([':uid' => $userId, ':name' => 'Моя коллекция', ':desc' => '']);
    $newId = (int)$db->lastInsertId();
    $userCollections = [[
        'collection_id' => $newId,
        'name' => 'Моя коллекция',
        'description' => '',
        'created_at' => date('Y-m-d H:i:s')
    ]];
}

// Выбранная коллекция
$selectedCollectionId = (int)($_GET['collection_id'] ?? $userCollections[0]['collection_id']);

// Проверим, что выбранная коллекция принадлежит пользователю (иначе — первая)
$allowed = array_map(fn($c) => (int)$c['collection_id'], $userCollections);
if (!in_array($selectedCollectionId, $allowed, true)) {
    $selectedCollectionId = (int)$userCollections[0]['collection_id'];
}

// Фильтры (GET)
$q = trim((string)($_GET['q'] ?? ''));
$category = trim((string)($_GET['category'] ?? '')); // category_name
$status = trim((string)($_GET['status'] ?? 'all'));  // all|completed|in-progress|favorites|recent
$sort = trim((string)($_GET['sort'] ?? 'date-desc')); // date-desc|date-asc|name-asc|name-desc|progress-desc|progress-asc
$page = max(1, (int)($_GET['page'] ?? 1));

// Категории (для select)
$cats = $db->query("SELECT category_name FROM category ORDER BY category_name ASC")->fetchAll(PDO::FETCH_COLUMN);

// База WHERE
$where = [];
$params = [
    ':cid' => $selectedCollectionId,
    ':uid' => $userId
];

// Поиск
if ($q !== '') {
    $where[] = "(p.title LIKE :q OR p.description LIKE :q OR p.tags LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}

// Категория
if ($category !== '' && $category !== 'Все') {
    $where[] = "cat.category_name = :cat";
    $params[':cat'] = $category;
}

// Статус
if ($status === 'completed') {
    $where[] = "COALESCE(pr.completion_percentage, 0) >= 100";
} elseif ($status === 'in-progress') {
    $where[] = "COALESCE(pr.completion_percentage, 0) > 0 AND COALESCE(pr.completion_percentage, 0) < 100";
} elseif ($status === 'favorites') {
    $where[] = "cp.is_favorite = 1";
} elseif ($status === 'recent') {
    $where[] = "cp.added_at >= (NOW() - INTERVAL 7 DAY)";
}

$whereSql = $where ? (" AND " . implode(" AND ", $where)) : "";

// Сортировка (white list)
$orderSql = match($sort) {
    'date-asc' => "cp.added_at ASC",
    'name-asc' => "p.title ASC",
    'name-desc' => "p.title DESC",
    'progress-desc' => "COALESCE(pr.completion_percentage, 0) DESC, cp.added_at DESC",
    'progress-asc' => "COALESCE(pr.completion_percentage, 0) ASC, cp.added_at DESC",
    default => "cp.added_at DESC"
};

// Подсчёт total
$countStmt = $db->prepare("
    SELECT COUNT(*)
    FROM collection_pattern cp
    JOIN pattern p ON p.pattern_id = cp.pattern_id
    LEFT JOIN category cat ON cat.category_id = p.category_id
    LEFT JOIN progress pr ON pr.user_id = :uid AND pr.pattern_id = p.pattern_id
    WHERE cp.collection_id = :cid
    $whereSql
");
$countStmt->execute($params);
$totalItems = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalItems / ITEMS_PER_PAGE));
$page = min($page, $totalPages);
$offset = ($page - 1) * ITEMS_PER_PAGE;

// Данные карточек
$listStmt = $db->prepare("
    SELECT
        p.pattern_id, p.title, p.image_path, p.width, p.height, p.total_pixels, p.color_count, p.difficulty, p.tags, p.description,
        cat.category_name,
        cp.added_at, cp.is_favorite, cp.notes,
        COALESCE(pr.completion_percentage, 0) AS completion_percentage,
        COALESCE(pr.pixels_marked, 0) AS pixels_marked
    FROM collection_pattern cp
    JOIN pattern p ON p.pattern_id = cp.pattern_id
    LEFT JOIN category cat ON cat.category_id = p.category_id
    LEFT JOIN progress pr ON pr.user_id = :uid AND pr.pattern_id = p.pattern_id
    WHERE cp.collection_id = :cid
    $whereSql
    ORDER BY $orderSql
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) $listStmt->bindValue($k, $v);
$listStmt->bindValue(':limit', ITEMS_PER_PAGE, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$items = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// Статистика (по всей коллекции без учёта фильтра)
$statsStmt = $db->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN COALESCE(pr.completion_percentage, 0) >= 100 THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN COALESCE(pr.completion_percentage, 0) > 0 AND COALESCE(pr.completion_percentage, 0) < 100 THEN 1 ELSE 0 END) AS in_progress,
        SUM(CASE WHEN cp.is_favorite = 1 THEN 1 ELSE 0 END) AS favorites
    FROM collection_pattern cp
    JOIN pattern p ON p.pattern_id = cp.pattern_id
    LEFT JOIN progress pr ON pr.user_id = :uid AND pr.pattern_id = p.pattern_id
    WHERE cp.collection_id = :cid
");
$statsStmt->execute([':uid' => $userId, ':cid' => $selectedCollectionId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'completed'=>0,'in_progress'=>0,'favorites'=>0];

// back url (чтобы после POST вернуться туда же)
$backUrl = 'myCollection.php?' . http_build_query([
    'collection_id' => $selectedCollectionId,
    'q' => $q,
    'category' => $category,
    'status' => $status,
    'sort' => $sort,
    'page' => $page,
]);

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Моя коллекция — PixelCraft</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/stylemyCollection.css">
</head>
<body>

<header class="header">
    <a class="logo-block" href="/index.php" style="text-decoration:none; color:inherit;">
        <img src="/assets/images/logo.png" class="header-logo-img" alt="Logo">
        <div class="logo-text">
            <div class="logo-name">PixelCraft</div>
            <div class="logo-desc">Ваш персональный помощник для<br>работы с пиксельными схемами</div>
        </div>
    </a>


    <nav class="menu">
        <a href="../../index.php">Главная</a>
        <a href="all.php">Все схемы</a>
        <a href="#" class="active-page">Моя коллекция</a>

        <span style="margin-left:35px; display:inline-flex; align-items:center; gap:10px;">
            <span class="user-avatar"><?=h(mb_strtoupper(mb_substr((string)($_SESSION['user_name'] ?? 'U'), 0, 1)))?></span>
            <span class="user-name"><?=h((string)($_SESSION['user_name'] ?? 'User'))?></span>
            <a class="logout-btn" href="myCollection.php?logout=1" style="text-decoration:none; display:inline-flex; align-items:center;">Выйти</a>
        </span>
    </nav>
</header>

<section class="collection-container">

    <div class="collection-header">
        <h1>Моя коллекция</h1>

        <div class="collection-stats">
            <div class="stat-card">
                <div class="stat-icon">📦</div>
                <div class="stat-info">
                    <div class="stat-number"><?= (int)$stats['total'] ?></div>
                    <div class="stat-label">Всего схем</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">✅</div>
                <div class="stat-info">
                    <div class="stat-number"><?= (int)$stats['completed'] ?></div>
                    <div class="stat-label">Завершено</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">⏳</div>
                <div class="stat-info">
                    <div class="stat-number"><?= (int)$stats['in_progress'] ?></div>
                    <div class="stat-label">В процессе</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">❤️</div>
                <div class="stat-info">
                    <div class="stat-number"><?= (int)$stats['favorites'] ?></div>
                    <div class="stat-label">Избранное</div>
                </div>
            </div>
        </div>

        <form class="collection-controls" method="get" action="myCollection.php">
            <input type="hidden" name="collection_id" value="<?= (int)$selectedCollectionId ?>">

            <div class="search-box">
                <span class="search-icon">🔍</span>
                <input type="text" name="q" value="<?=h($q)?>" placeholder="Поиск по названию/тегам...">
            </div>

            <div class="filter-sort">
                <select name="collection_id" onchange="this.form.submit()">
                    <?php foreach ($userCollections as $col): ?>
                        <option value="<?= (int)$col['collection_id'] ?>" <?= ((int)$col['collection_id'] === $selectedCollectionId ? 'selected' : '') ?>>
                            <?=h($col['name'])?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="category" onchange="this.form.submit()">
                    <option value="Все" <?= ($category==='' || $category==='Все') ? 'selected' : '' ?>>Все категории</option>
                    <?php foreach ($cats as $cn): ?>
                        <option value="<?=h((string)$cn)?>" <?= ($category===(string)$cn) ? 'selected' : '' ?>>
                            <?=h((string)$cn)?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="status" onchange="this.form.submit()">
                    <option value="all" <?= $status==='all' ? 'selected' : '' ?>>Все</option>
                    <option value="completed" <?= $status==='completed' ? 'selected' : '' ?>>Завершено</option>
                    <option value="in-progress" <?= $status==='in-progress' ? 'selected' : '' ?>>В процессе</option>
                    <option value="favorites" <?= $status==='favorites' ? 'selected' : '' ?>>Избранное</option>
                    <option value="recent" <?= $status==='recent' ? 'selected' : '' ?>>Недавние (7 дней)</option>
                </select>

                <select name="sort" onchange="this.form.submit()">
                    <option value="date-desc" <?= $sort==='date-desc' ? 'selected' : '' ?>>Сначала новые</option>
                    <option value="date-asc" <?= $sort==='date-asc' ? 'selected' : '' ?>>Сначала старые</option>
                    <option value="name-asc" <?= $sort==='name-asc' ? 'selected' : '' ?>>Название А→Я</option>
                    <option value="name-desc" <?= $sort==='name-desc' ? 'selected' : '' ?>>Название Я→А</option>
                    <option value="progress-desc" <?= $sort==='progress-desc' ? 'selected' : '' ?>>Прогресс ↓</option>
                    <option value="progress-asc" <?= $sort==='progress-asc' ? 'selected' : '' ?>>Прогресс ↑</option>
                </select>
            </div>

            <div style="display:flex; gap:12px; flex-wrap:wrap;">
                <button class="page-btn" type="submit">Применить</button>
                <a class="page-btn" href="myCollection.php?collection_id=<?=(int)$selectedCollectionId?>" style="text-decoration:none;display:inline-flex;align-items:center;justify-content:center;">
                    Сброс
                </a>
            </div>
        </form>
    </div>

    <?php if ($totalItems === 0): ?>
        <div class="empty-collection">
            <div class="empty-icon">📭</div>
            <h3>Ничего не найдено</h3>
            <p>Попробуйте изменить фильтры или поисковый запрос.</p>
            <a href="all.php" class="browse-btn">Перейти к каталогу</a>
        </div>
    <?php else: ?>

        <div class="schemes-grid">
            <?php foreach ($items as $it):
                $pid = (int)$it['pattern_id'];
                $title = (string)$it['title'];
                $img = (string)($it['image_path'] ?: '/assets/images/default-scheme.png');
                $w = (int)$it['width'];
                $hgt = (int)$it['height'];
                $totalPixels = (int)$it['total_pixels'];
                $colors = (int)$it['color_count'];
                $diff = difficultyLabel((string)$it['difficulty']);
                $catName = (string)($it['category_name'] ?? 'Без категории');
                $progress = (float)$it['completion_percentage'];
                $pixelsMarked = (int)$it['pixels_marked'];
                $isFav = (int)$it['is_favorite'] === 1;
                $notes = (string)($it['notes'] ?? '');
                $addedAt = (string)$it['added_at'];

                $badgeText = $progress >= 100 ? 'Завершено' : ($progress > 0 ? 'В процессе' : 'Новая');
                $badgeClass = $progress >= 100 ? 'badge-completed' : 'badge-in-progress';
            ?>
            <div class="collection-card">
                <div class="card-image">
                    <img src="<?=h($img)?>" alt="<?=h($title)?>">
                    <span class="card-badge <?=h($badgeClass)?>"><?=h($badgeText)?></span>

                    <form method="post" style="position:absolute; top:15px; left:15px;">
                        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
                        <input type="hidden" name="action" value="toggle_favorite">
                        <input type="hidden" name="collection_id" value="<?=$selectedCollectionId?>">
                        <input type="hidden" name="pattern_id" value="<?=$pid?>">
                        <input type="hidden" name="back" value="<?=h($backUrl)?>">
                        <button class="favorite-btn <?= $isFav ? 'active' : '' ?>" type="submit" title="Избранное">
                            <?= $isFav ? '♥' : '♡' ?>
                        </button>
                    </form>
                </div>

                <div class="card-content">
                    <div class="card-header">
                        <h3 class="card-title"><?=h($title)?></h3>
                        <span class="card-category"><?=h($catName)?></span>
                    </div>

                    <div class="card-progress">
                        <div class="progress-info">
                            <span class="progress-percent"><?=h(number_format($progress, 0))?>%</span>
                            <span class="progress-count"><?= (int)$pixelsMarked ?>/<?= (int)$totalPixels ?></span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?=h((string)min(100, max(0, $progress)))?>%"></div>
                        </div>
                    </div>

                    <div class="card-meta">
                        <div class="meta-item"><span>▦</span><span><?=$w?>×<?=$hgt?></span></div>
                        <div class="meta-item"><span>🎨</span><span><?=$colors?> цветов</span></div>
                        <div class="meta-item"><span>📅</span><span><?=h(date('d.m.Y', strtotime($addedAt)))?></span></div>
                    </div>

                    <div style="margin:10px 0; color:#7a5e9d; font-size:13px;">
                        Сложность: <b style="color:#4b1370;"><?=h($diff)?></b>
                    </div>

                    <details style="margin-top:8px;">
                        <summary style="cursor:pointer; color:#7b3efc; font-weight:600;">📝 Заметки</summary>
                        <form method="post" style="margin-top:10px;">
                            <input type="hidden" name="csrf" value="<?=h($csrf)?>">
                            <input type="hidden" name="action" value="save_notes">
                            <input type="hidden" name="collection_id" value="<?=$selectedCollectionId?>">
                            <input type="hidden" name="pattern_id" value="<?=$pid?>">
                            <input type="hidden" name="back" value="<?=h($backUrl)?>">
                            <textarea name="notes" style="width:100%;height:110px;padding:12px;border-radius:10px;border:2px solid #e5d6ff;font-family:Inter,sans-serif;box-sizing:border-box;"><?=h($notes)?></textarea>
                            <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:10px;">
                                <button class="btn-save" type="submit">Сохранить</button>
                            </div>
                        </form>
                    </details>

                    <div class="card-actions" style="margin-top:14px;">
                        <a class="action-btn btn-continue" href="scheme.php?pattern_id=<?=$pid?>" style="text-decoration:none;">
                            <span>▶</span> Открыть
                        </a>

                        <form method="post" onsubmit="return confirm('Удалить схему из коллекции?');" style="flex:1;">
                            <input type="hidden" name="csrf" value="<?=h($csrf)?>">
                            <input type="hidden" name="action" value="remove_item">
                            <input type="hidden" name="collection_id" value="<?=$selectedCollectionId?>">
                            <input type="hidden" name="pattern_id" value="<?=$pid?>">
                            <input type="hidden" name="back" value="<?=h($backUrl)?>">
                            <button class="action-btn btn-remove" type="submit" style="width:100%;">
                                <span>🗑️</span> Удалить
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php
                $prev = max(1, $page - 1);
                $next = min($totalPages, $page + 1);
                ?>
                <a class="page-btn" href="myCollection.php?<?=h(http_build_query(['collection_id'=>$selectedCollectionId,'q'=>$q,'category'=>$category,'status'=>$status,'sort'=>$sort,'page'=>$prev]))?>" style="text-decoration:none;">
                    ← Назад
                </a>

                <div class="page-numbers">
                    <?php
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $start + 4);
                    $start = max(1, $end - 4);
                    for ($i=$start; $i<=$end; $i++):
                        $link = "myCollection.php?" . http_build_query([
                            'collection_id'=>$selectedCollectionId,
                            'q'=>$q,
                            'category'=>$category,
                            'status'=>$status,
                            'sort'=>$sort,
                            'page'=>$i
                        ]);
                    ?>
                        <a class="page-number <?= $i===$page ? 'active' : '' ?>" href="<?=h($link)?>" style="text-decoration:none;">
                            <?=$i?>
                        </a>
                    <?php endfor; ?>
                </div>

                <a class="page-btn" href="myCollection.php?<?=h(http_build_query(['collection_id'=>$selectedCollectionId,'q'=>$q,'category'=>$category,'status'=>$status,'sort'=>$sort,'page'=>$next]))?>" style="text-decoration:none;">
                    Вперёд →
                </a>
            </div>
        <?php endif; ?>

    <?php endif; ?>

</section>

<footer class="footer">
    <div class="footer-content">
        <div class="footer-logo">
            <img src="/assets/images/logo.png" class="footer-img" alt="">
            <div class="footer-text">
                <div class="footer-title">PixelCraft</div>
                <div class="footer-sub">Ваш персональный помощник для<br>работы с пиксельными схемами</div>
            </div>
        </div>
    </div>
</footer>

</body>
</html>

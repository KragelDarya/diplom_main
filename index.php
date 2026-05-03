<?php 
declare(strict_types=1);

require_once __DIR__ . '/config/session.php';
if (empty($_SESSION['user']) && !empty($_SESSION['user_id'])) {
    $_SESSION['user'] = [
        'user_id' => (int)$_SESSION['user_id'],
        'email'   => (string)($_SESSION['user_email'] ?? ''),
        'name'    => (string)($_SESSION['user_name'] ?? ''),
    ];
}
require_once __DIR__ . '/config/db.php';
$db = getDB();

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function normalize_image_path(?string $path): string {
    if (!$path) return 'assets/images/other.png'; // fallback

    $p = trim($path);

    if (str_starts_with($p, '../images/')) {
        return 'images/' . substr($p, strlen('../images/'));
    }

    if (str_starts_with($p, '/images/')) {
        return 'images/' . substr($p, strlen('/images/'));
    }

    if (str_starts_with($p, 'images/')) {
        return $p;
    }

    if (!str_contains($p, '/')) {
        return 'images/' . $p;
    }

    return $p;
}

function difficulty_to_ui(string $dbVal): array {
    return match ($dbVal) {
        'beginner' => ['easy', 'Легкий'],
        'intermediate' => ['medium', 'Средний'],
        'advanced' => ['hard', 'Сложный'],
        default => ['medium', $dbVal],
    };
}

function require_post(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['status' => 'error', 'message' => 'Method not allowed'], 405);
    }
}

if (isset($_GET['api'])) {
    $api = (string)$_GET['api'];

    if ($api === 'me') {
        $user = $_SESSION['user'] ?? null;
        json_out(['status' => 'success', 'user' => $user]);
    }

    if ($api === 'logout') {
        $_SESSION = [];
        session_destroy();
        json_out(['status' => 'success']);
    }

    if ($api === 'register') {
        require_post();
        $payload = json_decode((string)file_get_contents('php://input'), true) ?? [];

        $email = trim((string)($payload['email'] ?? ''));
        $name  = trim((string)($payload['name'] ?? ''));
        $pass  = (string)($payload['password'] ?? '');

        if ($email === '' || $name === '' || $pass === '') {
            json_out(['status' => 'error', 'message' => 'Заполните все поля'], 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_out(['status' => 'error', 'message' => 'Некорректный email'], 400);
        }

        if (mb_strlen($name) < 3 || mb_strlen($name) > 20) {
            json_out(['status' => 'error', 'message' => 'Имя должно быть от 3 до 20 символов'], 400);
        }

        if (!preg_match('/^[A-Za-zА-Яа-я0-9_]+$/u', $name)) {
            json_out(['status' => 'error', 'message' => 'Имя может содержать только буквы, цифры и подчеркивание'], 400);
        }

        if (strlen($pass) < 8) {
            json_out(['status' => 'error', 'message' => 'Пароль должен быть не короче 8 символов'], 400);
        }

        if (!preg_match('/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/', $pass)) {
            json_out(['status' => 'error', 'message' => 'Пароль должен содержать заглавные, строчные буквы и цифры'], 400);
        }

        try {
            $stmt = $db->prepare("INSERT INTO user (email, name, password) VALUES (:email, :name, :pass)");
            $stmt->execute([
                ':email' => $email,
                ':name'  => $name,
                ':pass'  => $pass,
            ]);

            $userId = (int)$db->lastInsertId();

            $stmt = $db->prepare("INSERT INTO collection (user_id, name, description) VALUES (:uid, 'Моя коллекция', 'Схемы пользователя')");
            $stmt->execute([':uid' => $userId]);

            $_SESSION['user'] = ['user_id' => $userId, 'email' => $email, 'name' => $name];

            
            $_SESSION['user_id'] = $userId;
            $_SESSION['user_name'] = $name;
            $_SESSION['user_email'] = $email;

            json_out(['status' => 'success', 'user' => $_SESSION['user']]);
        } catch (PDOException $e) {
            json_out(['status' => 'error', 'message' => 'Пользователь с таким email или именем уже существует'], 409);
        }
    }

    if ($api === 'login') {
        require_post();
        $payload = json_decode((string)file_get_contents('php://input'), true) ?? [];

        $email = trim((string)($payload['email'] ?? ''));
        $pass  = (string)($payload['password'] ?? '');

        if ($email === '' || $pass === '') {
            json_out(['status' => 'error', 'message' => 'Введите email и пароль'], 400);
        }

        $stmt = $db->prepare("SELECT user_id, email, name FROM user WHERE email = :email AND password = :pass LIMIT 1");
        $stmt->execute([':email' => $email, ':pass' => $pass]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_out(['status' => 'error', 'message' => 'Некорректный email'], 400);
        }

        if (!$user) {
            json_out(['status' => 'error', 'message' => 'Неверный email или пароль'], 401);
        }

        $_SESSION['user'] = [
            'user_id' => (int)$user['user_id'],
            'email'   => (string)$user['email'],
            'name'    => (string)$user['name'],
        ];

        $_SESSION['user_id'] = (int)$user['user_id'];
        $_SESSION['user_name'] = (string)$user['name'];
        $_SESSION['user_email'] = (string)$user['email'];

        $stmt = $db->prepare("SELECT collection_id FROM collection WHERE user_id = :uid ORDER BY collection_id ASC LIMIT 1");
        $stmt->execute([':uid' => $_SESSION['user']['user_id']]);
        $col = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$col) {
            $stmt = $db->prepare("INSERT INTO collection (user_id, name, description) VALUES (:uid, 'Моя коллекция', 'Схемы пользователя')");
            $stmt->execute([':uid' => $_SESSION['user']['user_id']]);
        }

        json_out(['status' => 'success', 'user' => $_SESSION['user']]);
    }

    if ($api === 'is_in_collection') {
        $user = $_SESSION['user'] ?? null;
        if (!$user) json_out(['status' => 'success', 'in_collection' => false]);

        $patternId = (int)($_GET['pattern_id'] ?? 0);
        if ($patternId <= 0) json_out(['status' => 'success', 'in_collection' => false]);


        $stmt = $db->prepare("SELECT 1 FROM pattern WHERE pattern_id = :pid AND is_active = 1 LIMIT 1");
        $stmt->execute([':pid' => $patternId]);
        if (!$stmt->fetchColumn()) {
            json_out(['status' => 'success', 'in_collection' => false]);
        }

        $stmt = $db->prepare("SELECT collection_id FROM collection WHERE user_id = :uid ORDER BY collection_id ASC LIMIT 1");
        $stmt->execute([':uid' => $user['user_id']]);
        $col = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$col) json_out(['status' => 'success', 'in_collection' => false]);

        $stmt = $db->prepare("SELECT 1 FROM collection_pattern WHERE collection_id = :cid AND pattern_id = :pid LIMIT 1");
        $stmt->execute([':cid' => (int)$col['collection_id'], ':pid' => $patternId]);

        json_out(['status' => 'success', 'in_collection' => (bool)$stmt->fetchColumn()]);
    }

    if ($api === 'add_to_collection') {
        require_post();
        $user = $_SESSION['user'] ?? null;
        if (!$user) json_out(['status' => 'error', 'message' => 'Необходимо войти'], 401);

        $payload = json_decode((string)file_get_contents('php://input'), true) ?? [];
        $patternId = (int)($payload['pattern_id'] ?? 0);
        if ($patternId <= 0) json_out(['status' => 'error', 'message' => 'pattern_id некорректен'], 400);

        $stmt = $db->prepare("SELECT 1 FROM pattern WHERE pattern_id = :pid AND is_active = 1 LIMIT 1");
        $stmt->execute([':pid' => $patternId]);
        if (!$stmt->fetchColumn()) {
            json_out(['status' => 'error', 'message' => 'Эта схема временно скрыта'], 403);
        }

        $stmt = $db->prepare("SELECT collection_id FROM collection WHERE user_id = :uid ORDER BY collection_id ASC LIMIT 1");
        $stmt->execute([':uid' => $user['user_id']]);
        $col = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$col) {
            $stmt = $db->prepare("INSERT INTO collection (user_id, name, description) VALUES (:uid, 'Моя коллекция', 'Схемы пользователя')");
            $stmt->execute([':uid' => $user['user_id']]);
            $collectionId = (int)$db->lastInsertId();
        } else {
            $collectionId = (int)$col['collection_id'];
        }

        try {
            $stmt = $db->prepare("INSERT INTO collection_pattern (collection_id, pattern_id, is_favorite, notes) VALUES (:cid, :pid, 0, NULL)");
            $stmt->execute([':cid' => $collectionId, ':pid' => $patternId]);
            json_out(['status' => 'success', 'added' => true]);
        } catch (PDOException $e) {
            json_out(['status' => 'success', 'added' => false, 'message' => 'Уже в коллекции']);
        }
    }

    if ($api === 'remove_from_collection') {
        require_post();

        $user = $_SESSION['user'] ?? null;
        if (!$user) json_out(['status' => 'error', 'message' => 'Необходимо войти'], 401);

        $payload = json_decode((string)file_get_contents('php://input'), true) ?? [];
        $patternId = (int)($payload['pattern_id'] ?? 0);
        if ($patternId <= 0) json_out(['status' => 'error', 'message' => 'pattern_id некорректен'], 400);

       
        $stmt = $db->prepare("SELECT collection_id FROM collection WHERE user_id = :uid ORDER BY collection_id ASC LIMIT 1");
        $stmt->execute([':uid' => (int)$user['user_id']]);
        $collectionId = (int)($stmt->fetchColumn() ?: 0);

        if ($collectionId <= 0) {
            json_out(['status' => 'success', 'removed' => false, 'message' => 'Коллекция не найдена']);
        }

   
        $del = $db->prepare("DELETE FROM collection_pattern WHERE collection_id = :cid AND pattern_id = :pid");
        $del->execute([':cid' => $collectionId, ':pid' => $patternId]);

        json_out(['status' => 'success', 'removed' => ($del->rowCount() > 0)]);
    }

    if ($api === 'check_user') {
        $email = trim((string)($_GET['email'] ?? ''));
        $name  = trim((string)($_GET['name'] ?? ''));

        $emailExists = false;
        $nameExists = false;

        if ($email !== '') {
            $st = $db->prepare("SELECT 1 FROM user WHERE email = :email LIMIT 1");
            $st->execute([':email' => $email]);
            $emailExists = (bool)$st->fetchColumn();
        }

        if ($name !== '') {
            $st = $db->prepare("SELECT 1 FROM user WHERE name = :name LIMIT 1");
            $st->execute([':name' => $name]);
            $nameExists = (bool)$st->fetchColumn();
        }

        json_out([
            'status' => 'success',
            'email_exists' => $emailExists,
            'name_exists' => $nameExists
        ]);
    }   

    json_out(['status' => 'error', 'message' => 'Unknown api'], 404);

}

$fixedCategories = ['Еда', 'Растения', 'Персонажи', 'Абстракции', 'Животные', 'Другое'];
$catImageMap = [
    'Еда' => 'assets/images/food.png',
    'Растения' => 'assets/images/plants.png',
    'Персонажи' => 'assets/images/characters.png',
    'Абстракции' => 'assets/images/abstract.png',
    'Животные' => 'assets/images/animals.png',
    'Другое' => 'assets/images/other.png',
];

$in = implode(',', array_fill(0, count($fixedCategories), '?'));
$stmt = $db->prepare("SELECT category_id, category_name FROM category WHERE category_name IN ($in)");
$stmt->execute($fixedCategories);
$catsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

$catsByName = [];
foreach ($catsRaw as $c) $catsByName[$c['category_name']] = $c;

$categories = [];
foreach ($fixedCategories as $name) {
    $categories[] = [
        'category_name' => $name,
        'category_id' => isset($catsByName[$name]) ? (int)$catsByName[$name]['category_id'] : null,
        'image' => $catImageMap[$name] ?? 'assets/images/other.png',
    ];
}

$popularTitles = ['Роза в винтажном стиле', 'Горный пейзаж', 'Милый котёнок'];

$in2 = implode(',', array_fill(0, count($popularTitles), '?'));
$stmt = $db->prepare("
    SELECT pattern_id, title, image_path, difficulty, width, height, total_pixels, color_count
    FROM pattern
    WHERE is_active = 1
      AND title IN ($in2)
");
$stmt->execute($popularTitles);
$popularRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($popularRaw) < 3) {
    $stmt = $db->query("
        SELECT pattern_id, title, image_path, difficulty, width, height, total_pixels, color_count
        FROM pattern
        WHERE is_active = 1
        ORDER BY created_at DESC
        LIMIT 3
    ");
    $popularRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (!$popularRaw) {
    $popularRaw = [];
}

$popularByTitle = [];
foreach ($popularRaw as $p) $popularByTitle[$p['title']] = $p;

$popular = [];
foreach ($popularTitles as $t) {
    if (isset($popularByTitle[$t])) $popular[] = $popularByTitle[$t];
}
if (count($popular) < 3) {
    foreach ($popularRaw as $p) {
        if (count($popular) >= 3) break;
        $already = false;
        foreach ($popular as $x) {
            if ((int)$x['pattern_id'] === (int)$p['pattern_id']) { $already = true; break; }
        }
        if (!$already) $popular[] = $p;
    }
}

$user = $_SESSION['user'] ?? null;
require_once __DIR__ . '/config/session.php';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PixelCraft</title>

    <link rel="stylesheet" href="/assets/css/style.css?v=2">
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
        <a href="/index.php" class="active-page">Главная</a>
        <a href="../app/views/all.php">Все схемы</a>
        <a href="../app/views/myCollection.php">Моя коллекция</a>
        <a href="#">Создать схему</a>

        <?php if (!$user): ?>
            <a href="#" class="register-btn-header" id="openAuthHeader">Зарегистрироваться</a>
        <?php else: ?>
            <div class="user-info" style="margin-left: 35px;">
                <div class="user-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></div>
                <div class="user-name"><?= htmlspecialchars($user['name']) ?></div>
                <button class="logout-btn" id="logoutBtn">Выйти</button>
            </div>
        <?php endif; ?>
    </nav>
</header>

<section class="hero" id="hero">
    <div class="hero-content">
        <h1>Создавайте<br>пиксельные схемы<br>легко</h1>
        <p>
            Платформа для создания и работы с пиксельными схемами.
            Начните рисовать прямо сейчас с простым редактором.
        </p>
    </div>
</section>

<?php if (empty($_SESSION['user'])): ?>
  <section class="register-block">
    <p class="register-text">
      Регистрируйтесь, добавляйте схемы в свою коллекцию, сохраняйте прогресс, создавайте свои схемы и делитесь ими с другими пользователями
    </p>
    <a href="#" id="openAuthMain" class="register-btn-main">Зарегистрироваться</a>
  </section>
<?php endif; ?>

<div class="auth-modal" id="authModal">
    <div class="auth-window">
        <button class="auth-close" id="authClose">×</button>

        <div class="auth-tabs">
            <button class="auth-tab active" data-tab="register">Регистрация</button>
            <button class="auth-tab" data-tab="login">Вход</button>
        </div>

        <form class="auth-form" id="registerForm">
            <div class="form-group">
                <input type="email" id="regEmail" placeholder="Электронная почта" required>
                <div class="hint" id="hint-email"></div>
            </div>

            <div class="form-group">
                <input type="text" id="regUsername" placeholder="Имя пользователя" required>
                <div class="hint" id="hint-username"></div>
            </div>

            <div class="form-group">
                <input type="password" id="regPassword" placeholder="Пароль" required>
                <div class="hint" id="hint-password"></div>
            </div>

            <div class="form-group">
                <input type="password" id="regPassword2" placeholder="Подтвердите пароль" required>
                <div class="hint" id="hint-password2"></div>
            </div>

            <button type="submit" class="auth-submit">Зарегистрироваться</button>

            <p class="auth-switch">
                Уже есть аккаунт?
                <a href="#" class="switch-to-login">Войти</a>
            </p>
        </form>

        <form class="auth-form hidden" id="loginForm">
            <div class="form-group">
                <input type="email" id="logEmail" placeholder="Электронная почта" required>
                <div class="hint" id="hint-login-email"></div>
            </div>

            <div class="form-group">
                <input type="password" id="logPassword" placeholder="Пароль" required>
                <div class="hint" id="hint-login-password"></div>
            </div>

            <button type="submit" class="auth-submit">Войти</button>

            <p class="auth-switch">
                Нет аккаунта?
                <a href="#" class="switch-to-register">Зарегистрироваться</a>
            </p>
        </form>

        <p class="auth-error" id="authError"></p>
    </div>
</div>

<section class="categories">
    <h2>Найдите схему по теме</h2>
    <p class="cat-subtitle">Выбирайте из разных направлений</p>

    <div class="cat-grid">
        <div class="cat-column">

            <a class="cat-item"
                href="/app/views/all.php?category=<?= urlencode($categories[0]['category_name']) ?>">
                <img src="<?= htmlspecialchars($categories[0]['image']) ?>" alt="<?= htmlspecialchars($categories[0]['category_name']) ?>">
                <span><?= htmlspecialchars($categories[0]['category_name']) ?></span>
            </a>

            <a class="cat-item"
                href="/app/views/all.php?category=<?= urlencode($categories[2]['category_name']) ?>">
                <img src="<?= htmlspecialchars($categories[2]['image']) ?>" alt="<?= htmlspecialchars($categories[2]['category_name']) ?>">
                <span><?= htmlspecialchars($categories[2]['category_name']) ?></span>
            </a>

        </div>

        <div class="cat-column">

            <a class="cat-item"
                href="/app/views/all.php?category=<?= urlencode($categories[1]['category_name']) ?>">
                <img src="<?= htmlspecialchars($categories[1]['image']) ?>" alt="<?= htmlspecialchars($categories[1]['category_name']) ?>">
                <span><?= htmlspecialchars($categories[1]['category_name']) ?></span>
            </a>

            <a class="cat-item"
                href="/app/views/all.php?category=<?= urlencode($categories[3]['category_name']) ?>">
                <img src="<?= htmlspecialchars($categories[3]['image']) ?>" alt="<?= htmlspecialchars($categories[3]['category_name']) ?>">
                <span><?= htmlspecialchars($categories[3]['category_name']) ?></span>
            </a>

        </div>

        <div class="cat-column">

            <a class="cat-item"
                href="/app/views/all.php?category=<?= urlencode($categories[4]['category_name']) ?>">
                <img src="<?= htmlspecialchars($categories[4]['image']) ?>" alt="<?= htmlspecialchars($categories[4]['category_name']) ?>">
                <span><?= htmlspecialchars($categories[4]['category_name']) ?></span>
            </a>

            <a class="cat-item"
                href="/app/views/all.php?category=<?= urlencode($categories[5]['category_name']) ?>">
                <img src="<?= htmlspecialchars($categories[5]['image']) ?>" alt="<?= htmlspecialchars($categories[5]['category_name']) ?>">
                <span><?= htmlspecialchars($categories[5]['category_name']) ?></span>
            </a>

        </div>
    </div>
</section>

<section class="popular">
    <h2>Популярные схемы этого месяца</h2>

    <div class="popular-grid">
        <?php foreach ($popular as $p): ?>
            <?php
                [$tagClass, $tagText] = difficulty_to_ui((string)$p['difficulty']);
                $img = normalize_image_path($p['image_path'] ?? null);
                $pid = (int)$p['pattern_id'];
                $title = (string)$p['title'];
                $w = (int)$p['width'];
                $h = (int)$p['height'];
                $total = (int)$p['total_pixels'];
                $colors = (int)$p['color_count'];
            ?>
            <div class="scheme-card" data-pattern-id="<?= $pid ?>">
                <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($title) ?>">
                <div class="card-content">
                    <div class="card-title-row">
                        <h3><?= htmlspecialchars($title) ?></h3>
                        <span class="tag <?= htmlspecialchars($tagClass) ?>"><?= htmlspecialchars($tagText) ?></span>
                    </div>
                    <div class="info-line">
                        <span class="icon">▦</span> <?= $w ?> × <?= $h ?> • <?= $total ?> пикселей
                    </div>
                    <div class="info-line">
                        <span class="icon">🎨</span> <?= $colors ?> цветов
                    </div>

                    <div class="colors">
                        <span style="background:#b90000"></span>
                        <span style="background:#ff6600"></span>
                        <span style="background:#289b00"></span>
                        <span style="background:#004aad"></span>
                    </div>

                    <div class="buttons">
                        <button class="btn start" onclick="location.href='/app/views/scheme.php?pattern_id=<?= $pid ?>'">Начать</button>
                        <button class="btn add" data-action="add-to-collection" data-pattern-id="<?= $pid ?>">Добавить в коллекцию</button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<footer class="footer">
    <div class="footer-content">
        <div class="footer-logo">
            <img src="assets/images/logo.png" alt="PixelCraft Logo" class="footer-img">
            <div class="footer-text">
                <div class="footer-title">PixelCraft</div>
                <div class="footer-sub">
                    Ваш персональный помощник для<br>
                    работы с пиксельными схемами
                </div>
            </div>
        </div>
    </div>
</footer>

<script>
function showNotification(message, type = 'info') {
    const n = document.createElement('div');
    n.className = 'notification notification-' + type;
    n.textContent = message;
    document.body.appendChild(n);

    setTimeout(() => {
        n.style.animation = 'slideOut 0.3s ease forwards';
        setTimeout(() => n.remove(), 300);
    }, 2500);
}

document.addEventListener("DOMContentLoaded", () => {
    const hero = document.getElementById("hero");
    if (!hero) return;

    document.addEventListener("mousemove", e => {
        const rect = hero.getBoundingClientRect();
        if (e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom) return;

        const dot = document.createElement("div");
        dot.className = "trail-dot";
        dot.style.left = (e.clientX - rect.left) + "px";
        dot.style.top  = (e.clientY - rect.top) + "px";
        hero.appendChild(dot);

        setTimeout(() => dot.remove(), 900);
    });
});

const authModal = document.getElementById('authModal');
const authClose = document.getElementById('authClose');
const openAuthHeader = document.getElementById('openAuthHeader');
const openAuthMain = document.getElementById('openAuthMain');

function openModal(tab = 'register') {
    if (!authModal) return;
    authModal.style.display = 'flex';
    document.body.classList.add('modal-open');
    switchTab(tab);
}
function closeModal() {
    if (!authModal) return;
    authModal.style.display = 'none';
    document.body.classList.remove('modal-open');
}

function switchTab(tab) {
    const regTab = document.querySelector('.auth-tab[data-tab="register"]');
    const logTab = document.querySelector('.auth-tab[data-tab="login"]');
    const regForm = document.getElementById('registerForm');
    const logForm = document.getElementById('loginForm');

    if (tab === 'login') {
        logTab.classList.add('active');
        regTab.classList.remove('active');
        regForm.classList.add('hidden');
        logForm.classList.remove('hidden');
    } else {
        regTab.classList.add('active');
        logTab.classList.remove('active');
        regForm.classList.remove('hidden');
        logForm.classList.add('hidden');
    }
    document.getElementById('authError').textContent = '';
}

document.addEventListener('click', (e) => {
    if (e.target?.classList?.contains('switch-to-login')) { e.preventDefault(); switchTab('login'); }
    if (e.target?.classList?.contains('switch-to-register')) { e.preventDefault(); switchTab('register'); }
    if (e.target?.classList?.contains('auth-tab')) { e.preventDefault(); switchTab(e.target.dataset.tab); }
});

authClose?.addEventListener('click', closeModal);
authModal?.addEventListener('click', (e) => { if (e.target === authModal) closeModal(); });
openAuthHeader?.addEventListener('click', (e) => { e.preventDefault(); openModal('register'); });
openAuthMain?.addEventListener('click', (e) => { e.preventDefault(); openModal('register'); });

async function apiPost(api, body) {
    const res = await fetch(`index.php?api=${encodeURIComponent(api)}`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(body || {})
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data?.message || 'Ошибка запроса');
    return data;
}

document.getElementById('registerForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const email = document.getElementById('regEmail').value.trim();
    const name = document.getElementById('regUsername').value.trim();
    const p1 = document.getElementById('regPassword').value;
    const p2 = document.getElementById('regPassword2').value;

    if (p1 !== p2) { document.getElementById('authError').textContent = 'Пароли не совпадают'; return; }

    try {
        await apiPost('register', {email, name, password: p1});
        showNotification('Регистрация успешна! Вы вошли.', 'success');
        closeModal();
        location.reload();
    } catch (err) {
        document.getElementById('authError').textContent = err.message;
    }
});

document.getElementById('loginForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const email = document.getElementById('logEmail').value.trim();
    const password = document.getElementById('logPassword').value;

    try {
        await apiPost('login', {email, password});
        showNotification('Вход выполнен успешно', 'success');
        closeModal();
        location.reload();
    } catch (err) {
        document.getElementById('authError').textContent = err.message;
    }
});

document.getElementById('logoutBtn')?.addEventListener('click', async () => {
    await fetch('index.php?api=logout');
    location.reload();
});

async function checkButtonsInCollection() {
    const buttons = document.querySelectorAll('button[data-action="add-to-collection"]');
    for (const btn of buttons) {
        const pid = btn.dataset.patternId;
        try {
            const res = await fetch(`index.php?api=is_in_collection&pattern_id=${encodeURIComponent(pid)}`);
            const data = await res.json();
            if (data?.in_collection) {
                btn.classList.add('added');
                btn.textContent = 'Уже в коллекции';
            }
        } catch(_) {}
    }
}

document.addEventListener('click', async (e) => {
    const btn = e.target?.closest?.('button[data-action="add-to-collection"]');
    if (!btn) return;

    e.preventDefault();
    const pid = Number(btn.dataset.patternId);

    if (btn.classList.contains('added')) {
        try {
            const data = await apiPost('remove_from_collection', { pattern_id: pid });

            if (data.removed) {
                btn.classList.remove('added');
                btn.textContent = 'Добавить в коллекцию';
                showNotification('Схема удалена из коллекции', 'success');
            } else {
                // если вдруг уже нет в БД
                btn.classList.remove('added');
                btn.textContent = 'Добавить в коллекцию';
                showNotification(data.message || 'Схема уже удалена', 'info');
            }
        } catch (err) {
            showNotification(err.message || 'Ошибка удаления', 'error');
        }
        return;
    }

    try {
        const data = await apiPost('add_to_collection', { pattern_id: pid });

        if (data.added) {
            btn.classList.add('added');
            btn.textContent = 'Уже в коллекции';
            showNotification('Схема добавлена в коллекцию!', 'success');
        } else {
            btn.classList.add('added');
            btn.textContent = 'Уже в коллекции';
            showNotification(data.message || 'Уже в коллекции', 'info');
        }
    } catch (err) {
        if ((err.message || '').includes('скрыта')) {
            showNotification(err.message, 'error');
            return;
        }
        openModal('login');
        showNotification('Для сохранения схемы в коллекцию необходимо войти в аккаунт', 'info');
    }
});

document.addEventListener('DOMContentLoaded', () => {
    checkButtonsInCollection();
});

function validateEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

function validateUsername(username) {
  return /^[A-Za-zА-Яа-я0-9_]+$/u.test(username);
}

function validatePassword(password) {
  return /(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/.test(password);
}

function showHint(hintEl, text) {
  if (!hintEl) return;
  hintEl.classList.remove('hint-error', 'hint-success');
  hintEl.textContent = text || '';
}

function showError(inputEl, hintEl, text) {
  if (inputEl) inputEl.classList.add('input-error');
  if (inputEl) inputEl.classList.remove('input-success');
  if (hintEl) {
    hintEl.classList.add('hint-error');
    hintEl.classList.remove('hint-success');
    hintEl.textContent = text || '';
  }
}

function showSuccess(inputEl, hintEl, text) {
  if (inputEl) inputEl.classList.add('input-success');
  if (inputEl) inputEl.classList.remove('input-error');
  if (hintEl) {
    hintEl.classList.add('hint-success');
    hintEl.classList.remove('hint-error');
    hintEl.textContent = text || '';
  }
}

async function checkUserExists({ email = '', name = '' }) {
  const qs = new URLSearchParams();
  if (email) qs.set('email', email);
  if (name) qs.set('name', name);

  const res = await fetch(`index.php?api=check_user&${qs.toString()}`);
  const data = await res.json().catch(() => ({}));
  if (!res.ok || data.status !== 'success') return { email_exists: false, name_exists: false };
  return data;
}

(function setupValidation() {
  const regEmail = document.getElementById('regEmail');
  const regUsername = document.getElementById('regUsername');
  const regPassword = document.getElementById('regPassword');
  const regPassword2 = document.getElementById('regPassword2');

  const logEmail = document.getElementById('logEmail');
  const logPassword = document.getElementById('logPassword');

  const hintEmail = document.getElementById('hint-email');
  const hintUsername = document.getElementById('hint-username');
  const hintPassword = document.getElementById('hint-password');
  const hintPassword2 = document.getElementById('hint-password2');
  const hintLoginEmail = document.getElementById('hint-login-email');
  const hintLoginPassword = document.getElementById('hint-login-password');

  let isEmailValid = false;
  let isUsernameValid = false;
  let isPasswordValid = false;
  let isPassword2Valid = false;

  let isLoginEmailValid = false;
  let isLoginPasswordValid = false;

  if (regEmail) {
    regEmail.addEventListener('input', async function () {
      const email = this.value.trim();

      if (!email) {
        showError(this, hintEmail, 'Введите email');
        isEmailValid = false;
        return;
      }

      if (!validateEmail(email)) {
        showError(this, hintEmail, 'Некорректный email адрес');
        isEmailValid = false;
        return;
      }

      try {
        const data = await checkUserExists({ email });
        if (data.email_exists) {
          showError(this, hintEmail, 'Этот email уже зарегистрирован');
          isEmailValid = false;
        } else {
          showSuccess(this, hintEmail, 'Email доступен ✔');
          isEmailValid = true;
        }
      } catch {
        showSuccess(this, hintEmail, '');
        isEmailValid = true;
      }
    });

    regEmail.addEventListener('blur', function () {
      if (!this.value.trim()) showHint(hintEmail, 'Обязательное поле');
    });
  }

  if (regUsername) {
    regUsername.addEventListener('input', async function () {
      const username = this.value.trim();

      if (!username) {
        showError(this, hintUsername, 'Введите имя пользователя');
        isUsernameValid = false;
        return;
      }
      if (username.length < 3) {
        showError(this, hintUsername, 'Минимум 3 символа');
        isUsernameValid = false;
        return;
      }
      if (username.length > 20) {
        showError(this, hintUsername, 'Максимум 20 символов');
        isUsernameValid = false;
        return;
      }
      if (!validateUsername(username)) {
        showError(this, hintUsername, 'Только буквы, цифры и подчеркивание');
        isUsernameValid = false;
        return;
      }

      try {
        const data = await checkUserExists({ name: username });
        if (data.name_exists) {
          showError(this, hintUsername, 'Это имя уже занято');
          isUsernameValid = false;
        } else {
          showSuccess(this, hintUsername, 'Имя доступно ✔');
          isUsernameValid = true;
        }
      } catch {
        showSuccess(this, hintUsername, '');
        isUsernameValid = true;
      }
    });

    regUsername.addEventListener('blur', function () {
      if (!this.value.trim()) showHint(hintUsername, 'Обязательное поле');
    });
  }

  if (regPassword) {
    regPassword.addEventListener('input', function () {
      const password = this.value;

      if (!password) {
        showError(this, hintPassword, 'Введите пароль');
        isPasswordValid = false;
        return;
      }
      if (password.length < 8) {
        showError(this, hintPassword, 'Минимум 8 символов');
        isPasswordValid = false;
        return;
      }
      if (!validatePassword(password)) {
        showError(this, hintPassword, 'Заглавные, строчные буквы и цифры');
        isPasswordValid = false;
        return;
      }

      showSuccess(this, hintPassword, 'Надежный пароль ✔');
      isPasswordValid = true;

      if (regPassword2 && regPassword2.value) {
        regPassword2.dispatchEvent(new Event('input'));
      }
    });

    regPassword.addEventListener('blur', function () {
      if (!this.value) showHint(hintPassword, 'Обязательное поле');
    });
  }

  if (regPassword2) {
    regPassword2.addEventListener('input', function () {
      const password = regPassword ? regPassword.value : '';
      const confirm = this.value;

      if (!confirm) {
        showError(this, hintPassword2, 'Подтвердите пароль');
        isPassword2Valid = false;
        return;
      }
      if (password !== confirm) {
        showError(this, hintPassword2, 'Пароли не совпадают');
        isPassword2Valid = false;
        return;
      }

      showSuccess(this, hintPassword2, 'Пароли совпадают ✔');
      isPassword2Valid = true;
    });

    regPassword2.addEventListener('blur', function () {
      if (!this.value) showHint(hintPassword2, 'Обязательное поле');
    });
  }

  if (logEmail) {
    logEmail.addEventListener('input', function () {
      const email = this.value.trim();

      if (!email) {
        showError(this, hintLoginEmail, 'Введите email');
        isLoginEmailValid = false;
        return;
      }
      if (!validateEmail(email)) {
        showError(this, hintLoginEmail, 'Некорректный email');
        isLoginEmailValid = false;
        return;
      }

      showSuccess(this, hintLoginEmail, '');
      isLoginEmailValid = true;
    });
  }

  if (logPassword) {
    logPassword.addEventListener('input', function () {
      const password = this.value;

      if (!password) {
        showError(this, hintLoginPassword, 'Введите пароль');
        isLoginPasswordValid = false;
        return;
      }

      showSuccess(this, hintLoginPassword, '');
      isLoginPasswordValid = true;
    });
  }

  const registerForm = document.getElementById('registerForm');
  registerForm?.addEventListener('submit', (e) => {
    regEmail?.dispatchEvent(new Event('input'));
    regUsername?.dispatchEvent(new Event('input'));
    regPassword?.dispatchEvent(new Event('input'));
    regPassword2?.dispatchEvent(new Event('input'));

    if (!(isEmailValid && isUsernameValid && isPasswordValid && isPassword2Valid)) {
      e.preventDefault();
      const err = document.getElementById('authError');
      if (err) err.textContent = 'Исправьте ошибки в форме регистрации';
    }
  });

  const loginForm = document.getElementById('loginForm');
  loginForm?.addEventListener('submit', (e) => {
    logEmail?.dispatchEvent(new Event('input'));
    logPassword?.dispatchEvent(new Event('input'));

    if (!(isLoginEmailValid && isLoginPasswordValid)) {
      e.preventDefault();
      const err = document.getElementById('authError');
      if (err) err.textContent = 'Введите корректные данные для входа';
    }
  });
})();

</script>
</body>
</html>

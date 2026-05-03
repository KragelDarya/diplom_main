<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/db.php';
$db = getDB();

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): void {
    header("Location: $url");
    exit;
}

function difficultyLabel(string $d): string {
    return match($d) {
        'beginner' => 'Легкий',
        'intermediate' => 'Средний',
        'advanced' => 'Сложный',
        default => $d
    };
}


function normalize_image_path(?string $path): string {
    if (!$path) return '/images/default-scheme.png';

    $p = trim($path);

    if (str_starts_with($p, '../images/')) {
        return '/images/' . substr($p, strlen('../images/'));
    }

    if (str_starts_with($p, 'images/')) {
        return '/'.$p;
    }

    if (str_starts_with($p, '/images/')) {
        return $p;
    }

    if (!str_contains($p, '/')) {
        return '/images/' . $p;
    }

    return $p;
}

/* =========================
   Получаем pattern_id
========================= */
$patternId = (int)($_GET['pattern_id'] ?? 0);

if ($patternId <= 0) {
    exit('Некорректный pattern_id');
}

/* =========================
   Загружаем схему из БД (ТОЛЬКО АКТИВНЫЕ)
========================= */
$stmt = $db->prepare("
    SELECT 
        p.pattern_id,
        p.title,
        p.image_path,
        p.width,
        p.height,
        p.total_pixels,
        p.color_count,
        p.difficulty,
        p.description,
        c.category_name
    FROM pattern p
    LEFT JOIN category c ON c.category_id = p.category_id
    WHERE p.pattern_id = :pid
      AND p.is_active = 1
    LIMIT 1
");
$stmt->execute([':pid' => $patternId]);
$pattern = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pattern) {
    // Схема либо не существует, либо скрыта (is_active=0)
    http_response_code(404);
    exit('Схема не найдена или временно скрыта');
}

$userId = (int)($_SESSION['user_id'] ?? 0);

/* =========================
   Обработка сохранения прогресса
========================= */
if ($userId && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $completion = (float)($_POST['completion'] ?? 0);
    $completion = max(0, min(100, $completion));

    $stmt = $db->prepare("
        INSERT INTO progress (user_id, pattern_id, completion_percentage)
        VALUES (:uid, :pid, :completion)
        ON DUPLICATE KEY UPDATE
            completion_percentage = VALUES(completion_percentage),
            last_updated = CURRENT_TIMESTAMP
    ");
    $stmt->execute([
        ':uid' => $userId,
        ':pid' => $patternId,
        ':completion' => $completion
    ]);

    redirect("scheme.php?pattern_id=" . $patternId);
}

/* =========================
   Загружаем прогресс пользователя
========================= */
$progressValue = 0;

if ($userId) {
    $stmt = $db->prepare("
        SELECT completion_percentage
        FROM progress
        WHERE user_id = :uid AND pattern_id = :pid
        LIMIT 1
    ");
    $stmt->execute([
        ':uid' => $userId,
        ':pid' => $patternId
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $progressValue = (float)$row['completion_percentage'];
    }
}

$imageSrc = normalize_image_path($pattern['image_path'] ?? null);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title><?=h($pattern['title'])?> — PixelCraft</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/stylescheme.css">
</head>

<body>

<header class="header">
    <a class="logo-block" href="/index.php" style="text-decoration:none;color:inherit;">
        <img src="/assets/images/logo.png" class="header-logo-img" alt="">
        <div class="logo-text">
            <div class="logo-name">PixelCraft</div>
            <div class="logo-desc">
                Ваш персональный помощник для<br>работы с пиксельными схемами
            </div>
        </div>
    </a>

    <nav class="menu">
        <a href="/index.php">Главная</a>
        <a href="/views/all.php">Все схемы</a>
        <a href="/views/myCollection.php">Моя коллекция</a>
    </nav>
</header>

<section class="stats">
    <div class="stat-card">
        <div class="stat-name">Категория</div>
        <div class="stat-value"><?=h($pattern['category_name'] ?? '—')?></div>
    </div>

    <div class="stat-card">
        <div class="stat-name">Размер</div>
        <div class="stat-value"><?=$pattern['width']?> × <?=$pattern['height']?></div>
    </div>

    <div class="stat-card">
        <div class="stat-name">Сложность</div>
        <div class="stat-value"><?=h(difficultyLabel($pattern['difficulty']))?></div>
    </div>

    <div class="stat-card">
        <div class="stat-name">Прогресс</div>
        <div class="stat-value"><?=number_format($progressValue, 0)?>%</div>
    </div>
</section>

<section class="workspace">

    <div class="palette">
        <h3>Информация</h3>
        <div class="color-item">
            <div class="color-info">
                <div class="color-name">Всего пикселей</div>
                <div class="color-count"><?=$pattern['total_pixels']?></div>
            </div>
        </div>

        <div class="color-item">
            <div class="color-info">
                <div class="color-name">Количество цветов</div>
                <div class="color-count"><?=$pattern['color_count']?></div>
            </div>
        </div>

        <div class="color-item">
            <div class="color-info">
                <div class="color-name">Описание</div>
                <div class="color-count"><?=h($pattern['description'] ?? '')?></div>
            </div>
        </div>
    </div>

    <div class="scheme-area">
        <div class="scheme-header">
            <h2><?=h($pattern['title'])?></h2>
        </div>

        <img src="<?=h($imageSrc)?>"
             alt="<?=h($pattern['title'])?>"
             style="max-width:100%;border-radius:12px;margin-top:20px;">

        <?php if ($userId): ?>
            <form method="post" style="margin-top:20px;">
                <label style="color:#3c1461;font-weight:600;">Прогресс (%)</label>
                <input type="number"
                       name="completion"
                       value="<?=number_format($progressValue,0)?>"
                       min="0"
                       max="100"
                       style="width:100%;padding:10px;border-radius:8px;border:2px solid #e5d6ff;margin-top:8px;">
                <button type="submit"
                        style="margin-top:12px;padding:10px 20px;background:#7b3efc;color:white;border:none;border-radius:8px;">
                    Сохранить прогресс
                </button>
            </form>
        <?php else: ?>
            <p style="margin-top:20px;color:#7c6a9a;">
                Войдите в аккаунт, чтобы сохранять прогресс.
            </p>
        <?php endif; ?>

    </div>

</section>

<footer class="footer">
    <div class="footer-content">
        <div class="footer-logo">
            <img src="/assets/images/logo.png" class="footer-img" alt="">
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

</body>
</html>

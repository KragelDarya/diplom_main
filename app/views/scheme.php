<?php
declare(strict_types=1);

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

function normalize_image_path(?string $path): string {
    if (!$path) return '/images/default-scheme.png';

    $p = trim($path);

    if (str_starts_with($p, '../images/')) return '/images/' . substr($p, strlen('../images/'));
    if (str_starts_with($p, 'images/')) return '/' . $p;
    if (str_starts_with($p, '/images/')) return $p;
    if (!str_contains($p, '/')) return '/images/' . $p;

    return $p;
}

$patternId = (int)($_GET['pattern_id'] ?? 0);
if ($patternId <= 0) exit('Некорректный pattern_id');

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
    http_response_code(404);
    exit('Схема не найдена или временно скрыта');
}

$userId = (int)($_SESSION['user_id'] ?? 0);

if ($userId && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $completion = max(0, min(100, (float)($_POST['completion'] ?? 0)));
    $pixelsMarked = max(0, (int)($_POST['pixels_marked'] ?? 0));

    $stmt = $db->prepare("
        INSERT INTO progress (user_id, pattern_id, completion_percentage, pixels_marked)
        VALUES (:uid, :pid, :completion, :pixels_marked)
        ON DUPLICATE KEY UPDATE
            completion_percentage = VALUES(completion_percentage),
            pixels_marked = VALUES(pixels_marked),
            last_updated = CURRENT_TIMESTAMP
    ");
    $stmt->execute([
        ':uid' => $userId,
        ':pid' => $patternId,
        ':completion' => $completion,
        ':pixels_marked' => $pixelsMarked
    ]);

    exit('ok');
}

$progressValue = 0;
$pixelsMarked = 0;

if ($userId) {
    $stmt = $db->prepare("
        SELECT completion_percentage, pixels_marked
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
        $pixelsMarked = (int)$row['pixels_marked'];
    }
}

$imageSrc = normalize_image_path($pattern['image_path'] ?? null);
$totalPixels = (int)$pattern['total_pixels'];
if ($totalPixels <= 0) {
    $totalPixels = (int)$pattern['width'] * (int)$pattern['height'];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title><?= h($pattern['title']) ?> — PixelCraft</title>

    <link rel="stylesheet" href="/assets/css/style.css?v=2">
    <link rel="stylesheet" href="/assets/css/stylescheme.css?v=2">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>

<body>

<header class="header">
    <a href="/index.php" class="logo-block">
        <img src="/assets/images/logo.png" class="header-logo-img" alt="Logo">
        <div class="logo-text">
            <div class="logo-name">PixelCraft</div>
            <div class="logo-desc">Ваш персональный помощник для<br>работы с пиксельными схемами</div>
        </div>
    </a>

    <nav class="menu">
        <a href="/index.php">Главная</a>
        <a href="/app/views/all.php">Все схемы</a>
        <a href="/app/views/myCollection.php">Моя коллекция</a>
        <a href="#">Создать схему</a>

        <?php if ($userId): ?>
            <a href="/app/views/myCollection.php?logout=1">Выйти</a>
        <?php else: ?>
            <a href="/index.php" class="register-btn-header">Войти</a>
        <?php endif; ?>
    </nav>
</header>

<section class="scheme-stats">
    <div class="scheme-stat-card">
        <div class="stat-name">Прогресс</div>
        <div class="stat-value" id="progressValue"><?= number_format($progressValue, 1) ?>%</div>
    </div>

    <div class="scheme-stat-card">
        <div class="stat-name">Отмечено</div>
        <div class="stat-value">
            <span id="markedValue"><?= (int)$pixelsMarked ?></span> / <?= (int)$totalPixels ?>
        </div>
    </div>

    <div class="scheme-stat-card">
        <div class="stat-name">Осталось</div>
        <div class="stat-value" id="leftValue"><?= max(0, $totalPixels - $pixelsMarked) ?></div>
    </div>

    <div class="scheme-stat-card">
        <div class="stat-name">Время</div>
        <div class="stat-value" id="timerValue">00:00:00</div>
    </div>
</section>

<section class="scheme-workspace">

    <aside class="scheme-palette">
        <h3>🎨 Палитра</h3>
        <div id="paletteList"></div>
    </aside>

    <main class="scheme-main">
        <div class="scheme-top">
            <h2><?= h($pattern['title']) ?></h2>

            <div class="scheme-tools">
                <button type="button" id="zoomOut">−</button>
                <span id="zoomText">100%</span>
                <button type="button" id="zoomIn">+</button>
                <button type="button" id="resetMarks">↺</button>
                <button type="button" id="saveProgress">💾</button>
            </div>
        </div>

        <div class="canvas-wrap">
            <canvas id="schemeCanvas"></canvas>
        </div>
    </main>

</section>

<script>
const patternId = <?= (int)$patternId ?>;
const userId = <?= (int)$userId ?>;
const imageSrc = <?= json_encode($imageSrc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const gridWidth = <?= (int)$pattern['width'] ?>;
const gridHeight = <?= (int)$pattern['height'] ?>;
const totalPixels = gridWidth * gridHeight;

const canvas = document.getElementById('schemeCanvas');
const ctx = canvas.getContext('2d', { willReadFrequently: true });

const paletteList = document.getElementById('paletteList');
const progressValue = document.getElementById('progressValue');
const markedValue = document.getElementById('markedValue');
const leftValue = document.getElementById('leftValue');
const zoomText = document.getElementById('zoomText');

const storageKey = `pixelcraft_marks_${patternId}_${userId || 'guest'}`;

let zoom = 1;
let cellSize = 14;
let imagePixels = [];
let marked = new Set(JSON.parse(localStorage.getItem(storageKey) || '[]'));
let colorStats = {};
let colorMarked = {};

function rgbToHex(r, g, b) {
    return '#' + [r, g, b].map(v => v.toString(16).padStart(2, '0')).join('').toUpperCase();
}

function colorKey(hex) {
    return hex.toUpperCase();
}

function drawScheme() {
    const size = Math.max(6, Math.round(cellSize * zoom));

    canvas.width = gridWidth * size;
    canvas.height = gridHeight * size;

    ctx.clearRect(0, 0, canvas.width, canvas.height);

    for (let y = 0; y < gridHeight; y++) {
        for (let x = 0; x < gridWidth; x++) {
            const index = y * gridWidth + x;
            const hex = imagePixels[index];

            ctx.fillStyle = hex;
            ctx.fillRect(x * size, y * size, size, size);

            ctx.strokeStyle = 'rgba(255,255,255,0.25)';
            ctx.strokeRect(x * size, y * size, size, size);

            if (marked.has(index)) {
                ctx.fillStyle = 'rgba(255,255,255,0.65)';
                ctx.beginPath();
                ctx.arc(
                    x * size + size / 2,
                    y * size + size / 2,
                    Math.max(1.5, size * 0.18),
                    0,
                    Math.PI * 2
                );
                ctx.fill();

                ctx.strokeStyle = 'rgba(0,0,0,0.55)';
                ctx.lineWidth = 1;
                ctx.stroke();
            }
        }
    }

    zoomText.textContent = Math.round(zoom * 100) + '%';
}

function updateStats() {
    colorMarked = {};

    marked.forEach(index => {
        const hex = imagePixels[index];
        colorMarked[hex] = (colorMarked[hex] || 0) + 1;
    });

    const markedCount = marked.size;
    const percent = totalPixels > 0 ? (markedCount / totalPixels) * 100 : 0;

    progressValue.textContent = percent.toFixed(1) + '%';
    markedValue.textContent = markedCount;
    leftValue.textContent = Math.max(0, totalPixels - markedCount);

    renderPalette();
}

function renderPalette() {
    const colors = Object.entries(colorStats)
        .sort((a, b) => b[1] - a[1]);

    paletteList.innerHTML = '';

    colors.forEach(([hex, total], i) => {
        const done = colorMarked[hex] || 0;

        const item = document.createElement('div');
        item.className = 'palette-item';
        item.innerHTML = `
            <div class="palette-color" style="background:${hex}"></div>
            <div class="palette-info">
                <div class="palette-name">DMC ${3000 + i}</div>
                <div class="palette-count">${done}/${total}</div>
            </div>
        `;
        paletteList.appendChild(item);
    });
}

canvas.addEventListener('click', (e) => {
    const rect = canvas.getBoundingClientRect();
    const size = canvas.width / gridWidth;

    const x = Math.floor((e.clientX - rect.left) / size);
    const y = Math.floor((e.clientY - rect.top) / size);

    if (x < 0 || y < 0 || x >= gridWidth || y >= gridHeight) return;

    const index = y * gridWidth + x;

    if (marked.has(index)) {
        marked.delete(index);
    } else {
        marked.add(index);
    }

    localStorage.setItem(storageKey, JSON.stringify([...marked]));

    updateStats();
    drawScheme();
});

document.getElementById('zoomIn').addEventListener('click', () => {
    zoom = Math.min(3, zoom + 0.1);
    drawScheme();
});

document.getElementById('zoomOut').addEventListener('click', () => {
    zoom = Math.max(0.5, zoom - 0.1);
    drawScheme();
});

document.getElementById('resetMarks').addEventListener('click', () => {
    if (!confirm('Сбросить отмеченные пиксели?')) return;

    marked.clear();
    localStorage.removeItem(storageKey);

    updateStats();
    drawScheme();
});

document.getElementById('saveProgress').addEventListener('click', async () => {
    const completion = totalPixels > 0 ? (marked.size / totalPixels) * 100 : 0;

    if (!userId) {
        alert('Войдите в аккаунт, чтобы сохранить прогресс');
        return;
    }

    const formData = new FormData();
    formData.append('completion', completion.toFixed(2));
    formData.append('pixels_marked', marked.size);

    const res = await fetch(location.href, {
        method: 'POST',
        body: formData
    });

    if (res.ok) {
        alert('Прогресс сохранён');
    } else {
        alert('Ошибка сохранения прогресса');
    }
});

function loadImageAsPixels() {
    const img = new Image();
    img.src = imageSrc;

    img.onload = () => {
        const tmp = document.createElement('canvas');
        const tctx = tmp.getContext('2d', { willReadFrequently: true });

        tmp.width = gridWidth;
        tmp.height = gridHeight;

        tctx.drawImage(img, 0, 0, gridWidth, gridHeight);

        const data = tctx.getImageData(0, 0, gridWidth, gridHeight).data;

        imagePixels = [];
        colorStats = {};

        for (let i = 0; i < data.length; i += 4) {
            const hex = colorKey(rgbToHex(data[i], data[i + 1], data[i + 2]));
            imagePixels.push(hex);
            colorStats[hex] = (colorStats[hex] || 0) + 1;
        }

        updateStats();
        drawScheme();
    };

    img.onerror = () => {
        alert('Не удалось загрузить изображение схемы');
    };
}

let seconds = 0;
setInterval(() => {
    seconds++;

    const h = String(Math.floor(seconds / 3600)).padStart(2, '0');
    const m = String(Math.floor((seconds % 3600) / 60)).padStart(2, '0');
    const s = String(seconds % 60).padStart(2, '0');

    document.getElementById('timerValue').textContent = `${h}:${m}:${s}`;
}, 1000);

loadImageAsPixels();
</script>

</body>
</html>
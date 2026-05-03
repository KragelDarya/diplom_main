<?php
// api/user.php - Авторизация и регистрация (DB + PHP session)
declare(strict_types=1);

session_start();

header("Content-Type: application/json; charset=UTF-8");

// Если фронт и API на одном домене — CORS не нужен.
// Если всё же есть другой домен/порт — лучше указать конкретный Origin, а не *.
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config/db.php';
$db = getDB();

function json_input(): array {
    $raw = file_get_contents("php://input");
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function respond(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = (string)($_GET['action'] ?? '');

if ($method === 'GET' && $action === 'me') {
    if (!empty($_SESSION['user_id'])) {
        respond(200, [
            "status" => "success",
            "user" => [
                "user_id" => (int)$_SESSION['user_id'],
                "name" => (string)($_SESSION['user_name'] ?? ''),
                "email" => (string)($_SESSION['user_email'] ?? '')
            ]
        ]);
    }
    respond(200, ["status" => "success", "user" => null]);
}

if ($method === 'POST' && $action === 'logout') {
    session_destroy();
    respond(200, ["status" => "success", "message" => "Выход выполнен"]);
}

if ($method !== 'POST') {
    respond(405, ["status" => "error", "message" => "Method Not Allowed"]);
}

$data = json_input();

if ($action === 'register') {
    $name = trim((string)($data['name'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    $password = (string)($data['password'] ?? '');

    if ($name === '' || $email === '' || $password === '') {
        respond(400, ["status" => "error", "message" => "Заполните имя, email и пароль"]);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(400, ["status" => "error", "message" => "Некорректный email"]);
    }
    if (mb_strlen($password) < 6) {
        respond(400, ["status" => "error", "message" => "Пароль должен быть минимум 6 символов"]);
    }

    // Сейчас оставляю пароли как есть, чтобы совпало с твоим myCollection.php.
    // Позже можно перейти на password_hash/password_verify.
    try {
        $stmt = $db->prepare("INSERT INTO user (name, email, password) VALUES (:name, :email, :password)");
        $stmt->execute([
            ':name' => $name,
            ':email' => $email,
            ':password' => $password
        ]);

        $userId = (int)$db->lastInsertId();

        // ставим сессию — теперь myCollection.php увидит пользователя
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_name'] = $name;
        $_SESSION['user_email'] = $email;

        respond(201, [
            "status" => "success",
            "message" => "Регистрация успешна",
            "user" => [
                "user_id" => $userId,
                "name" => $name,
                "email" => $email
            ]
        ]);
    } catch (PDOException $e) {
        // Дубликаты из-за uq_user_email или uq_user_name
        $msg = "Не удалось зарегистрироваться";
        if ((int)$e->errorInfo[1] === 1062) {
            if (str_contains($e->getMessage(), 'uq_user_email') || str_contains($e->getMessage(), 'email')) {
                $msg = "Пользователь с таким email уже существует";
            } elseif (str_contains($e->getMessage(), 'uq_user_name') || str_contains($e->getMessage(), 'name')) {
                $msg = "Пользователь с таким именем уже существует";
            } else {
                $msg = "Такой пользователь уже существует";
            }
            respond(409, ["status" => "error", "message" => $msg]);
        }

        respond(500, ["status" => "error", "message" => $msg, "error" => $e->getMessage()]);
    }
}

if ($action === 'login') {
    $email = trim((string)($data['email'] ?? ''));
    $password = (string)($data['password'] ?? '');

    if ($email === '' || $password === '') {
        respond(400, ["status" => "error", "message" => "Введите email и пароль"]);
    }

    $stmt = $db->prepare("SELECT user_id, name, email, password FROM user WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || (string)$user['password'] !== $password) {
        respond(401, ["status" => "error", "message" => "Неверный email или пароль"]);
    }

    $_SESSION['user_id'] = (int)$user['user_id'];
    $_SESSION['user_name'] = (string)$user['name'];
    $_SESSION['user_email'] = (string)$user['email'];

    respond(200, [
        "status" => "success",
        "message" => "Вход выполнен",
        "user" => [
            "user_id" => (int)$user['user_id'],
            "name" => (string)$user['name'],
            "email" => (string)$user['email']
        ]
    ]);
}

respond(400, ["status" => "error", "message" => "Unknown action"]);

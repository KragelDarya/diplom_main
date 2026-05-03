<?php
// api/progress.php - Работа с прогрессом
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

require_once '../config/db.php';

$db = getDB();

if (isset($_GET['user_id']) && isset($_GET['pattern_id'])) {
    $query = "SELECT * FROM progress WHERE user_id = :user_id AND pattern_id = :pattern_id";
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':user_id' => $_GET['user_id'],
        ':pattern_id' => $_GET['pattern_id']
    ]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode([
            "status" => "success",
            "data" => $stmt->fetch(PDO::FETCH_ASSOC)
        ]);
    } else {
        echo json_encode([
            "status" => "success",
            "data" => null,
            "message" => "Прогресс не найден"
        ]);
    }
}
?>
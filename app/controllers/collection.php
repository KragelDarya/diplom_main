<?php
// api/collection.php - Работа с коллекциями пользователей
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

require_once '../config/db.php';

$db = getDB();

if (isset($_GET['user_id'])) {
    $query = "SELECT 
                c.*,
                GROUP_CONCAT(
                    JSON_OBJECT(
                        'pattern_id', p.pattern_id,
                        'title', p.title,
                        'width', p.width,
                        'height', p.height,
                        'total_pixels', p.total_pixels,
                        'color_count', p.color_count,
                        'difficulty', p.difficulty,
                        'image_path', p.image_path
                    )
                ) as patterns
              FROM collection c
              LEFT JOIN collection_pattern cp ON c.collection_id = cp.collection_id
              LEFT JOIN pattern p ON cp.pattern_id = p.pattern_id
              WHERE c.user_id = :user_id
              GROUP BY c.collection_id";
    
    $stmt = $db->prepare($query);
    $stmt->execute([':user_id' => $_GET['user_id']]);
    
    $collections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Парсим JSON паттернов
    foreach ($collections as &$collection) {
        if ($collection['patterns']) {
            $collection['patterns'] = json_decode('[' . $collection['patterns'] . ']', true);
        } else {
            $collection['patterns'] = [];
        }
    }
    
    echo json_encode([
        "status" => "success",
        "data" => $collections
    ]);
}
?>
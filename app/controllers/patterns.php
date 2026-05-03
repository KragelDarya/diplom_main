<?php
// api/patterns.php - Получение схем из существующей БД
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

require_once '../config/db.php';

try {
    $db = getDB();
    
    // Получаем схемы с информацией о категориях
    $query = "SELECT 
                p.*,
                c.category_name,
                c.description as category_description
              FROM pattern p
              LEFT JOIN category c ON p.category_id = c.category_id
              WHERE p.is_active = 1
              ORDER BY p.created_at DESC
              LIMIT 200";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    
    $patterns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Форматируем данные для фронтенда
    foreach ($patterns as &$pattern) {
        $pattern['difficulty_text'] = match($pattern['difficulty']) {
            'beginner' => 'Легкий',
            'intermediate' => 'Средний',
            'advanced' => 'Сложный',
            default => $pattern['difficulty']
        };
        
        // Разбираем теги из JSON или строки
        if (!empty($pattern['tags'])) {
            $pattern['tags_array'] = json_decode($pattern['tags'], true) ?? explode(',', $pattern['tags']);
        } else {
            $pattern['tags_array'] = [];
        }
    }
    
    echo json_encode([
        "status" => "success",
        "data" => $patterns
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Ошибка получения схем",
        "error" => $e->getMessage()
    ]);
}
?>
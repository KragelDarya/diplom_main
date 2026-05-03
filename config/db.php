<?php
// config/db.php - Подключение к существующей базе данных

class Database {
    private $host = 'localhost';
    private $db_name = 'pixel_art_db'; // Название вашей БД
    private $username = 'root';        // Пользователь MySQL
    private $password = '0000';           // Пароль (в OpenServer по умолчанию пустой)
    private $conn;

    public function getConnection() {
        $this->conn = null;

        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->conn->exec("SET NAMES utf8mb4");
            
            // Проверка подключения
            $test = $this->conn->query("SELECT 1");
            if ($test) {
                error_log("База данных успешно подключена: " . $this->db_name);
            }
            
        } catch(PDOException $e) {
            error_log("Ошибка подключения к БД: " . $e->getMessage());
            die(json_encode([
                "error" => "Ошибка подключения к базе данных",
                "message" => $e->getMessage()
            ]));
        }

        return $this->conn;
    }
}

// Функция для быстрого получения подключения
function getDB() {
    static $db = null;
    if ($db === null) {
        $database = new Database();
        $db = $database->getConnection();
    }
    return $db;
}

// Функция для проверки существования таблиц
function checkDatabaseTables() {
    try {
        $db = getDB();
        $tables = ['user', 'category', 'pattern', 'collection', 'progress', 'palette', 'color'];
        $existingTables = [];
        
        foreach ($tables as $table) {
            $stmt = $db->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() > 0) {
                $existingTables[] = $table;
            }
        }
        
        error_log("Существующие таблицы: " . implode(", ", $existingTables));
        return $existingTables;
        
    } catch (Exception $e) {
        error_log("Ошибка проверки таблиц: " . $e->getMessage());
        return [];
    }
}

// Вызываем проверку при загрузке
checkDatabaseTables();
?>
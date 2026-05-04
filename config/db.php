<?php
// ============================================
// config/db.php — PDO Singleton Connection
// ============================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'quic1934_upgrade');
define('DB_USER', 'quic1934_zenhkm');
define('DB_PASS', '03Maret1990');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'QuizB');
define('APP_URL', 'https://up.quizb.my.id');  // <<< GANTI
define('APP_ENV', 'production');               // 'development' atau 'production'

class DB {
    private static ?PDO $instance = null;

    public static function getInstance(): PDO {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST, DB_NAME, DB_CHARSET
            );
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ];
            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                if (APP_ENV === 'development') {
                    die(json_encode(['error' => $e->getMessage()]));
                }
                http_response_code(500);
                die(json_encode(['error' => 'Database connection failed']));
            }
        }
        return self::$instance;
    }

    // Helper: run query and return all rows
    public static function all(string $sql, array $params = []): array {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // Helper: run query and return single row
    public static function one(string $sql, array $params = []): ?array {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // Helper: execute INSERT/UPDATE/DELETE, return affected rows
    public static function execute(string $sql, array $params = []): int {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    // Helper: get last inserted ID
    public static function lastId(): string {
        return self::getInstance()->lastInsertId();
    }
}

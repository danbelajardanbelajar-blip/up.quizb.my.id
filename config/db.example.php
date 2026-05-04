<?php
// ============================================
// config/db.php — PDO Singleton Connection
// COPY file ini menjadi db.php dan isi kredensial!
// ============================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'nama_database_anda');   // <<< GANTI
define('DB_USER', 'nama_user_anda');       // <<< GANTI
define('DB_PASS', 'password_anda');        // <<< GANTI
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'QuizB');
define('APP_ENV', 'production'); // 'development' | 'production'
define('APP_DEBUG', false);

// ============================================
// DB Class — PDO Singleton
// ============================================
class DB {
    private static ?PDO $instance = null;

    public static function conn(): PDO {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST, DB_NAME, DB_CHARSET
            );
            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (PDOException $e) {
                if (APP_DEBUG) {
                    die(json_encode(['success' => false, 'message' => 'DB Error: ' . $e->getMessage()]));
                }
                die(json_encode(['success' => false, 'message' => 'Database connection failed']));
            }
        }
        return self::$instance;
    }

    public static function all(string $sql, array $params = []): array {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function one(string $sql, array $params = []): array|false {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    public static function run(string $sql, array $params = []): int {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
}

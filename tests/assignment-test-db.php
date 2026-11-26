<?php
// Test-only SQLite adapter. All application DML and transaction boundaries run unchanged.
class DB {
    static ?PDO $pdo = null;
    static string $failOn = '';
    static bool $transactional = true;
    static function conn(): PDO { return self::$pdo; }
    static function lastId(): string { return self::$pdo->lastInsertId(); }
    static function sql(string $sql): string {
        $sql = preg_replace('/\s+FOR UPDATE\b/i', '', $sql);
        $sql = preg_replace('/\) ENGINE=InnoDB.*$/s', ')', $sql);
        $sql = str_replace('INT AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
        $sql = str_replace('ON DUPLICATE KEY UPDATE submission_id = VALUES(submission_id)', 'ON CONFLICT(user_id, submission_id) DO NOTHING', $sql);
        $sql = str_replace('FROM_UNIXTIME(?)', "datetime(?, 'unixepoch')", $sql);
        return str_replace('NOW()', "datetime('now')", $sql);
    }
    static function execute($sql, $params = []) {
        // Model MySQL's implicit DDL commit; SQLite alone would hide this failure mode.
        if (preg_match('/^\s*(CREATE|ALTER|DROP)\b/i', $sql) && self::$pdo->inTransaction()) self::$pdo->commit();
        if (self::$failOn !== '' && str_contains($sql, self::$failOn)) throw new PDOException('Injected storage failure');
        $stmt = self::$pdo->prepare(self::sql($sql)); $stmt->execute($params); return $stmt->rowCount();
    }
    static function one($sql, $params = []) {
        if (str_starts_with($sql, 'SHOW COLUMNS FROM users')) return ['Field'=>'existing_reference_column'];
        if (str_contains($sql, 'SELECT DATABASE()')) return ['database_name'=>'test', 'server_name'=>'isolated'];
        $stmt = self::$pdo->prepare(self::sql($sql)); $stmt->execute($params); return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    static function all($sql, $params = []) {
        if (str_contains($sql, 'information_schema.TABLES')) return array_map(fn($table)=>['table_name'=>$table,'engine'=>self::$transactional?'InnoDB':'MyISAM'], ['assignments','assignment_submissions','attempts','attempt_answers','users','quizzes','assignment_attempts','submission_requests','proctoring_receipts']);
        $stmt = self::$pdo->prepare(self::sql($sql)); $stmt->execute($params); return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

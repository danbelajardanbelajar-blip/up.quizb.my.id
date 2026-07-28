<?php
require 'config/db.php';
$pdo = DB::conn();
echo "<h1>Database Migration</h1>";
try {
    $pdo->exec("ALTER TABLE quizzes ADD COLUMN require_camera TINYINT(1) DEFAULT 0");
    echo "<p>Success: Added require_camera to quizzes</p>";
} catch (Exception $e) {
    echo "<p>Skipped: require_camera (maybe already exists? " . $e->getMessage() . ")</p>";
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS attempt_snapshots (
        id INT AUTO_INCREMENT PRIMARY KEY,
        attempt_id INT NOT NULL,
        image_path VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (attempt_id) REFERENCES attempts(id) ON DELETE CASCADE
    )");
    echo "<p>Success: Created attempt_snapshots table</p>";
} catch (Exception $e) {
    echo "<p>Failed: " . $e->getMessage() . "</p>";
}
echo "<p>Done.</p>";

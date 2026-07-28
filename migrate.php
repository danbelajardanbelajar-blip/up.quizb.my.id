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
        attempt_id BIGINT NOT NULL,
        image_path VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(attempt_id)
    )");
    echo "<p>Success: Created attempt_snapshots table</p>";
} catch (Exception $e) {
    echo "<p>Failed: " . $e->getMessage() . "</p>";
}

try {
    DB::execute("ALTER TABLE assignments ADD COLUMN require_camera TINYINT(1) NOT NULL DEFAULT 0");
    echo "Column require_camera added to assignments table.<br>";
} catch (Exception $e) {
    echo "Error (assignments.require_camera): " . $e->getMessage() . "<br>";
}

echo "Migration finished.";
echo "<p>Done.</p>";

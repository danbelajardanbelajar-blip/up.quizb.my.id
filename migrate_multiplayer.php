<?php
require 'config/db.php';
try {
    DB::execute("CREATE TABLE IF NOT EXISTS challenge_participants (
        id INT AUTO_INCREMENT PRIMARY KEY,
        challenge_id INT NOT NULL,
        user_id INT NOT NULL,
        is_host TINYINT(1) DEFAULT 0,
        status VARCHAR(20) DEFAULT 'pending',
        progress VARCHAR(255) NULL DEFAULT NULL,
        attempt_id INT NULL DEFAULT NULL,
        score_final INT NULL DEFAULT NULL,
        time_taken INT NULL DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY(challenge_id),
        KEY(user_id)
    )");
    echo "<h1>Migrasi Tabel Multiplayer Berhasil!</h1>";
} catch (Exception $e) { echo "Error: " . $e->getMessage(); }
?>

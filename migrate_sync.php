<?php
require 'config/db.php';
try {
    DB::execute("ALTER TABLE challenges ADD COLUMN challenger_progress VARCHAR(255) NULL DEFAULT NULL AFTER challenged_attempt_id");
    DB::execute("ALTER TABLE challenges ADD COLUMN challenged_progress VARCHAR(255) NULL DEFAULT NULL AFTER challenger_progress");
    DB::execute("ALTER TABLE challenges ADD COLUMN start_time DATETIME NULL DEFAULT NULL AFTER expires_at");
    echo "<h1>Migrasi Berhasil!</h1>";
} catch (Exception $e) { echo "Error: " . $e->getMessage(); }
?>

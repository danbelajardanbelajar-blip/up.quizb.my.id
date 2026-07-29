<?php
require_once 'config/db.php';
$pdo = DB::conn();
$stmt = $pdo->query("SHOW COLUMNS FROM assignments LIKE 'require_camera'");
$row = $stmt->fetch();
if ($row) {
    echo "Column exists: " . json_encode($row);
} else {
    echo "Column does NOT exist in assignments table!";
}

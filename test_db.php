<?php
require 'config/db.php';
$pdo = DB::conn();
$stmt = $pdo->query('SELECT image_path FROM attempt_snapshots ORDER BY id DESC LIMIT 1');
$row = $stmt->fetch();
if ($row) {
    echo "DB PATH: " . $row['image_path'] . "\n";
    $file = __DIR__ . '/' . $row['image_path'];
    echo "FILE EXISTS: " . (file_exists($file) ? 'YES' : 'NO') . "\n";
    if (file_exists($file)) echo "FILE SIZE: " . filesize($file) . "\n";
} else {
    echo "NO ROWS\n";
}

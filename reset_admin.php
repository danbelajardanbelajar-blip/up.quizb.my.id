<?php
// ============================================
// reset_admin.php — Reset Admin Password (HAPUS SETELAH DIGUNAKAN!)
// ============================================
require_once __DIR__ . '/config/db.php';

$email    = 'admin@quizb.my.id';
$password = 'admin123';
$hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

// Update password admin
$stmt = DB::conn()->prepare(
    'UPDATE users SET password_hash = ? WHERE email = ?'
);
$stmt->execute([$hash, $email]);

$affected = $stmt->rowCount();

// Verifikasi
$user = DB::one('SELECT id, name, email, role, password_hash FROM users WHERE email = ?', [$email]);

echo '<pre>';
echo "=== RESET ADMIN PASSWORD ===\n\n";

if ($affected > 0) {
    echo "✅ Password berhasil direset!\n\n";
} else {
    echo "⚠️  Tidak ada baris yang diupdate. Email tidak ditemukan?\n\n";
}

echo "Email  : {$user['email']}\n";
echo "Name   : {$user['name']}\n";
echo "Role   : {$user['role']}\n";
echo "Hash   : {$user['password_hash']}\n\n";

$verify = password_verify($password, $user['password_hash']);
echo "Verifikasi password '$password': " . ($verify ? "✅ COCOK" : "❌ TIDAK COCOK") . "\n\n";
echo "=== HAPUS FILE INI SETELAH SELESAI! ===\n";
echo '</pre>';

<?php
// =========================================================================
// create_reviewer.php — Seeder Akun Reviewer Google Play
// Proteksi Keamanan: Hanya dapat dijalankan via CLI Terminal atau sesi Admin
// =========================================================================

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

$isCli = (php_sapi_name() === 'cli');
$isAuthorized = false;

if ($isCli) {
    $isAuthorized = true;
} else {
    // Jika diakses melalui browser / HTTP:
    // 1. Cek apakah pengguna sudah login sebagai Admin
    if (isLoggedIn()) {
        $currentUser = getCurrentUser();
        if ($currentUser && ($currentUser['role'] ?? '') === 'admin') {
            $isAuthorized = true;
        }
    }

    // 2. Fallback cek secret key via query param (?key=...) jika diset
    $providedKey = $_GET['key'] ?? '';
    $envKey = defined('APP_KEY') ? APP_KEY : (getenv('APP_KEY') ?: '');
    if (!empty($providedKey) && !empty($envKey) && hash_equals($envKey, $providedKey)) {
        $isAuthorized = true;
    }
}

if (!$isAuthorized) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error'   => 'Forbidden: Skrip seeder ini hanya dapat dieksekusi melalui CLI terminal atau sesi Admin yang sah.',
        'code'    => 403
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$email = 'reviewer@quizb.my.id';
$password = 'QuizBReviewer2026!';
$name = 'Google Play Reviewer';
$hash = password_hash($password, PASSWORD_BCRYPT);

$existing = DB::one('SELECT id FROM users WHERE email = ?', [$email]);
if ($existing) {
    DB::execute(
        'UPDATE users SET name = ?, password_hash = ?, is_active = 1, email_verified_at = NOW(), has_onboarded = 1, role = "pengajar" WHERE email = ?',
        [$name, $hash, $email]
    );
    $msg = "Reviewer account updated successfully.\n";
} else {
    DB::execute(
        'INSERT INTO users (name, email, password_hash, role, is_active, email_verified_at, has_onboarded) VALUES (?, ?, ?, "pengajar", 1, NOW(), 1)',
        [$name, $email, $hash]
    );
    $msg = "Reviewer account created successfully.\n";
}

if ($isCli) {
    echo $msg;
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'message' => trim($msg)]);
}

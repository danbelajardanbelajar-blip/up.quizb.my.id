<?php
// ============================================
// verify-email.php
// Menerima klik link dari email konfirmasi.
// Validasi token → login user → redirect ke SPA
// ============================================

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/response.php';
require_once __DIR__ . '/includes/helpers.php';

startSecureSession();

$token = trim($_GET['token'] ?? '');

// ── Helper redirect dengan pesan flash ──────────────────────────────
function redirectToApp(string $hash, string $flashType = '', string $flashMsg = ''): never {
    if ($flashType && $flashMsg) {
        $_SESSION['flash_type'] = $flashType;
        $_SESSION['flash_msg']  = $flashMsg;
    }
    header('Location: ' . APP_URL . '/' . ltrim($hash, '/'));
    exit;
}

// ── Validasi format token ────────────────────────────────────────────
if (!$token || strlen($token) !== 64 || !ctype_xdigit($token)) {
    redirectToApp('#/verify-error?reason=invalid');
}

$tokenHash = hash('sha256', $token);

$user = DB::one(
    "SELECT id, name, email, role, is_active
     FROM users
     WHERE email_verification_token = ?",
    [$tokenHash]
);

// ── Token tidak ditemukan ────────────────────────────────────────────
if (!$user) {
    // Mungkin sudah dipakai sebelumnya
    redirectToApp('#/verify-error?reason=used');
}

// ── Sudah aktif (klik ulang link yang sama) ──────────────────────────
if ($user['is_active']) {
    loginUser($user);
    redirectToApp('#/onboarding', 'success', 'Email sudah terverifikasi. Selamat datang kembali!');
}

// ── Aktifkan akun ────────────────────────────────────────────────────
DB::execute(
    "UPDATE users
     SET is_active = 1,
         email_verified_at = NOW(),
         email_verification_token = NULL,
         updated_at = NOW()
     WHERE id = ?",
    [(int)$user['id']]
);

// Ambil data terbaru
$verified = DB::one('SELECT id, name, email, role FROM users WHERE id = ?', [(int)$user['id']]);

// ── Auto-login ───────────────────────────────────────────────────────
loginUser($verified);

// ── Broadcast notifikasi pengguna baru ──────────────────────────────
$newUserId = (int)$verified['id'];
try {
    require_once __DIR__ . '/includes/helpers.php';
    $others = DB::all(
        "SELECT id FROM users WHERE id != ? AND is_active = 1",
        [$newUserId]
    );
    foreach ($others as $other) {
        if (function_exists('pushNotification')) {
            pushNotification(
                (int)$other['id'],
                'new_user',
                '👤 ' . $verified['name'] . ' bergabung',
                $verified['name'] . ' baru saja mendaftar di ' . APP_NAME . '.',
                '/public-history?user_id=' . $newUserId
            );
        }
    }
} catch (\Throwable $e) {
    error_log('[verify-email] notif error: ' . $e->getMessage());
}

// ── Set flag untuk onboarding ────────────────────────────────────────
$_SESSION['is_new_user'] = true;

// ── Redirect ke halaman pilih role ──────────────────────────────────
redirectToApp('#/onboarding', 'success', 'Email berhasil dikonfirmasi! Selamat datang 🎉');

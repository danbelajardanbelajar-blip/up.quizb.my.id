<?php
// ============================================
// includes/auth.php — Session & Auth Helpers
// ============================================

function startSecureSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 86400 * 7,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('QUIZB_SESSION');
        session_start();
    }
}

function requireAuth(): array {
    startSecureSession();
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode(['success' => false, 'error' => 'Unauthorized', 'message' => 'Silakan login untuk mengakses fitur ini', 'code' => 401]));
    }
    return [
        'id'   => (int) $_SESSION['user_id'],
        'name' => $_SESSION['user_name'],
        'role' => $_SESSION['user_role'],
    ];
}

function requireAdmin(): array {
    $user = requireAuth();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        die(json_encode(['error' => 'Forbidden', 'code' => 403]));
    }
    return $user;
}

function requirePengajar(): array {
    $user = requireAuth();
    if (!in_array($user['role'], ['pengajar', 'admin'])) {
        http_response_code(403);
        die(json_encode(['error' => 'Hanya pengajar yang dapat mengakses fitur ini', 'code' => 403]));
    }
    return $user;
}

function isPengajar(): bool {
    startSecureSession();
    return !empty($_SESSION['user_id']) &&
           in_array($_SESSION['user_role'] ?? '', ['pengajar', 'admin']);
}

function isLoggedIn(): bool {
    startSecureSession();
    return !empty($_SESSION['user_id']);
}

function getCurrentUser(): ?array {
    startSecureSession();
    if (empty($_SESSION['user_id'])) return null;
    return [
        'id'   => (int) $_SESSION['user_id'],
        'name' => $_SESSION['user_name'],
        'role' => $_SESSION['user_role'],
    ];
}

// ============================================
// Ambil nama kota dari IP (untuk user anonim)
// ============================================
function getCityFromIp(string $ip): string {
    if ($ip === '127.0.0.1' || $ip === '::1'
        || str_starts_with($ip, '192.168.')
        || str_starts_with($ip, '10.')) {
        return 'Lokal';
    }
    try {
        $ctx  = stream_context_create(['http' => ['timeout' => 2]]);
        $json = @file_get_contents("http://ip-api.com/json/{$ip}?fields=city,status", false, $ctx);
        if ($json) {
            $data = json_decode($json, true);
            if (!empty($data['city']) && ($data['status'] ?? '') === 'success') {
                return $data['city'];
            }
        }
    } catch (\Throwable $e) {}
    return 'Indonesia';
}

// ============================================
// Buat atau ambil user anonim berdasarkan IP
// Nama: "Anonim <Kota>" — is_active = 0
// ============================================
function getOrCreateAnonUser(): array {
    startSecureSession();

    // Sudah ada di session browser ini?
    if (!empty($_SESSION['anon_user_id'])) {
        return [
            'id'   => (int) $_SESSION['anon_user_id'],
            'name' => $_SESSION['anon_user_name'],
            'role' => 'user',
        ];
    }

    // Ambil IP klien
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
       ?? $_SERVER['HTTP_X_FORWARDED_FOR']
       ?? $_SERVER['REMOTE_ADDR']
       ?? '0.0.0.0';
    $ip = trim(explode(',', $ip)[0]);

    $anonEmail = 'anon_' . md5($ip) . '@quizb.guest';

    // Cek apakah sudah ada untuk IP ini
    $existing = DB::one(
        "SELECT id, name FROM users WHERE email = ?",
        [$anonEmail]
    );

    if ($existing) {
        $_SESSION['anon_user_id']   = $existing['id'];
        $_SESSION['anon_user_name'] = $existing['name'];
        return ['id' => (int)$existing['id'], 'name' => $existing['name'], 'role' => 'user'];
    }

    // Buat user anonim baru
    $city  = getCityFromIp($ip);
    $name  = 'Anonim ' . $city;
    $hash  = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

    DB::execute(
        "INSERT INTO users (name, email, password_hash, role, is_active) VALUES (?,?,?,'user',0)",
        [$name, $anonEmail, $hash]
    );
    $userId = (int) DB::lastId();

    $_SESSION['anon_user_id']   = $userId;
    $_SESSION['anon_user_name'] = $name;

    return ['id' => $userId, 'name' => $name, 'role' => 'user'];
}

// ============================================
// Ambil user (login atau anonim) — tanpa 401
// ============================================
function getCurrentUserOrAnon(): array {
    startSecureSession();
    if (!empty($_SESSION['user_id'])) {
        return [
            'id'      => (int) $_SESSION['user_id'],
            'name'    => $_SESSION['user_name'],
            'role'    => $_SESSION['user_role'],
            'is_anon' => false,
        ];
    }
    $anon = getOrCreateAnonUser();
    $anon['is_anon'] = true;
    return $anon;
}

function loginUser(array $user): void {
    startSecureSession();
    session_regenerate_id(true);
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $user['role'];
    // Hapus anon session setelah login
    unset($_SESSION['anon_user_id'], $_SESSION['anon_user_name']);
}

function logoutUser(): void {
    startSecureSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

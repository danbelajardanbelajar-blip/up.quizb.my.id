<?php
// ============================================
// api/auth.php — Auth Endpoints
// ============================================

function auth_login(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);

    $body  = getBody();
    $email = sanitizeString($body['email'] ?? '');
    $pass  = $body['password'] ?? '';

    if (!$email || !$pass) jsonError('Email dan password wajib diisi');

    // Rate limiting (simple session-based)
    $key = 'login_attempts_' . md5($email);
    $_SESSION[$key] = ($_SESSION[$key] ?? 0) + 1;
    if ($_SESSION[$key] > 10) {
        jsonError('Terlalu banyak percobaan login. Coba lagi nanti.', 429);
    }

    $user = DB::one(
        'SELECT id, name, email, password_hash, role, is_active FROM users WHERE email = ?',
        [$email]
    );

    if (!$user || !password_verify($pass, $user['password_hash'])) {
        jsonError('Email atau password salah', 401);
    }
    if (!$user['is_active']) jsonError('Akun dinonaktifkan', 403);

    unset($_SESSION[$key]);
    loginUser($user);

    jsonSuccess([
        'id'         => (int)$user['id'],
        'name'       => $user['name'],
        'email'      => $user['email'],
        'role'       => $user['role'],
        'csrf_token' => generateCsrfToken(),
    ], 'Login berhasil');
}

function auth_register(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);

    $body  = getBody();
    $name  = sanitizeString($body['name']  ?? '');
    $email = sanitizeString($body['email'] ?? '');
    $pass  = $body['password'] ?? '';

    if (!$name || !$email || !$pass) jsonError('Semua field wajib diisi');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError('Format email tidak valid');
    if (strlen($pass) < 6) jsonError('Password minimal 6 karakter');
    if (strlen($name) < 2) jsonError('Nama minimal 2 karakter');

    $exists = DB::one('SELECT id FROM users WHERE email = ?', [$email]);
    if ($exists) jsonError('Email sudah terdaftar', 409);

    $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
    DB::execute(
        'INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)',
        [$name, $email, $hash]
    );

    $user = DB::one('SELECT id, name, email, role FROM users WHERE email = ?', [$email]);
    loginUser($user);

    jsonSuccess([
        'id'         => (int)$user['id'],
        'name'       => $user['name'],
        'email'      => $user['email'],
        'role'       => $user['role'],
        'csrf_token' => generateCsrfToken(),
    ], 'Registrasi berhasil');
}

function auth_logout(): void {
    logoutUser();
    jsonSuccess(null, 'Logout berhasil');
}

function auth_me(): void {
    $user = getCurrentUser();
    if (!$user) jsonError('Tidak login', 401);

    $data = DB::one(
        'SELECT id, name, email, role, avatar, total_points, quizzes_taken, created_at FROM users WHERE id = ?',
        [$user['id']]
    );
    $data['csrf'] = generateCsrfToken();
    jsonSuccess($data);
}

function auth_csrf(): void {
    jsonSuccess(['token' => generateCsrfToken()]);
}

function auth_update_profile(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    $user = requireAuth();

    $body     = getBody();
    $name     = sanitizeString($body['name'] ?? '');
    $password = $body['password']     ?? '';
    $newPass  = $body['password_new'] ?? '';

    if (!$name || strlen($name) < 2) jsonError('Nama minimal 2 karakter');

    $existing = DB::one('SELECT * FROM users WHERE id = ?', [$user['id']]);
    if (!$existing) jsonError('User tidak ditemukan', 404);

    // Update name
    DB::conn()->prepare('UPDATE users SET name = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$name, $user['id']]);

    // Update session name
    $_SESSION['user_name'] = $name;

    // Optionally change password
    if ($newPass) {
        if (strlen($newPass) < 6) jsonError('Password baru minimal 6 karakter');
        if (!$password) jsonError('Masukkan password saat ini untuk menggantinya');
        if (!password_verify($password, $existing['password_hash'])) {
            jsonError('Password saat ini salah', 401);
        }
        $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
        DB::conn()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([$hash, $user['id']]);
    }

    jsonSuccess(['name' => $name, 'message' => 'Profil berhasil diperbarui']);
}

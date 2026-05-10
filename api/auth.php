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

    // — Broadcast notifikasi "pengguna baru" ke semua user aktif lain (kecuali si pendaftar)
    $newUserId = (int)$user['id'];
    $others = DB::all(
        "SELECT id FROM users WHERE id != ? AND is_active = 1",
        [$newUserId]
    );
    foreach ($others as $other) {
        pushNotification(
            (int)$other['id'],
            'new_user',
            '👤 Pengguna baru bergabung',
            $name . ' baru saja mendaftar di QuizB.',
            '/public-history?user_id=' . $newUserId
        );
    }

    jsonSuccess([
        'id'          => (int)$user['id'],
        'name'        => $user['name'],
        'email'       => $user['email'],
        'role'        => $user['role'],
        'csrf_token'  => generateCsrfToken(),
        'is_new_user' => true,
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
        'SELECT id, name, email, role, avatar, total_points, quizzes_taken,
                quiz_questions_limit, shuffle_questions, shuffle_options, created_at
         FROM users WHERE id = ?',
        [$user['id']]
    );
    $data['quiz_questions_limit'] = (int)($data['quiz_questions_limit'] ?? 10);
    $data['shuffle_questions']    = (bool)(int)($data['shuffle_questions'] ?? 1);
    $data['shuffle_options']      = (bool)(int)($data['shuffle_options']   ?? 1);
    $data['csrf'] = generateCsrfToken();
    jsonSuccess($data);
}

function auth_update_settings(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    $user = requireAuth();

    $body  = getBody();
    $limit            = isset($body['quiz_questions_limit']) ? (int)$body['quiz_questions_limit'] : null;
    $shuffleQuestions = isset($body['shuffle_questions'])    ? (int)(bool)$body['shuffle_questions'] : null;
    $shuffleOptions   = isset($body['shuffle_options'])      ? (int)(bool)$body['shuffle_options']   : null;

    if ($limit === null && $shuffleQuestions === null && $shuffleOptions === null) {
        jsonError('Tidak ada data pengaturan yang dikirim');
    }
    if ($limit !== null && ($limit < 1 || $limit > 100)) {
        jsonError('Jumlah soal harus antara 1 dan 100');
    }

    // Bangun SET clause secara dinamis
    $sets   = [];
    $params = [];
    if ($limit !== null)            { $sets[] = 'quiz_questions_limit = ?'; $params[] = $limit; }
    if ($shuffleQuestions !== null) { $sets[] = 'shuffle_questions = ?';    $params[] = $shuffleQuestions; }
    if ($shuffleOptions   !== null) { $sets[] = 'shuffle_options = ?';      $params[] = $shuffleOptions; }
    $sets[]   = 'updated_at = NOW()';
    $params[] = $user['id'];

    DB::conn()->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')
        ->execute($params);

    jsonSuccess([
        'quiz_questions_limit' => $limit,
        'shuffle_questions'    => $shuffleQuestions !== null ? (bool)$shuffleQuestions : null,
        'shuffle_options'      => $shuffleOptions   !== null ? (bool)$shuffleOptions   : null,
        'message'              => 'Pengaturan berhasil disimpan',
    ]);
}

function auth_csrf(): void {
    jsonSuccess(['token' => generateCsrfToken()]);
}

// POST — set role saat onboarding pertama kali (user baru)
function auth_set_role(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    $user = requireAuth();
    $body = getBody();
    $role = trim($body['role'] ?? '');

    $allowed = ['user', 'pelajar', 'pengajar'];
    if (!in_array($role, $allowed)) jsonError('Role tidak valid');

    DB::execute('UPDATE users SET role = ?, updated_at = NOW() WHERE id = ?', [$role, $user['id']]);

    // Sinkronkan session
    $_SESSION['user_role'] = $role;

    jsonSuccess(['role' => $role], 'Role berhasil disimpan');
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

<?php
// ============================================
// api/auth.php — Auth Endpoints
// ============================================

// mailer.php di-load hanya saat dibutuhkan (register / resend / verify)
// agar tidak menyebabkan 500 pada endpoint lain jika PHPMailer belum ada.

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
        'SELECT id, name, email, password_hash, role, is_active, email_verified_at FROM users WHERE email = ?',
        [$email]
    );

    if (!$user || !password_verify($pass, $user['password_hash'])) {
        jsonError('Email atau password salah', 401);
    }

    // Akun belum verifikasi email
    if (!$user['is_active'] && empty($user['email_verified_at'])) {
        jsonError('Akun belum diverifikasi. Silakan cek email kamu untuk link konfirmasi.', 403);
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

function includeMailer(): void {
    $mailerPath = __DIR__ . '/../includes/mailer.php';
    if (!file_exists($mailerPath)) {
        error_log('[Auth] Mailer helper tidak ditemukan: ' . $mailerPath);
        jsonError('Mailer library tidak tersedia. Hubungi administrator.', 500);
    }
    try {
        require_once $mailerPath;
    } catch (\Throwable $e) {
        error_log('[Auth] Gagal memuat mailer: ' . $e->getMessage());
        jsonError('Mailer library tidak tersedia. Hubungi administrator.', 500);
    }
}

function auth_register(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    includeMailer();

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

    // Generate token verifikasi (32 bytes = 64 karakter hex)
    $token     = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);   // simpan hash-nya di DB

    $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
    DB::execute(
        'INSERT INTO users (name, email, password_hash, is_active, email_verification_token) VALUES (?, ?, ?, 0, ?)',
        [$name, $email, $hash, $tokenHash]
    );

    // Kirim email konfirmasi
    try {
        sendVerificationEmail($email, $name, $token);
    } catch (\Throwable $e) {
        // Jika gagal kirim email, hapus user agar bisa daftar ulang
        DB::execute('DELETE FROM users WHERE email = ?', [$email]);
        error_log('[Register] Gagal kirim email ke ' . $email . ': ' . $e->getMessage());
        jsonError('Gagal mengirim email konfirmasi. Periksa koneksi server atau coba lagi.', 500);
    }

    jsonSuccess([
        'email_sent' => true,
        'email'      => $email,
        'message'    => 'Registrasi berhasil! Cek email kamu untuk link konfirmasi.',
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

function ensureGoogleIdColumnExists(): void {
    $column = DB::one("SHOW COLUMNS FROM users LIKE 'google_id'");
    if (!$column) {
        DB::execute("ALTER TABLE users ADD COLUMN google_id VARCHAR(255) UNIQUE DEFAULT NULL AFTER email");
    }
}

function fetchGoogleJson(string $url, array $postFields = [], array $headers = []): array {
    $options = [
        'http' => [
            'method'  => $postFields ? 'POST' : 'GET',
            'header'  => array_merge([
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ], $headers),
            'content' => $postFields ? http_build_query($postFields) : null,
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        if ($postFields) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        }
        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            jsonError('Gagal melakukan permintaan ke Google: ' . ($curlError ?: 'Unknown error'), 500);
        }
    } else {
        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            jsonError('Gagal melakukan permintaan ke Google, cURL tidak tersedia', 500);
        }
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        jsonError('Respon Google tidak valid: ' . substr($response ?? '', 0, 512), 500);
    }
    return $data;
}

function auth_google(): void {
    $mode = $_GET['mode'] ?? $_GET['state'] ?? 'login';
    if (!in_array($mode, ['login', 'register'])) {
        jsonError('Mode tidak valid', 400);
    }

    // Google OAuth configuration
    $clientId = GOOGLE_CLIENT_ID;
    $clientSecret = GOOGLE_CLIENT_SECRET;
    $redirectUri = APP_URL . '/api/auth/google_callback';

    if (!$clientId || !$clientSecret || str_starts_with($clientId, 'YOUR_') || str_starts_with($clientSecret, 'YOUR_')) {
        jsonError('Google OAuth belum dikonfigurasi. Silakan isi GOOGLE_CLIENT_ID dan GOOGLE_CLIENT_SECRET pada config/db.php', 500);
    }

    ensureGoogleIdColumnExists();

    if (isset($_GET['code'])) {
        // Callback dari Google
        $code = $_GET['code'];
        $tokenUrl = 'https://oauth2.googleapis.com/token';
        $postData = [
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code'
        ];

        $tokenData = fetchGoogleJson($tokenUrl, $postData);
        if (empty($tokenData['access_token'])) {
            $errorDetails = $tokenData['error_description'] ?? ($tokenData['error'] ?? 'Tidak ada access_token');
            jsonError('Gagal mendapatkan token dari Google: ' . $errorDetails, 500);
        }

        // Get user info
        $userInfoUrl = 'https://www.googleapis.com/oauth2/v2/userinfo';
        $userResponse = fetchGoogleJson($userInfoUrl, [], ['Authorization: Bearer ' . $tokenData['access_token']]);
        if (empty($userResponse['id'])) {
            $errorDetails = $userResponse['error_description'] ?? ($userResponse['error'] ?? 'Data user tidak lengkap');
            jsonError('Gagal mendapatkan data user dari Google: ' . $errorDetails, 500);
        }

        $googleId = $userResponse['id'];
        $email = $userResponse['email'] ?? '';
        $name = $userResponse['name'] ?? '';

        if (!$email) {
            jsonError('Google tidak menyediakan alamat email', 500);
        }

        // Check if user exists
        $user = DB::one('SELECT id, name, email, role, google_id FROM users WHERE google_id = ? OR email = ?', [$googleId, $email]);

        if ($user) {
            // User exists, login
            if (!empty($user['google_id']) && $user['google_id'] != $googleId) {
                jsonError('Email sudah terdaftar dengan akun lain', 409);
            }
            // Update google_id if not set
            if (empty($user['google_id'])) {
                DB::execute('UPDATE users SET google_id = ? WHERE id = ?', [$googleId, $user['id']]);
            }
            loginUser($user);
            header('Location: ' . APP_URL . '/#/dashboard');
            exit;
        }

        // New user: auto-register when Google account is not found
        DB::execute(
            'INSERT INTO users (name, email, google_id) VALUES (?, ?, ?)',
            [$name, $email, $googleId]
        );
        $newUser = DB::one('SELECT id, name, email, role FROM users WHERE google_id = ?', [$googleId]);
        loginUser($newUser);
        header('Location: ' . APP_URL . '/#/google-setup');
        exit;
    }

    // Redirect to Google for authentication
    $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'scope' => 'openid email profile',
        'response_type' => 'code',
        'state' => $mode
    ]);
    header('Location: ' . $authUrl);
    exit;
}

function auth_google_callback(): void {
    // Handle Google OAuth callback
    auth_google();
}

// ============================================
// Verifikasi token dari link email
// GET /api.php?action=auth.verify_email&token=XXX
// ============================================
function auth_verify_email(): void {
    includeMailer();
    $token = trim($_GET['token'] ?? $_POST['token'] ?? '');
    if (!$token || strlen($token) !== 64 || !ctype_xdigit($token)) {
        jsonError('Token tidak valid', 400);
    }
    $tokenHash = hash('sha256', $token);
    $user = DB::one(
        "SELECT id, name, email, role, email_verification_token, is_active FROM users WHERE email_verification_token = ?",
        [$tokenHash]
    );
    if (!$user) {
        jsonError('Link konfirmasi tidak valid atau sudah digunakan.', 400);
    }
    if ($user['is_active']) {
        loginUser($user);
        jsonSuccess([
            'id'          => (int)$user['id'],
            'name'        => $user['name'],
            'email'       => $user['email'],
            'role'        => $user['role'],
            'is_new_user' => true,
            'csrf_token'  => generateCsrfToken(),
        ], 'Email sudah terverifikasi. Login berhasil.');
    }
    DB::execute(
        "UPDATE users SET is_active = 1, email_verified_at = NOW(), email_verification_token = NULL, updated_at = NOW() WHERE id = ?",
        [(int)$user['id']]
    );
    $verified = DB::one('SELECT id, name, email, role FROM users WHERE id = ?', [(int)$user['id']]);
    loginUser($verified);
    $newUserId = (int)$verified['id'];
    $others = DB::all("SELECT id FROM users WHERE id != ? AND is_active = 1", [$newUserId]);
    foreach ($others as $o) {
        pushNotification((int)$o['id'], 'new_user',
            '👤 ' . $verified['name'] . ' bergabung',
            $verified['name'] . ' baru saja mendaftar di ' . APP_NAME . '.',
            '/public-history?user_id=' . $newUserId
        );
    }
    jsonSuccess([
        'id'          => (int)$verified['id'],
        'name'        => $verified['name'],
        'email'       => $verified['email'],
        'role'        => $verified['role'],
        'is_new_user' => true,
        'csrf_token'  => generateCsrfToken(),
    ], 'Email berhasil dikonfirmasi! Selamat datang 🎉');
}

// ============================================
// Kirim ulang email verifikasi
// POST /api.php?action=auth.resend_verification
// ============================================
function auth_resend_verification(): void {
    includeMailer();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    $body  = getBody();
    $email = sanitizeString($body['email'] ?? '');
    if (!$email) jsonError('Email wajib diisi');
    $user = DB::one("SELECT id, name, email, is_active FROM users WHERE email = ?", [$email]);
    if (!$user || $user['is_active']) {
        jsonSuccess(null, 'Jika email terdaftar dan belum diverifikasi, kami akan mengirim ulang konfirmasi.');
    }
    $rlKey = 'resend_email_' . md5($email);
    if (!empty($_SESSION[$rlKey]) && (time() - $_SESSION[$rlKey]) < 60) {
        jsonError('Tunggu 60 detik sebelum mengirim ulang.', 429);
    }
    $_SESSION[$rlKey] = time();
    $token     = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    DB::execute("UPDATE users SET email_verification_token = ?, updated_at = NOW() WHERE id = ?", [$tokenHash, $user['id']]);
    try {
        sendVerificationEmail($user['email'], $user['name'], $token);
    } catch (\Throwable $e) {
        error_log('[Resend] Gagal kirim email ke ' . $email . ': ' . $e->getMessage());
        jsonError('Gagal mengirim email. Coba lagi beberapa saat.', 500);
    }
    jsonSuccess(null, 'Email konfirmasi berhasil dikirim ulang. Cek inbox kamu.');
}

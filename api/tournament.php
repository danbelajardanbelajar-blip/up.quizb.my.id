<?php
// ============================================
// api/tournament.php — Tournament Mode Endpoints
// ============================================

require_once __DIR__ . '/../includes/helpers.php';

function ensureTournamentTables(): void {
    static $ready = false;
    if ($ready) return;
    try {
        $pdo = DB::conn();
        $pdo->exec("CREATE TABLE IF NOT EXISTS tournaments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            quiz_id INT NOT NULL,
            creator_id INT NOT NULL,
            status ENUM('pending', 'open', 'in_progress', 'completed', 'cancelled') DEFAULT 'open',
            max_participants INT DEFAULT 16,
            winner_id INT NULL,
            start_time DATETIME NULL,
            end_time DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_t_quiz (quiz_id),
            KEY idx_t_creator (creator_id),
            KEY idx_t_status (status),
            KEY idx_t_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS tournament_participants (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tournament_id INT NOT NULL,
            user_id INT NOT NULL,
            is_creator TINYINT(1) DEFAULT 0,
            status ENUM('pending', 'accepted', 'declined') DEFAULT 'pending',
            attempt_id INT NULL,
            score_final INT NULL,
            time_taken INT NULL,
            progress TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_tourn_user (tournament_id, user_id),
            KEY idx_tp_tourn (tournament_id),
            KEY idx_tp_user (user_id),
            KEY idx_tp_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $ready = true;
    } catch (\Throwable $e) {
        error_log('[Tournament schema] ' . $e->getMessage());
    }
}

/**
 * GET — Daftar Turnamen untuk user aktif
 */
function tournament_list(): void {
    $user = requireAuth();
    ensureTournamentTables();

    // 1. Undangan Masuk (Pending)
    $incoming = DB::all(
        "SELECT t.id, t.title, t.description, t.quiz_id, t.creator_id, t.status, t.created_at,
                q.title AS quiz_title, q.total_questions, u.name AS creator_name,
                (SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = t.id AND status = 'accepted') AS accepted_count,
                (SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = t.id) AS total_invited
         FROM tournament_participants tp
         JOIN tournaments t ON t.id = tp.tournament_id
         JOIN quizzes q ON q.id = t.quiz_id
         JOIN users u ON u.id = t.creator_id
         WHERE tp.user_id = ? AND tp.status = 'pending' AND t.status != 'cancelled'
         ORDER BY t.created_at DESC LIMIT 20",
        [$user['id']]
    );

    // 2. Turnamen Aktif yang diikut / dibuat user
    $active = DB::all(
        "SELECT t.id, t.title, t.description, t.quiz_id, t.creator_id, t.status, t.start_time, t.created_at,
                q.title AS quiz_title, q.total_questions, u.name AS creator_name,
                tp.status AS my_status, tp.is_creator, tp.score_final, tp.time_taken,
                (SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = t.id AND status = 'accepted') AS accepted_count,
                (SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = t.id) AS total_invited
         FROM tournament_participants tp
         JOIN tournaments t ON t.id = tp.tournament_id
         JOIN quizzes q ON q.id = t.quiz_id
         JOIN users u ON u.id = t.creator_id
         WHERE tp.user_id = ? AND tp.status = 'accepted' AND t.status IN ('open', 'in_progress')
         ORDER BY t.created_at DESC LIMIT 20",
        [$user['id']]
    );

    // 3. Turnamen yang dibuat oleh user sendiri (My Tournaments)
    $myTournaments = DB::all(
        "SELECT t.id, t.title, t.description, t.quiz_id, t.creator_id, t.status, t.start_time, t.created_at,
                q.title AS quiz_title, q.total_questions,
                (SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = t.id AND status = 'accepted') AS accepted_count,
                (SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = t.id) AS total_invited
         FROM tournaments t
         JOIN quizzes q ON q.id = t.quiz_id
         WHERE t.creator_id = ?
         ORDER BY t.created_at DESC LIMIT 20",
        [$user['id']]
    );

    // 4. Riwayat Turnamen Selesai
    $history = DB::all(
        "SELECT t.id, t.title, t.quiz_id, t.creator_id, t.status, t.created_at, t.winner_id,
                w.name AS winner_name, q.title AS quiz_title,
                tp.score_final, tp.time_taken, tp.is_creator,
                (SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = t.id AND status = 'accepted') AS accepted_count
         FROM tournament_participants tp
         JOIN tournaments t ON t.id = tp.tournament_id
         JOIN quizzes q ON q.id = t.quiz_id
         LEFT JOIN users w ON w.id = t.winner_id
         WHERE tp.user_id = ? AND tp.status = 'accepted' AND t.status = 'completed'
         ORDER BY t.created_at DESC LIMIT 20",
        [$user['id']]
    );

    jsonSuccess([
        'incoming'       => $incoming,
        'active'         => $active,
        'my_tournaments' => $myTournaments,
        'history'        => $history,
        'pending_count'  => count($incoming)
    ]);
}

/**
 * POST — Buat turnamen baru dan undang para peserta berdasarkan ID/Email/Nama
 */
function tournament_create(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    $user = requireAuth();
    ensureTournamentTables();
    $body = getBody();

    $title = trim($body['title'] ?? '');
    $description = trim($body['description'] ?? '');
    $quizId = (int)($body['quiz_id'] ?? 0);
    $maxParticipants = max(2, min(64, (int)($body['max_participants'] ?? 16)));
    $invitedInput = $body['invited_users'] ?? [];

    if (empty($title)) jsonError('Judul turnamen harus diisi');
    if (!$quizId) jsonError('Quiz harus dipilih');
    if (empty($invitedInput) || !is_array($invitedInput)) jsonError('Minimal undang 1 pemain ke turnamen');

    $quiz = DB::one('SELECT id, title, total_questions FROM quizzes WHERE id = ? AND is_published = 1', [$quizId]);
    if (!$quiz) jsonError('Kuis tidak ditemukan atau belum dipublikasikan', 404);

    // Resolusi user target dari input (ID, email, atau nama)
    $targetUserIds = [];
    foreach ($invitedInput as $item) {
        if (is_numeric($item)) {
            $uid = (int)$item;
            if ($uid !== (int)$user['id']) $targetUserIds[$uid] = true;
        } else if (is_string($item) && strlen(trim($item)) > 0) {
            $s = trim($item);
            $found = DB::one('SELECT id FROM users WHERE (email = ? OR name = ?) AND is_active = 1 LIMIT 1', [$s, $s]);
            if ($found && (int)$found['id'] !== (int)$user['id']) {
                $targetUserIds[(int)$found['id']] = true;
            }
        } else if (is_array($item) && isset($item['id'])) {
            $uid = (int)$item['id'];
            if ($uid !== (int)$user['id']) $targetUserIds[$uid] = true;
        }
    }

    $validUserIds = array_keys($targetUserIds);
    if (empty($validUserIds)) jsonError('Pemain yang diundang tidak ditemukan atau tidak valid');
    if (count($validUserIds) > $maxParticipants) {
        jsonError("Maksimal peserta turnamen adalah {$maxParticipants} orang.");
    }

    $placeholders = str_repeat('?,', count($validUserIds) - 1) . '?';
    $validUsers = DB::all("SELECT id, name, email FROM users WHERE id IN ($placeholders) AND is_active = 1", $validUserIds);
    if (empty($validUsers)) jsonError('Pemain yang diundang tidak valid', 404);

    // 1. Simpan room turnamen
    DB::execute(
        "INSERT INTO tournaments (title, description, quiz_id, creator_id, status, max_participants)
         VALUES (?, ?, ?, ?, 'open', ?)",
        [$title, $description ?: null, $quizId, $user['id'], $maxParticipants]
    );
    $tournamentId = (int)DB::lastId();

    // 2. Simpan Creator sebagai accepted host
    DB::execute(
        "INSERT INTO tournament_participants (tournament_id, user_id, is_creator, status, progress)
         VALUES (?, ?, 1, 'accepted', '{\"s\":0,\"q\":0,\"last_ping\":0}')",
        [$tournamentId, $user['id']]
    );

    // 3. Simpan Peserta Undangan (Pending) & Kirim Notifikasi
    foreach ($validUsers as $target) {
        DB::execute(
            "INSERT INTO tournament_participants (tournament_id, user_id, is_creator, status, progress)
             VALUES (?, ?, 0, 'pending', '{\"s\":0,\"q\":0,\"last_ping\":0}')",
            [$tournamentId, $target['id']]
        );

        // Push Notifikasi (FCM Android + Web)
        pushNotification(
            $target['id'],
            'tournament',
            '🏆 Undangan Turnamen: ' . $title,
            $user['name'] . ' mengundangmu berlaga di turnamen kuis "' . $quiz['title'] . '"',
            '/tournament/' . $tournamentId,
            [
                'tournament_id'    => (string)$tournamentId,
                'quiz_id'          => (string)$quizId,
                'tournament_title' => (string)$title,
                'quiz_title'       => (string)$quiz['title'],
                'creator_name'     => (string)$user['name']
            ]
        );
    }

    jsonSuccess([
        'tournament_id' => $tournamentId,
        'title'         => $title,
        'invited_count' => count($validUsers)
    ], 'Turnamen berhasil dibuat dan undangan telah dikirimkan!');
}

/**
 * GET — Detail status dan leaderboard live turnamen
 */
function tournament_detail(): void {
    $user = requireAuth();
    ensureTournamentTables();

    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonError('Tournament ID diperlukan');

    $t = DB::one(
        "SELECT t.*, q.title AS quiz_title, q.time_limit, q.total_questions, u.name AS creator_name,
                w.name AS winner_name, NOW() AS server_now
         FROM tournaments t
         JOIN quizzes q ON q.id = t.quiz_id
         JOIN users u ON u.id = t.creator_id
         LEFT JOIN users w ON w.id = t.winner_id
         WHERE t.id = ?",
        [$id]
    );
    if (!$t) jsonError('Turnamen tidak ditemukan', 404);

    // Ambil partisipan
    $participants = DB::all(
        "SELECT tp.id, tp.tournament_id, tp.user_id, tp.is_creator, tp.status, tp.attempt_id,
                tp.score_final, tp.time_taken, tp.progress, u.name, u.email
         FROM tournament_participants tp
         JOIN users u ON u.id = tp.user_id
         WHERE tp.tournament_id = ?
         ORDER BY tp.is_creator DESC, tp.created_at ASC",
        [$id]
    );

    $now = time();
    $totalAccepted = 0;
    $totalSubmitted = 0;

    foreach ($participants as &$p) {
        $prog = $p['progress'] ? json_decode($p['progress'], true) : null;
        $p['progress_data'] = $prog;
        $p['is_submitted'] = ($p['attempt_id'] !== null || $p['score_final'] !== null);
        $p['live_score'] = $p['is_submitted'] ? (int)$p['score_final'] : (isset($prog['s']) ? (int)$prog['s'] : 0);
        $p['q_index'] = isset($prog['q']) ? (int)$prog['q'] : 0;

        if ($p['status'] === 'accepted') {
            $totalAccepted++;
            if ($p['is_submitted']) {
                $totalSubmitted++;
            }
        }

        // Cek disconnect (> 35 detik tanpa ping jika turnamen sedang in_progress dan belum submit)
        if ($t['status'] === 'in_progress' && !$p['is_submitted'] && $prog && !empty($prog['last_ping'])) {
            $p['disconnected'] = ($now - (int)$prog['last_ping'] > 35);
        } else {
            $p['disconnected'] = false;
        }
    }
    unset($p);

    // Auto-complete jika semua peserta yang accepted sudah submit saat status in_progress
    if ($t['status'] === 'in_progress' && $totalAccepted > 0 && $totalSubmitted >= $totalAccepted) {
        $winner = DB::one(
            "SELECT user_id FROM tournament_participants
             WHERE tournament_id = ? AND attempt_id IS NOT NULL AND status = 'accepted'
             ORDER BY score_final DESC, time_taken ASC LIMIT 1",
            [$id]
        );
        $winnerId = $winner['user_id'] ?? null;
        DB::execute("UPDATE tournaments SET status = 'completed', winner_id = ?, end_time = NOW() WHERE id = ?", [$winnerId, $id]);
        $t['status'] = 'completed';
        $t['winner_id'] = $winnerId;
        if ($winnerId) {
            $winUser = DB::one("SELECT name FROM users WHERE id = ?", [$winnerId]);
            $t['winner_name'] = $winUser['name'] ?? null;
        }
    }

    $t['participants'] = $participants;
    $t['is_creator'] = ((int)$t['creator_id'] === (int)$user['id']);
    
    // Status user aktif di turnamen ini
    $myPart = null;
    foreach ($participants as $p) {
        if ((int)$p['user_id'] === (int)$user['id']) {
            $myPart = $p;
            break;
        }
    }
    $t['my_status'] = $myPart ? $myPart['status'] : null;
    $t['my_participant'] = $myPart;

    jsonSuccess($t);
}

/**
 * POST — Terima undangan turnamen
 */
function tournament_accept(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    $user = requireAuth();
    ensureTournamentTables();
    $body = getBody();
    $id = (int)($body['tournament_id'] ?? 0);
    if (!$id) jsonError('Tournament ID diperlukan');

    $member = DB::one('SELECT * FROM tournament_participants WHERE tournament_id = ? AND user_id = ?', [$id, $user['id']]);
    if (!$member) jsonError('Kamu tidak diundang ke turnamen ini', 403);

    DB::execute("UPDATE tournament_participants SET status = 'accepted' WHERE tournament_id = ? AND user_id = ?", [$id, $user['id']]);

    // Beritahu creator turnamen bahwa pemain menerima
    $t = DB::one('SELECT title, creator_id FROM tournaments WHERE id = ?', [$id]);
    if ($t && (int)$t['creator_id'] !== (int)$user['id']) {
        pushNotification(
            $t['creator_id'],
            'tournament_accepted',
            '🏆 Pemain Bergabung ke Turnamen!',
            $user['name'] . ' telah menerima undangan turnamen "' . $t['title'] . '"',
            '/tournament/' . $id,
            ['tournament_id' => (string)$id]
        );
    }

    jsonSuccess([], 'Undangan turnamen berhasil diterima! Selamat bertanding.');
}

/**
 * POST — Tolak undangan turnamen
 */
function tournament_decline(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    $user = requireAuth();
    ensureTournamentTables();
    $body = getBody();
    $id = (int)($body['tournament_id'] ?? 0);
    if (!$id) jsonError('Tournament ID diperlukan');

    $member = DB::one('SELECT * FROM tournament_participants WHERE tournament_id = ? AND user_id = ?', [$id, $user['id']]);
    if (!$member) jsonError('Kamu tidak diundang ke turnamen ini', 403);

    DB::execute("UPDATE tournament_participants SET status = 'declined' WHERE tournament_id = ? AND user_id = ?", [$id, $user['id']]);
    jsonSuccess([], 'Undangan turnamen telah ditolak.');
}

/**
 * POST — Creator memulai jalannya turnamen
 */
function tournament_start(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    $user = requireAuth();
    ensureTournamentTables();
    $body = getBody();
    $id = (int)($body['tournament_id'] ?? 0);
    if (!$id) jsonError('Tournament ID diperlukan');

    $t = DB::one('SELECT * FROM tournaments WHERE id = ?', [$id]);
    if (!$t) jsonError('Turnamen tidak ditemukan', 404);
    if ((int)$t['creator_id'] !== (int)$user['id']) jsonError('Hanya pembuat turnamen yang dapat memulainya', 403);

    DB::execute("UPDATE tournaments SET status = 'in_progress', start_time = NOW() WHERE id = ?", [$id]);

    // Beritahu semua peserta yang sudah accepted
    $accepted = DB::all("SELECT user_id FROM tournament_participants WHERE tournament_id = ? AND status = 'accepted' AND user_id != ?", [$id, $user['id']]);
    foreach ($accepted as $part) {
        pushNotification(
            $part['user_id'],
            'tournament_started',
            '🚀 Turnamen Telah Dimulai!',
            'Turnamen "' . $t['title'] . '" kini sedang berlangsung. Klik untuk mulai mengerjakan kuis!',
            '/play/' . $t['quiz_id'] . '?mode=tournament&tid=' . $id,
            [
                'tournament_id' => (string)$id,
                'quiz_id'       => (string)$t['quiz_id']
            ]
        );
    }

    jsonSuccess([], 'Turnamen resmi dimulai!');
}

/**
 * POST — Live synchronization nilai & posisi soal peserta saat kuis turnamen berlangsung
 */
function tournament_sync(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    $user = requireAuth();
    ensureTournamentTables();
    $body = getBody();
    $id = (int)($body['tournament_id'] ?? 0);
    $score = (int)($body['score'] ?? 0);
    $qIndex = (int)($body['q_index'] ?? 0);
    if (!$id) jsonError('Tournament ID diperlukan');

    $member = DB::one("SELECT user_id FROM tournament_participants WHERE tournament_id = ? AND user_id = ? AND status = 'accepted'", [$id, $user['id']]);
    if (!$member) jsonError('Akses ditolak', 403);

    $now = time();
    $prog = ['s' => $score, 'q' => $qIndex, 'last_ping' => $now];
    DB::execute("UPDATE tournament_participants SET progress = ? WHERE tournament_id = ? AND user_id = ?", [json_encode($prog), $id, $user['id']]);

    // Ambil data terbaru seluruh peserta turnamen
    $participants = DB::all(
        "SELECT tp.user_id, tp.is_creator, tp.attempt_id, tp.score_final, tp.time_taken, tp.progress, u.name
         FROM tournament_participants tp
         JOIN users u ON u.id = tp.user_id
         WHERE tp.tournament_id = ? AND tp.status = 'accepted'",
        [$id]
    );

    foreach ($participants as &$p) {
        $pProg = $p['progress'] ? json_decode($p['progress'], true) : null;
        $p['progress_data'] = $pProg;
        $p['is_submitted'] = ($p['attempt_id'] !== null || $p['score_final'] !== null);
        $p['live_score'] = $p['is_submitted'] ? (int)$p['score_final'] : (isset($pProg['s']) ? (int)$pProg['s'] : 0);
        $p['q_index'] = isset($pProg['q']) ? (int)$pProg['q'] : 0;
        if (!$p['is_submitted'] && $pProg && !empty($pProg['last_ping'])) {
            $p['disconnected'] = ($now - (int)$pProg['last_ping'] > 35);
        } else {
            $p['disconnected'] = false;
        }
    }
    unset($p);

    jsonSuccess(['participants' => $participants]);
}

/**
 * POST — Submit hasil attempt kuis ke turnamen
 */
function tournament_submit(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    $user = requireAuth();
    ensureTournamentTables();
    $body = getBody();
    $tournamentId = (int)($body['tournament_id'] ?? 0);
    $attemptId    = (int)($body['attempt_id'] ?? 0);
    if (!$tournamentId || !$attemptId) jsonError('Parameter turnamen dan attempt diperlukan');

    $room = DB::one(
        "SELECT t.quiz_id, t.status, t.creator_id FROM tournaments t
         JOIN tournament_participants tp ON tp.tournament_id = t.id
         WHERE t.id = ? AND tp.user_id = ? AND tp.status = 'accepted'",
        [$tournamentId, $user['id']]
    );
    if (!$room) jsonError('Akses ditolak', 403);

    $attempt = DB::one("SELECT score, time_taken FROM attempts WHERE id = ? AND user_id = ? AND quiz_id = ?", [$attemptId, $user['id'], $room['quiz_id']]);
    if (!$attempt) jsonError('Attempt kuis tidak valid', 404);

    DB::execute(
        "UPDATE tournament_participants SET attempt_id = ?, score_final = ?, time_taken = ?
         WHERE tournament_id = ? AND user_id = ?",
        [$attemptId, $attempt['score'], $attempt['time_taken'], $tournamentId, $user['id']]
    );

    // Cek apakah seluruh peserta yang accepted sudah submit
    $totalAccepted = (int)(DB::one("SELECT COUNT(*) as c FROM tournament_participants WHERE tournament_id = ? AND status = 'accepted'", [$tournamentId])['c'] ?? 0);
    $totalSubmitted = (int)(DB::one("SELECT COUNT(*) as c FROM tournament_participants WHERE tournament_id = ? AND status = 'accepted' AND attempt_id IS NOT NULL", [$tournamentId])['c'] ?? 0);

    if ($totalAccepted > 0 && $totalSubmitted >= $totalAccepted) {
        $winner = DB::one(
            "SELECT user_id FROM tournament_participants
             WHERE tournament_id = ? AND attempt_id IS NOT NULL AND status = 'accepted'
             ORDER BY score_final DESC, time_taken ASC LIMIT 1",
            [$tournamentId]
        );
        $winnerId = $winner['user_id'] ?? null;
        DB::execute("UPDATE tournaments SET status = 'completed', winner_id = ?, end_time = NOW() WHERE id = ?", [$winnerId, $tournamentId]);
    }

    jsonSuccess([
        'total_accepted'  => $totalAccepted,
        'total_submitted' => $totalSubmitted
    ], 'Hasil turnamen berhasil dikumpulkan!');
}

/**
 * GET — Cari calon peserta turnamen berdasarkan nama atau email
 */
function tournament_search_users(): void {
    $user = requireAuth();
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) jsonSuccess([]);

    $results = DB::all(
        "SELECT id, name, email FROM users
         WHERE (name LIKE ? OR email LIKE ?) AND id != ? AND is_active = 1
         ORDER BY name ASC LIMIT 10",
        ['%' . $q . '%', '%' . $q . '%', $user['id']]
    );
    jsonSuccess($results);
}

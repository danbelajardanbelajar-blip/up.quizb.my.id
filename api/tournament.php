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

        // Bersihkan data lama di mana pembuat turnamen sempat tercatat sebagai peserta
        $pdo->exec("DELETE tp FROM tournament_participants tp INNER JOIN tournaments t ON t.id = tp.tournament_id WHERE tp.user_id = t.creator_id OR tp.is_creator = 1");
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

    // 1. Undangan Masuk (Pending) — hanya untuk user sebagai peserta
    $incoming = DB::all(
        "SELECT t.id, t.title, t.description, t.quiz_id, t.creator_id, t.status, t.created_at,
                q.title AS quiz_title, q.total_questions, u.name AS creator_name,
                (SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = t.id AND status = 'accepted' AND user_id != t.creator_id) AS accepted_count,
                (SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = t.id AND user_id != t.creator_id) AS total_invited
         FROM tournament_participants tp
         JOIN tournaments t ON t.id = tp.tournament_id
         JOIN quizzes q ON q.id = t.quiz_id
         JOIN users u ON u.id = t.creator_id
         WHERE tp.user_id = ? AND tp.status = 'pending' AND t.status != 'cancelled' AND tp.user_id != t.creator_id
         ORDER BY t.created_at DESC LIMIT 20",
        [$user['id']]
    );

    // 2. Turnamen Aktif yang diikuti user sebagai peserta
    $active = DB::all(
        "SELECT t.id, t.title, t.description, t.quiz_id, t.creator_id, t.status, t.start_time, t.created_at,
                q.title AS quiz_title, q.total_questions, u.name AS creator_name,
                tp.status AS my_status, 0 AS is_creator, tp.score_final, tp.time_taken,
                (SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = t.id AND status = 'accepted' AND user_id != t.creator_id) AS accepted_count,
                (SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = t.id AND user_id != t.creator_id) AS total_invited
         FROM tournament_participants tp
         JOIN tournaments t ON t.id = tp.tournament_id
         JOIN quizzes q ON q.id = t.quiz_id
         JOIN users u ON u.id = t.creator_id
         WHERE tp.user_id = ? AND tp.status = 'accepted' AND t.status IN ('open', 'in_progress') AND tp.user_id != t.creator_id
         ORDER BY t.created_at DESC LIMIT 20",
        [$user['id']]
    );

    // 3. Turnamen yang diselenggarakan oleh user sebagai Juri / Pengawas (My Tournaments)
    $myTournaments = DB::all(
        "SELECT t.id, t.title, t.description, t.quiz_id, t.creator_id, t.status, t.start_time, t.created_at,
                q.title AS quiz_title, q.total_questions, 1 AS is_creator,
                (SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = t.id AND status = 'accepted' AND user_id != t.creator_id) AS accepted_count,
                (SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = t.id AND user_id != t.creator_id) AS total_invited
         FROM tournaments t
         JOIN quizzes q ON q.id = t.quiz_id
         WHERE t.creator_id = ?
         ORDER BY t.created_at DESC LIMIT 20",
        [$user['id']]
    );

    // 4. Riwayat Turnamen Selesai (baik sebagai peserta maupun sebagai juri/pembuat)
    $history = DB::all(
        "SELECT DISTINCT t.id, t.title, t.quiz_id, t.creator_id, t.status, t.created_at, t.winner_id,
                w.name AS winner_name, q.title AS quiz_title,
                tp.score_final, tp.time_taken,
                (CASE WHEN t.creator_id = ? THEN 1 ELSE 0 END) AS is_creator,
                (SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = t.id AND status = 'accepted' AND user_id != t.creator_id) AS accepted_count
         FROM tournaments t
         JOIN quizzes q ON q.id = t.quiz_id
         LEFT JOIN tournament_participants tp ON tp.tournament_id = t.id AND tp.user_id = ?
         LEFT JOIN users w ON w.id = t.winner_id
         WHERE (tp.user_id = ? OR t.creator_id = ?) AND t.status = 'completed'
         ORDER BY t.created_at DESC LIMIT 20",
        [$user['id'], $user['id'], $user['id'], $user['id']]
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

    $title = trim($body['title'] ?? $_POST['title'] ?? '');
    $description = trim($body['description'] ?? $_POST['description'] ?? '');
    $quizId = (int)($body['quiz_id'] ?? $_POST['quiz_id'] ?? 0);
    $maxParticipants = max(2, min(64, (int)($body['max_participants'] ?? $_POST['max_participants'] ?? 16)));
    $invitedInput = $body['invitees'] ?? $body['invited_users'] ?? $_POST['invitees'] ?? $_POST['invited_users'] ?? [];

    if (is_string($invitedInput)) {
        $decoded = json_decode($invitedInput, true);
        if (is_array($decoded)) {
            $invitedInput = $decoded;
        } else if (strpos($invitedInput, ',') !== false) {
            $invitedInput = array_map('trim', explode(',', $invitedInput));
        } else if (strlen(trim($invitedInput)) > 0) {
            $invitedInput = [trim($invitedInput)];
        }
    }

    if (empty($title)) jsonError('Judul turnamen harus diisi');
    if (!$quizId) jsonError('Quiz harus dipilih');
    if (empty($invitedInput) || !is_array($invitedInput)) jsonError('Minimal undang 2 pemain ke turnamen (pembuat bertindak sebagai juri/pengawas)');

    $quiz = DB::one('SELECT id, title, total_questions FROM quizzes WHERE id = ? AND is_published = 1', [$quizId]);
    if (!$quiz) jsonError('Kuis tidak ditemukan atau belum dipublikasikan', 404);

    // Resolusi user target dari input (ID, email, atau nama) — Pembuat turnamen tidak boleh diundang sendiri
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
    if (count($validUserIds) < 2) {
        jsonError('Minimal undang 2 pemain untuk bertanding dalam turnamen (pembuat bertindak sebagai juri/pengawas)');
    }
    if (count($validUserIds) > $maxParticipants) {
        jsonError("Maksimal peserta turnamen adalah {$maxParticipants} orang.");
    }

    $placeholders = str_repeat('?,', count($validUserIds) - 1) . '?';
    $validUsers = DB::all("SELECT id, name, email FROM users WHERE id IN ($placeholders) AND is_active = 1", $validUserIds);
    if (count($validUsers) < 2) {
        jsonError('Minimal undang 2 pemain yang valid untuk bertanding dalam turnamen', 404);
    }

    // 1. Simpan room turnamen (pembuat bertindak sebagai Host/Juri)
    DB::execute(
        "INSERT INTO tournaments (title, description, quiz_id, creator_id, status, max_participants)
         VALUES (?, ?, ?, ?, 'open', ?)",
        [$title, $description ?: null, $quizId, $user['id'], $maxParticipants]
    );
    $tournamentId = (int)DB::lastId();

    // 2. Simpan Peserta Undangan (Pending) & Kirim Notifikasi
    // CATATAN: Pembuat turnamen TIDAK dimasukkan sebagai peserta (pembuat bertindak sebagai Juri/Host)
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
            $user['name'] . ' (Juri/Host) mengundangmu bertanding di turnamen kuis "' . $quiz['title'] . '"',
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

    // Ambil partisipan kompetisi (HANYA peserta yang diundang, BUKAN creator / juri)
    $participants = DB::all(
        "SELECT tp.id, tp.tournament_id, tp.user_id, 0 AS is_creator, tp.status, tp.attempt_id,
                tp.score_final, tp.time_taken, tp.progress, u.name, u.email
         FROM tournament_participants tp
         JOIN users u ON u.id = tp.user_id
         WHERE tp.tournament_id = ? AND tp.user_id != ?
         ORDER BY tp.created_at ASC",
        [$id, $t['creator_id']]
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
             WHERE tournament_id = ? AND score_final IS NOT NULL AND status = 'accepted' AND user_id != ?
             ORDER BY score_final DESC, time_taken ASC LIMIT 1",
            [$id, $t['creator_id']]
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
    $isCreator = ((int)$t['creator_id'] === (int)$user['id']);
    $t['is_creator'] = $isCreator;
    
    // Status user aktif di turnamen ini (jika juri/creator, bukan peserta kompetisi)
    $myPart = null;
    if (!$isCreator) {
        foreach ($participants as $p) {
            if ((int)$p['user_id'] === (int)$user['id']) {
                $myPart = $p;
                break;
            }
        }
    }
    $t['is_participant'] = ($myPart !== null);
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

    $t = DB::one('SELECT title, creator_id FROM tournaments WHERE id = ?', [$id]);
    if (!$t) jsonError('Turnamen tidak ditemukan', 404);
    if ((int)$t['creator_id'] === (int)$user['id']) {
        jsonError('Pembuat turnamen bertindak sebagai juri/pengawas dan tidak perlu menerima undangan.', 400);
    }

    $member = DB::one('SELECT * FROM tournament_participants WHERE tournament_id = ? AND user_id = ?', [$id, $user['id']]);
    if (!$member) jsonError('Kamu tidak diundang ke turnamen ini', 403);

    DB::execute("UPDATE tournament_participants SET status = 'accepted' WHERE tournament_id = ? AND user_id = ?", [$id, $user['id']]);

    // Beritahu creator turnamen bahwa pemain menerima
    pushNotification(
        $t['creator_id'],
        'tournament_accepted',
        '🏆 Peserta Bergabung ke Turnamen!',
        $user['name'] . ' telah menerima undangan turnamen "' . $t['title'] . '"',
        '/tournament/' . $id,
        ['tournament_id' => (string)$id]
    );

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

    $t = DB::one('SELECT creator_id FROM tournaments WHERE id = ?', [$id]);
    if (!$t) jsonError('Turnamen tidak ditemukan', 404);
    if ((int)$t['creator_id'] === (int)$user['id']) {
        jsonError('Pembuat turnamen bertindak sebagai juri/pengawas.', 400);
    }

    $member = DB::one('SELECT * FROM tournament_participants WHERE tournament_id = ? AND user_id = ?', [$id, $user['id']]);
    if (!$member) jsonError('Kamu tidak diundang ke turnamen ini', 403);

    DB::execute("UPDATE tournament_participants SET status = 'declined' WHERE tournament_id = ? AND user_id = ?", [$id, $user['id']]);
    jsonSuccess([], 'Undangan turnamen telah ditolak.');
}

/**
 * POST — Creator (Juri / Pengawas) memulai jalannya turnamen
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
    if ((int)$t['creator_id'] !== (int)$user['id']) jsonError('Hanya juri / pembuat turnamen yang dapat memulainya', 403);
    if ($t['status'] === 'in_progress') jsonError('Turnamen sudah sedang berlangsung');
    if ($t['status'] === 'completed') jsonError('Turnamen sudah selesai');

    // Beritahu semua peserta yang sudah accepted (bukan creator)
    $accepted = DB::all("SELECT user_id FROM tournament_participants WHERE tournament_id = ? AND status = 'accepted' AND user_id != ?", [$id, $user['id']]);
    if (empty($accepted)) {
        jsonError('Belum ada peserta yang menerima undangan turnamen. Tunggu minimal 1 peserta menerima sebelum memulai.');
    }

    DB::execute("UPDATE tournaments SET status = 'in_progress', start_time = NOW() WHERE id = ?", [$id]);

    foreach ($accepted as $part) {
        pushNotification(
            $part['user_id'],
            'tournament_started',
            '🚀 Turnamen Telah Dimulai!',
            'Juri telah memulai turnamen "' . $t['title'] . '". Ayo kerjakan kuis sekarang!',
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
    $score = isset($body['live_score']) ? (int)$body['live_score'] : (int)($body['score'] ?? 0);
    $qIndex = (int)($body['q_index'] ?? 0);
    if (!$id) jsonError('Tournament ID diperlukan');

    $t = DB::one('SELECT creator_id FROM tournaments WHERE id = ?', [$id]);
    if (!$t) jsonError('Turnamen tidak ditemukan', 404);

    $isCreator = ((int)$t['creator_id'] === (int)$user['id']);
    if (!$isCreator) {
        $member = DB::one("SELECT user_id FROM tournament_participants WHERE tournament_id = ? AND user_id = ? AND status = 'accepted'", [$id, $user['id']]);
        if (!$member) jsonError('Akses ditolak', 403);

        $now = time();
        $prog = ['s' => $score, 'q' => $qIndex, 'last_ping' => $now];
        DB::execute("UPDATE tournament_participants SET progress = ? WHERE tournament_id = ? AND user_id = ?", [json_encode($prog), $id, $user['id']]);
    }

    $now = time();
    // Ambil data terbaru seluruh peserta turnamen (HANYA peserta kompetisi)
    $participants = DB::all(
        "SELECT tp.user_id, 0 AS is_creator, tp.attempt_id, tp.score_final, tp.time_taken, tp.progress, u.name
         FROM tournament_participants tp
         JOIN users u ON u.id = tp.user_id
         WHERE tp.tournament_id = ? AND tp.status = 'accepted' AND tp.user_id != ?",
        [$id, $t['creator_id']]
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
    $attemptId    = isset($body['attempt_id']) ? (int)$body['attempt_id'] : 0;
    if (!$tournamentId) jsonError('Parameter tournament_id diperlukan');

    $room = DB::one("SELECT quiz_id, status, creator_id FROM tournaments WHERE id = ?", [$tournamentId]);
    if (!$room) jsonError('Turnamen tidak ditemukan', 404);

    if ((int)$room['creator_id'] === (int)$user['id']) {
        jsonError('Pembuat turnamen bertindak sebagai juri/pengawas dan tidak mengikuti pengerjaan kuis.', 403);
    }

    $part = DB::one("SELECT id, status FROM tournament_participants WHERE tournament_id = ? AND user_id = ?", [$tournamentId, $user['id']]);
    if (!$part || $part['status'] !== 'accepted') {
        jsonError('Anda bukan peserta aktif turnamen ini', 403);
    }

    $scoreFinal = isset($body['score_final']) ? (int)$body['score_final'] : null;
    $timeTaken  = isset($body['time_taken']) ? (int)$body['time_taken'] : null;

    if ($attemptId > 0) {
        $attempt = DB::one("SELECT score, time_taken FROM attempts WHERE id = ? AND user_id = ? AND quiz_id = ?", [$attemptId, $user['id'], $room['quiz_id']]);
        if ($attempt) {
            if ($scoreFinal === null) $scoreFinal = (int)$attempt['score'];
            if ($timeTaken === null)  $timeTaken  = (int)$attempt['time_taken'];
        }
    }

    if ($scoreFinal === null) $scoreFinal = 0;
    if ($timeTaken === null)  $timeTaken  = 0;

    DB::execute(
        "UPDATE tournament_participants SET attempt_id = ?, score_final = ?, time_taken = ?, status = 'accepted'
         WHERE tournament_id = ? AND user_id = ?",
        [$attemptId > 0 ? $attemptId : null, $scoreFinal, $timeTaken, $tournamentId, $user['id']]
    );

    // Cek apakah seluruh peserta yang accepted sudah submit (tanpa menghitung creator)
    $totalAccepted = (int)(DB::one("SELECT COUNT(*) as c FROM tournament_participants WHERE tournament_id = ? AND status = 'accepted' AND user_id != ?", [$tournamentId, $room['creator_id']])['c'] ?? 0);
    $totalSubmitted = (int)(DB::one("SELECT COUNT(*) as c FROM tournament_participants WHERE tournament_id = ? AND status = 'accepted' AND score_final IS NOT NULL AND user_id != ?", [$tournamentId, $room['creator_id']])['c'] ?? 0);

    if ($totalAccepted > 0 && $totalSubmitted >= $totalAccepted) {
        $winner = DB::one(
            "SELECT user_id FROM tournament_participants
             WHERE tournament_id = ? AND score_final IS NOT NULL AND status = 'accepted' AND user_id != ?
             ORDER BY score_final DESC, time_taken ASC LIMIT 1",
            [$tournamentId, $room['creator_id']]
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
 * POST — Host menghentikan turnamen (Stop)
 */
function tournament_stop(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    $user = requireAuth();
    ensureTournamentTables();
    $body = getBody();
    $id = (int)($body['tournament_id'] ?? 0);
    if (!$id) jsonError('Tournament ID diperlukan');

    $t = DB::one('SELECT * FROM tournaments WHERE id = ?', [$id]);
    if (!$t) jsonError('Turnamen tidak ditemukan', 404);
    if ((int)$t['creator_id'] !== (int)$user['id']) {
        jsonError('Hanya juri / pembuat turnamen yang dapat menghentikannya', 403);
    }
    if ($t['status'] === 'completed') {
        jsonError('Turnamen sudah berstatus selesai');
    }

    // Tentukan pemenang dari peserta yang nilainya tertinggi saat ini
    $winner = DB::one(
        "SELECT user_id FROM tournament_participants
         WHERE tournament_id = ? AND status = 'accepted' AND user_id != ?
         ORDER BY (CASE WHEN score_final IS NOT NULL THEN score_final ELSE 0 END) DESC,
                  (CASE WHEN time_taken IS NOT NULL THEN time_taken ELSE 999999 END) ASC
         LIMIT 1",
        [$id, $t['creator_id']]
    );
    $winnerId = $winner['user_id'] ?? null;

    DB::execute("UPDATE tournaments SET status = 'completed', winner_id = ?, end_time = NOW() WHERE id = ?", [$winnerId, $id]);

    // Beritahu seluruh peserta
    $participants = DB::all("SELECT user_id FROM tournament_participants WHERE tournament_id = ? AND user_id != ?", [$id, $user['id']]);
    foreach ($participants as $part) {
        pushNotification(
            $part['user_id'],
            'tournament_stopped',
            '⏹ Turnamen Dihentikan',
            'Juri telah menghentikan turnamen "' . $t['title'] . '". Hasil akhir telah ditetapkan.',
            '/tournament/' . $id,
            ['tournament_id' => (string)$id]
        );
    }

    jsonSuccess(['winner_id' => $winnerId], 'Turnamen berhasil dihentikan. Hasil akhir telah ditetapkan.');
}

/**
 * POST — Host menghapus turnamen (Delete)
 */
function tournament_delete(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    $user = requireAuth();
    ensureTournamentTables();
    $body = getBody();
    $id = (int)($body['tournament_id'] ?? 0);
    if (!$id) jsonError('Tournament ID diperlukan');

    $t = DB::one('SELECT * FROM tournaments WHERE id = ?', [$id]);
    if (!$t) jsonError('Turnamen tidak ditemukan', 404);
    if ((int)$t['creator_id'] !== (int)$user['id']) {
        jsonError('Hanya juri / pembuat turnamen yang dapat menghapus turnamen ini', 403);
    }

    // Hapus peserta & room turnamen
    DB::execute("DELETE FROM tournament_participants WHERE tournament_id = ?", [$id]);
    DB::execute("DELETE FROM tournaments WHERE id = ?", [$id]);

    jsonSuccess([], 'Turnamen berhasil dihapus secara permanen.');
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

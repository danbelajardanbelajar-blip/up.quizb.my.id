<?php
// ============================================
// api/challenge.php — Multiplayer Challenge Endpoints
// ============================================

// POST — buat tantangan baru
function challenge_create(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    $user = requireAuth();
    $body = getBody();

    $quizId = (int)($body['quiz_id'] ?? 0);
    $challengedIds = $body['challenged_ids'] ?? [];
    $message = trim($body['message'] ?? '');

    if (!$quizId) jsonError('Quiz ID diperlukan');
    if (empty($challengedIds) || !is_array($challengedIds)) jsonError('User yang ditantang diperlukan (minimal 1)');
    if (count($challengedIds) > 5) jsonError('Maksimal hanya bisa menantang 5 orang sekaligus.');

    // Remove self from targets if exists
    $challengedIds = array_filter($challengedIds, fn($id) => (int)$id !== (int)$user['id']);
    if (empty($challengedIds)) jsonError('Tidak bisa menantang diri sendiri');

    $quiz = DB::one('SELECT id, title FROM quizzes WHERE id = ? AND is_published = 1', [$quizId]);
    if (!$quiz) jsonError('Quiz tidak ditemukan', 404);

    // Filter valid users
    $placeholders = str_repeat('?,', count($challengedIds) - 1) . '?';
    $validUsers = DB::all("SELECT id, name FROM users WHERE id IN ($placeholders) AND is_active = 1", array_values($challengedIds));
    if (empty($validUsers)) jsonError('User tidak valid', 404);

    // Create single room
    DB::execute(
        'INSERT INTO challenges (quiz_id, challenger_id, challenged_id, message, expires_at)
         VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))',
        [$quizId, $user['id'], $validUsers[0]['id'], $message ?: null]
    );
    $challengeId = (int)DB::lastId();

    // Insert participants (Host)
    DB::execute(
        "INSERT INTO challenge_participants (challenge_id, user_id, is_host, status, progress) VALUES (?, ?, 1, 'accepted', '{\"s\":0,\"q\":0,\"last_ping\":0}')",
        [$challengeId, $user['id']]
    );

    // Insert participants (Invitees)
    foreach ($validUsers as $target) {
        DB::execute(
            "INSERT INTO challenge_participants (challenge_id, user_id, is_host, status, progress) VALUES (?, ?, 0, 'pending', '{\"s\":0,\"q\":0,\"last_ping\":0}')",
            [$challengeId, $target['id']]
        );
        // Notif
        pushNotification(
            $target['id'],
            'challenge',
            $user['name'] . ' menantangmu dalam Duel Grup! ⚔️',
            'Buktikan siapa yang tercepat di kuis "' . $quiz['title'] . '"',
            '/play/' . $quizId . '?mode=challenge&cid=' . $challengeId
        );
    }

    jsonSuccess(['challenge_id' => $challengeId], 'Tantangan grup berhasil dikirim!');
}

function challenge_list(): void {
    $user = requireAuth();
    $incoming = DB::all("SELECT c.id, c.quiz_id, c.status, c.created_at, q.title AS quiz_title, u.name AS challenger_name, u.id AS challenger_id FROM challenge_participants cp INNER JOIN challenges c ON c.id = cp.challenge_id INNER JOIN quizzes q ON q.id = c.quiz_id INNER JOIN users u ON u.id = c.challenger_id WHERE cp.user_id = ? AND cp.status = 'pending' AND c.status = 'pending' ORDER BY c.created_at DESC LIMIT 20", [$user['id']]);
    
    $outgoing = DB::all("SELECT c.id, c.quiz_id, c.status, c.created_at, q.title AS quiz_title, (SELECT COUNT(*) FROM challenge_participants p WHERE p.challenge_id = c.id AND p.is_host = 0) as total_invited, (SELECT COUNT(*) FROM challenge_participants p WHERE p.challenge_id = c.id AND p.status = 'accepted' AND p.is_host = 0) as total_accepted FROM challenges c INNER JOIN quizzes q ON q.id = c.quiz_id WHERE c.challenger_id = ? ORDER BY c.created_at DESC LIMIT 20", [$user['id']]);
    foreach ($outgoing as &$o) {
        $o['participants'] = DB::all("SELECT u.name, cp.status, cp.is_host FROM challenge_participants cp INNER JOIN users u ON u.id = cp.user_id WHERE cp.challenge_id = ? AND cp.is_host = 0", [$o['id']]);
    }
    unset($o);

    $history = DB::all("SELECT c.id, c.quiz_id, c.status, c.created_at, q.title AS quiz_title, c.winner_id, w.name as winner_name, cp.score_final, cp.time_taken, cp.status as my_status FROM challenge_participants cp INNER JOIN challenges c ON c.id = cp.challenge_id INNER JOIN quizzes q ON q.id = c.quiz_id LEFT JOIN users w ON w.id = c.winner_id WHERE cp.user_id = ? AND c.status = 'completed' ORDER BY c.created_at DESC LIMIT 20", [$user['id']]);
    
    jsonSuccess(['incoming' => $incoming, 'outgoing' => $outgoing, 'history' => $history]);
}

function challenge_accept(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    $user = requireAuth();
    $body = getBody();
    $challengeId = (int)($body['challenge_id'] ?? 0);
    $accept = !empty($body['accept']);

    if (!$challengeId) jsonError('Challenge ID diperlukan');
    $cp = DB::one("SELECT * FROM challenge_participants WHERE challenge_id = ? AND user_id = ?", [$challengeId, $user['id']]);
    if (!$cp || $cp['status'] !== 'pending') jsonError('Tantangan tidak valid', 404);
    $c = DB::one("SELECT * FROM challenges WHERE id = ?", [$challengeId]);
    if (!$c || $c['status'] !== 'pending') jsonError('Tantangan kadaluarsa', 400);

    if (!$accept) {
        DB::execute("UPDATE challenge_participants SET status = 'declined' WHERE id = ?", [$cp['id']]);
        jsonSuccess([], 'Tantangan ditolak.');
    }

    DB::execute("UPDATE challenge_participants SET status = 'accepted' WHERE id = ?", [$cp['id']]);
    $pendingCount = DB::one("SELECT COUNT(*) as c FROM challenge_participants WHERE challenge_id = ? AND status = 'pending'", [$challengeId])['c'];
    
    if ($pendingCount == 0) {
        $acceptedCount = DB::one("SELECT COUNT(*) as c FROM challenge_participants WHERE challenge_id = ? AND status = 'accepted'", [$challengeId])['c'];
        if ($acceptedCount > 1) {
            DB::execute("UPDATE challenges SET status = 'playing', start_time = DATE_ADD(NOW(), INTERVAL 5 SECOND) WHERE id = ?", [$challengeId]);
        } else {
            DB::execute("UPDATE challenges SET status = 'completed' WHERE id = ?", [$challengeId]);
        }
    }
    jsonSuccess([], 'Tantangan diterima. Menunggu yang lain...');
}

function challenge_status(): void {
    $user = requireAuth();
    $challengeId = (int)($_GET['id'] ?? 0);
    if (!$challengeId) jsonError('Challenge ID diperlukan');

    $c = DB::one("SELECT c.*, q.title as quiz_title, NOW() as server_now FROM challenges c JOIN quizzes q ON q.id = c.quiz_id WHERE c.id = ?", [$challengeId]);
    if (!$c) jsonError('Not found', 404);

    $participants = DB::all("SELECT cp.user_id, cp.status, cp.progress, cp.score_final, u.name, cp.is_host FROM challenge_participants cp JOIN users u ON u.id = cp.user_id WHERE cp.challenge_id = ?", [$challengeId]);
    $c['participants'] = $participants;
    
    $c['is_participant'] = false;
    foreach ($participants as $p) {
        if ((int)$p['user_id'] === (int)$user['id']) { $c['is_participant'] = true; break; }
    }
    jsonSuccess($c);
}

function challenge_sync(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    $user = requireAuth();
    $body = getBody();
    $challengeId = (int)($body['challenge_id'] ?? 0);
    $score = (int)($body['score'] ?? 0);
    $qIndex = (int)($body['q_index'] ?? 0);
    if (!$challengeId) jsonError('Challenge ID diperlukan');

    $c = DB::one("SELECT status, start_time FROM challenges WHERE id = ?", [$challengeId]);
    if (!$c) jsonError('Tantangan tidak ditemukan', 404);

    $myProgress = json_encode(['s' => $score, 'q' => $qIndex, 'last_ping' => time()]);
    DB::execute("UPDATE challenge_participants SET progress = ? WHERE challenge_id = ? AND user_id = ?", [$myProgress, $challengeId, $user['id']]);

    $participants = DB::all("SELECT cp.user_id, cp.progress, u.name, cp.score_final FROM challenge_participants cp JOIN users u ON u.id = cp.user_id WHERE cp.challenge_id = ? AND cp.status = 'accepted'", [$challengeId]);

    $activeCount = 0;
    foreach ($participants as &$p) {
        $prog = $p['progress'] ? json_decode($p['progress'], true) : null;
        if ($c['status'] === 'playing' && $prog && !empty($prog['last_ping'])) {
            if (time() - $prog['last_ping'] > 25) {
                $p['disconnected'] = true;
            } else { $p['disconnected'] = false; $activeCount++; }
        } else { $p['disconnected'] = false; $activeCount++; }
        $p['progress_data'] = $prog;
    }
    unset($p);

    if ($c['status'] === 'playing' && $activeCount <= 1 && count($participants) > 1) {
        DB::execute("UPDATE challenges SET status = 'completed' WHERE id = ?", [$challengeId]);
        $c['status'] = 'completed';
    }

    jsonSuccess(['status' => $c['status'], 'start_time' => $c['start_time'], 'participants' => $participants]);
}

function challenge_submit(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    $user = requireAuth();
    $body = getBody();
    $challengeId = (int)($body['challenge_id'] ?? 0);
    $attemptId   = (int)($body['attempt_id'] ?? 0);
    if (!$challengeId || !$attemptId) jsonError('parameter diperlukan');

    $attempt = DB::one("SELECT score, time_taken FROM attempts WHERE id = ?", [$attemptId]);
    if (!$attempt) jsonError('Attempt invalid', 404);

    DB::execute("UPDATE challenge_participants SET attempt_id = ?, score_final = ?, time_taken = ? WHERE challenge_id = ? AND user_id = ?", [$attemptId, $attempt['score'], $attempt['time_taken'], $challengeId, $user['id']]);

    $totalAccepted = DB::one("SELECT COUNT(*) as c FROM challenge_participants WHERE challenge_id = ? AND status = 'accepted'", [$challengeId])['c'];
    $totalSubmitted = DB::one("SELECT COUNT(*) as c FROM challenge_participants WHERE challenge_id = ? AND attempt_id IS NOT NULL", [$challengeId])['c'];

    if ($totalSubmitted >= $totalAccepted) {
        $winner = DB::one("SELECT user_id FROM challenge_participants WHERE challenge_id = ? AND attempt_id IS NOT NULL ORDER BY score_final DESC, time_taken ASC LIMIT 1", [$challengeId]);
        DB::execute("UPDATE challenges SET status = 'completed', winner_id = ? WHERE id = ?", [$winner['user_id'] ?? null, $challengeId]);
    }
    jsonSuccess([], 'Tersubmit');
}


function challenge_search_users(): void {
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


function challenge_delete(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') jsonError('Method not allowed', 405);
    $user = requireAuth();
    $challengeId = (int)($_GET['id'] ?? 0);
    if (!$challengeId) jsonError('Challenge ID diperlukan');

    $c = DB::one("SELECT id FROM challenges WHERE id = ?", [$challengeId]);
    if (!$c) jsonError('Tantangan tidak ditemukan', 404);

    // Hapus peserta dan tantangan
    DB::execute("DELETE FROM challenge_participants WHERE challenge_id = ?", [$challengeId]);
    DB::execute("DELETE FROM challenges WHERE id = ?", [$challengeId]);

    jsonSuccess([], 'Tantangan berhasil dihapus.');
}


<?php
// ============================================
// api/challenge.php — Challenge Mode Endpoints
// ============================================

// POST — buat tantangan baru
function challenge_create(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    $user = requireAuth();
    $body = getBody();

    $quizId       = (int)($body['quiz_id']       ?? 0);
    $challengedId = (int)($body['challenged_id'] ?? 0);
    $message      = trim($body['message'] ?? '');

    if (!$quizId)       jsonError('Quiz ID diperlukan');
    if (!$challengedId) jsonError('User yang ditantang diperlukan');
    if ($challengedId === (int)$user['id']) jsonError('Tidak bisa menantang diri sendiri');

    $quiz = DB::one('SELECT id, title FROM quizzes WHERE id = ? AND is_published = 1', [$quizId]);
    if (!$quiz) jsonError('Quiz tidak ditemukan', 404);

    $challenged = DB::one('SELECT id, name FROM users WHERE id = ? AND is_active = 1', [$challengedId]);
    if (!$challenged) jsonError('User tidak ditemukan', 404);

    // Cegah duplikasi tantangan aktif
    $existing = DB::one(
        "SELECT id FROM challenges
         WHERE quiz_id = ? AND challenger_id = ? AND challenged_id = ?
           AND status IN ('pending','playing')",
        [$quizId, $user['id'], $challengedId]
    );
    if ($existing) jsonError('Sudah ada tantangan aktif untuk quiz ini dengan user tersebut');

    DB::execute(
        'INSERT INTO challenges (quiz_id, challenger_id, challenged_id, message, expires_at)
         VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))',
        [$quizId, $user['id'], $challengedId, $message ?: null]
    );
    $challengeId = (int)DB::lastId();

    // Notifikasi ke penerima tantangan
    pushNotification(
        $challengedId,
        'challenge',
        $user['name'] . ' menantangmu! ⚔️',
        'Kamu diundang bermain "' . $quiz['title'] . '"',
        '/challenges'
    );

    jsonSuccess([
        'challenge_id'     => $challengeId,
        'quiz_title'       => $quiz['title'],
        'challenged_name'  => $challenged['name'],
    ], 'Tantangan berhasil dikirim!');
}

// GET — daftar tantangan masuk & keluar untuk user login
function challenge_list(): void {
    $user = requireAuth();

    // Tantangan masuk yang masih menunggu jawaban (bisa diterima/ditolak)
    $incoming = DB::all(
        "SELECT c.id, c.quiz_id, c.message, c.created_at, c.expires_at,
                q.title AS quiz_title, q.total_questions, q.time_limit,
                u.name AS challenger_name, u.id AS challenger_id
         FROM challenges c
         INNER JOIN quizzes q ON q.id = c.quiz_id
         INNER JOIN users  u ON u.id = c.challenger_id
         WHERE c.challenged_id = ? AND c.status = 'pending' AND c.expires_at > NOW()
         ORDER BY c.created_at DESC",
        [$user['id']]
    );

    // Semua riwayat tantangan yang pernah diterima user ini (semua status)
    $received = DB::all(
        "SELECT c.id, c.quiz_id, c.status, c.message, c.created_at, c.expires_at,
                q.title AS quiz_title, q.total_questions, q.time_limit,
                u.name AS challenger_name, u.id AS challenger_id,
                w.name AS winner_name, w.id AS winner_id,
                a.score AS my_score, a.time_taken AS my_time,
                a2.score AS challenger_score, a2.time_taken AS challenger_time
         FROM challenges c
         INNER JOIN quizzes q  ON q.id  = c.quiz_id
         INNER JOIN users   u  ON u.id  = c.challenger_id
         LEFT JOIN  users   w  ON w.id  = c.winner_id
         LEFT JOIN  attempts a  ON a.id  = c.challenged_attempt_id
         LEFT JOIN  attempts a2 ON a2.id = c.challenger_attempt_id
         WHERE c.challenged_id = ? AND c.status != 'pending'
         ORDER BY c.created_at DESC
         LIMIT 20",
        [$user['id']]
    );

    $outgoing = DB::all(
        "SELECT c.id, c.quiz_id, c.status, c.created_at,
                q.title AS quiz_title,
                u.name AS challenged_name, u.id AS challenged_id,
                w.name AS winner_name
         FROM challenges c
         INNER JOIN quizzes q ON q.id = c.quiz_id
         INNER JOIN users   u ON u.id = c.challenged_id
         LEFT JOIN  users   w ON w.id = c.winner_id
         WHERE c.challenger_id = ?
         ORDER BY c.created_at DESC
         LIMIT 20",
        [$user['id']]
    );

    jsonSuccess([
        'incoming'      => $incoming,
        'received'      => $received,
        'outgoing'      => $outgoing,
        'pending_count' => count($incoming),
    ]);
}

// POST — terima tantangan
function challenge_accept(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    $user = requireAuth();
    $body = getBody();

    $challengeId = (int)($body['challenge_id'] ?? 0);
    if (!$challengeId) jsonError('Challenge ID diperlukan');

    $challenge = DB::one(
        "SELECT c.*, q.title AS quiz_title
         FROM challenges c INNER JOIN quizzes q ON q.id = c.quiz_id
         WHERE c.id = ? AND c.challenged_id = ? AND c.status = 'pending' AND c.expires_at > NOW()",
        [$challengeId, $user['id']]
    );
    if (!$challenge) jsonError('Tantangan tidak ditemukan atau sudah kedaluwarsa', 404);

    DB::execute("UPDATE challenges SET status = 'playing' WHERE id = ?", [$challengeId]);

    jsonSuccess([
        'challenge_id' => $challengeId,
        'quiz_id'      => (int)$challenge['quiz_id'],
        'quiz_title'   => $challenge['quiz_title'],
    ], 'Tantangan diterima! Selamat bermain.');
}

// POST — tolak tantangan
function challenge_decline(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    $user = requireAuth();
    $body = getBody();

    $challengeId = (int)($body['challenge_id'] ?? 0);
    if (!$challengeId) jsonError('Challenge ID diperlukan');

    $challenge = DB::one(
        "SELECT id, challenger_id FROM challenges WHERE id = ? AND challenged_id = ? AND status = 'pending'",
        [$challengeId, $user['id']]
    );
    if (!$challenge) jsonError('Tantangan tidak ditemukan', 404);

    DB::execute("UPDATE challenges SET status = 'declined' WHERE id = ?", [$challengeId]);

    // Notifikasi ke pengirim tantangan
    pushNotification(
        (int)$challenge['challenger_id'],
        'challenge',
        $user['name'] . ' menolak tantanganmu',
        'Tantanganmu tidak diterima. Coba tantang yang lain!',
        '/challenges'
    );

    jsonSuccess([], 'Tantangan ditolak.');
}

// GET — status tantangan (untuk polling hasil)
function challenge_status(): void {
    $user = requireAuth();
    $challengeId = (int)($_GET['id'] ?? 0);
    if (!$challengeId) jsonError('Challenge ID diperlukan');

    $c = DB::one(
        "SELECT c.id, c.status, c.quiz_id, c.challenger_id, c.challenged_id,
                c.challenger_attempt_id, c.challenged_attempt_id, c.winner_id,
                q.title AS quiz_title,
                u1.name AS challenger_name, u2.name AS challenged_name,
                w.name  AS winner_name,
                a1.score AS challenger_score, a1.time_taken AS challenger_time,
                a2.score AS challenged_score, a2.time_taken AS challenged_time
         FROM challenges c
         INNER JOIN quizzes q  ON q.id  = c.quiz_id
         INNER JOIN users   u1 ON u1.id = c.challenger_id
         INNER JOIN users   u2 ON u2.id = c.challenged_id
         LEFT JOIN  users   w  ON w.id  = c.winner_id
         LEFT JOIN  attempts a1 ON a1.id = c.challenger_attempt_id
         LEFT JOIN  attempts a2 ON a2.id = c.challenged_attempt_id
         WHERE c.id = ? AND (c.challenger_id = ? OR c.challenged_id = ?)",
        [$challengeId, $user['id'], $user['id']]
    );
    if (!$c) jsonError('Tantangan tidak ditemukan', 404);

    $c['is_challenger'] = (int)$c['challenger_id'] === (int)$user['id'];
    $c['is_winner']     = $c['winner_id'] && (int)$c['winner_id'] === (int)$user['id'];
    $c['is_draw']       = false; // tidak pernah seri

    // Cast numbers
    foreach (['challenger_score','challenger_time','challenged_score','challenged_time'] as $k) {
        $c[$k] = $c[$k] !== null ? (int)$c[$k] : null;
    }

    jsonSuccess($c);
}

// POST — simpan attempt ke tantangan & tentukan pemenang jika kedua selesai
function challenge_submit(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    $user = requireAuth();
    $body = getBody();

    $challengeId = (int)($body['challenge_id'] ?? 0);
    $attemptId   = (int)($body['attempt_id']   ?? 0);
    if (!$challengeId || !$attemptId) jsonError('challenge_id dan attempt_id diperlukan');

    $challenge = DB::one(
        "SELECT * FROM challenges
         WHERE id = ? AND (challenger_id = ? OR challenged_id = ?)
           AND status IN ('pending','playing')",
        [$challengeId, $user['id'], $user['id']]
    );
    if (!$challenge) jsonError('Tantangan tidak ditemukan atau sudah selesai', 404);

    $isChallenger = (int)$challenge['challenger_id'] === (int)$user['id'];

    if ($isChallenger) {
        DB::execute(
            "UPDATE challenges SET challenger_attempt_id = ?, status = 'playing' WHERE id = ?",
            [$attemptId, $challengeId]
        );
    } else {
        DB::execute(
            "UPDATE challenges SET challenged_attempt_id = ? WHERE id = ?",
            [$attemptId, $challengeId]
        );
    }

    // Re-fetch dan tentukan pemenang jika keduanya sudah submit
    $updated    = DB::one('SELECT * FROM challenges WHERE id = ?', [$challengeId]);
    $bothDone   = $updated['challenger_attempt_id'] && $updated['challenged_attempt_id'];
    $winnerId   = null;
    $isDraw     = false;

    if ($bothDone) {
        $a1 = DB::one('SELECT score, correct_count, time_taken FROM attempts WHERE id = ?', [(int)$updated['challenger_attempt_id']]);
        $a2 = DB::one('SELECT score, correct_count, time_taken FROM attempts WHERE id = ?', [(int)$updated['challenged_attempt_id']]);

        $s1 = (int)$a1['score'];
        $s2 = (int)$a2['score'];
        $c1 = (int)$a1['correct_count'];
        $c2 = (int)$a2['correct_count'];
        $t1 = (int)$a1['time_taken'];
        $t2 = (int)$a2['time_taken'];

        // Lapis 1: skor lebih tinggi
        if ($s1 !== $s2) {
            $winnerId = $s1 > $s2 ? $updated['challenger_id'] : $updated['challenged_id'];
        }
        // Lapis 2: jumlah benar lebih banyak (antisipasi pembulatan skor)
        elseif ($c1 !== $c2) {
            $winnerId = $c1 > $c2 ? $updated['challenger_id'] : $updated['challenged_id'];
        }
        // Lapis 3: waktu lebih cepat
        elseif ($t1 !== $t2) {
            $winnerId = $t1 < $t2 ? $updated['challenger_id'] : $updated['challenged_id'];
        }
        // Lapis 4 (final — tidak mungkin seri): attempt_id lebih kecil = submit lebih awal
        else {
            $winnerId = (int)$updated['challenger_attempt_id'] < (int)$updated['challenged_attempt_id']
                ? $updated['challenger_id']
                : $updated['challenged_id'];
        }
        // $isDraw selalu false — selalu ada pemenang

        DB::execute(
            "UPDATE challenges SET status = 'completed', winner_id = ? WHERE id = ?",
            [$winnerId, $challengeId]
        );

        // Notifikasi hasil ke kedua pemain
        $quizTitle   = DB::one("SELECT title FROM quizzes WHERE id = ?", [$updated['quiz_id']])['title'] ?? 'quiz';
        $challengerId = (int)$updated['challenger_id'];
        $challengedId = (int)$updated['challenged_id'];
        $winnerIdInt  = (int)$winnerId;

        foreach ([$challengerId, $challengedId] as $pid) {
            $iWin = $pid === $winnerIdInt;
            pushNotification(
                $pid,
                'challenge_result',
                $iWin ? '🏆 Kamu menang!' : '😔 Kamu kalah',
                ($iWin ? 'Selamat! Kamu memenangkan tantangan "' : 'Sayang sekali, kamu kalah di tantangan "') . $quizTitle . '"',
                '/challenges'
            );
        }
    }

    jsonSuccess([
        'both_done'    => $bothDone,
        'is_draw'      => false,   // tidak pernah seri — selalu ada pemenang
        'winner_id'    => $winnerId ? (int)$winnerId : null,
        'challenge_id' => $challengeId,
    ], $bothDone ? 'Tantangan selesai!' : 'Hasil disimpan, menunggu lawan selesai.');
}

// DELETE — hapus tantangan (oleh pengirim atau penerima)
function challenge_delete(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') jsonError('Method not allowed', 405);
    $user        = requireAuth();
    $challengeId = (int)($_GET['id'] ?? 0);
    if (!$challengeId) jsonError('Challenge ID diperlukan');

    // User boleh menghapus jika dia adalah pengirim ATAU penerima
    $c = DB::one(
        "SELECT id, challenger_id, challenged_id FROM challenges WHERE id = ?",
        [$challengeId]
    );
    if (!$c) jsonError('Tantangan tidak ditemukan', 404);

    $uid = (int)$user['id'];
    if ((int)$c['challenger_id'] !== $uid && (int)$c['challenged_id'] !== $uid) {
        jsonError('Akses ditolak', 403);
    }

    // Hapus attempt_answers terkait agar tidak ada foreign key error
    $pdo = DB::conn();
    $pdo->prepare(
        "DELETE aa FROM attempt_answers aa
         JOIN attempts a ON aa.attempt_id = a.id
         WHERE a.id IN (
             SELECT v FROM (
                 SELECT challenger_attempt_id AS v FROM challenges WHERE id = ?
                 UNION
                 SELECT challenged_attempt_id  AS v FROM challenges WHERE id = ?
             ) sub WHERE v IS NOT NULL
         )"
    )->execute([$challengeId, $challengeId]);

    $pdo->prepare(
        "DELETE FROM attempts WHERE id IN (
             SELECT v FROM (
                 SELECT challenger_attempt_id AS v FROM challenges WHERE id = ?
                 UNION
                 SELECT challenged_attempt_id  AS v FROM challenges WHERE id = ?
             ) sub WHERE v IS NOT NULL
         )"
    )->execute([$challengeId, $challengeId]);

    $pdo->prepare("DELETE FROM challenges WHERE id = ?")->execute([$challengeId]);

    jsonSuccess([], 'Tantangan berhasil dihapus.');
}

// GET — cari user untuk ditantang
function challenge_search_users(): void {
    $user = requireAuth();
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) jsonError('Ketikkan minimal 2 karakter');

    $users = DB::all(
        "SELECT id, name, email FROM users
         WHERE (name LIKE ? OR email LIKE ?) AND id != ? AND is_active = 1
         LIMIT 10",
        ["%$q%", "%$q%", $user['id']]
    );

    jsonSuccess($users);
}

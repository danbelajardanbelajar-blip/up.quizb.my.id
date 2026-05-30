<?php
// ============================================
// api/attempt.php — Attempt & History Endpoints
// ============================================

function attempt_submit(): void {
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);

        // Siapapun bisa submit — login atau anonim
        $user = getCurrentUserOrAnon();
        $body = getBody();

        $quizId        = (int)($body['quiz_id']    ?? 0);
        $answers       = $body['answers']          ?? [];
        $timeTaken     = max(0, min(65535, (int)($body['time_taken'] ?? 0)));
        $displayedQIds = array_values(array_filter(array_unique(array_map('intval', $body['question_ids'] ?? [])), fn($id) => $id > 0));
        $playerName    = trim($body['player_name'] ?? '');
        if (mb_strlen($playerName) > 40) $playerName = mb_substr($playerName, 0, 40);

        $mode = sanitizeString($body['mode'] ?? 'exam');
        if (!in_array($mode, ['exam', 'instant', 'end', 'challenge'], true)) {
            $mode = 'exam';
        }

        if (!$quizId) jsonError('Quiz ID diperlukan');
            if (!$quizId) jsonError('Quiz ID diperlukan');
        if (!is_array($answers)) jsonError('Format jawaban tidak valid');

    $quiz = DB::one('SELECT id, total_questions, passing_score FROM quizzes WHERE id = ? AND is_published = 1', [$quizId]);
    if (!$quiz) jsonError('Quiz tidak ditemukan', 404);

    // Normalisasi format answers: [{question_id, option_id}] atau {qid: oid}
    $answerMap = [];
    if (is_array($answers) && isset($answers[0]['question_id'])) {
        foreach ($answers as $a) {
            $answerMap[(int)$a['question_id']] = (int)($a['option_id'] ?? 0);
        }
    } else {
        foreach ($answers as $qid => $oid) {
            $answerMap[(int)$qid] = (int)$oid;
        }
    }

    // Ambil hanya soal yang ditampilkan ke user.
    // Jika question_ids dikirim → filter ke soal tersebut (WAJIB untuk penilaian akurat).
    // PENTING: tidak lagi memfilter by quiz_id agar mendukung multiple quiz packages.
    // Soal yang tidak dikerjakan tidak boleh dihitung salah.
    if (!empty($displayedQIds)) {
        $placeholders = implode(',', array_fill(0, count($displayedQIds), '?'));
        $questions = DB::all(
            "SELECT q.id, q.points FROM questions q
             WHERE q.id IN ($placeholders)",
            $displayedQIds
        );
    } elseif (!empty($answerMap)) {
        // Fallback: hanya soal yang memang dijawab oleh pelajar
        $answeredIds  = array_keys($answerMap);
        $placeholders = implode(',', array_fill(0, count($answeredIds), '?'));
        $questions = DB::all(
            "SELECT q.id, q.points FROM questions q
             WHERE q.id IN ($placeholders)",
            $answeredIds
        );
    } else {
        // Tidak ada jawaban sama sekali
        $questions = [];
    }

    if (empty($questions)) {
        jsonError('Quiz tidak memiliki soal yang valid untuk disubmit', 400);
    }

    // Ambil kunci jawaban HANYA untuk soal yang ditampilkan (bukan seluruh quiz)
    // Ini mencegah bug di mana soal dengan is_correct duplikat menyebabkan kunci salah
    $shownQIds      = array_column($questions, 'id');
    $placeholders   = implode(',', array_fill(0, count($shownQIds), '?'));
    $correctOptions = DB::all(
        "SELECT o.question_id,
                MIN(o.id) AS option_id
         FROM options o
         WHERE o.question_id IN ($placeholders) AND o.is_correct = 1
         GROUP BY o.question_id",
        $shownQIds
    );

    // Cast ke int: PDO mengembalikan string, perbandingan === dengan int akan selalu false
    $correctMap = [];
    foreach ($correctOptions as $co) {
        $correctMap[(int)$co['question_id']] = (int)$co['option_id'];
    }

    $totalPoints  = 0;
    $earnedPoints = 0;
    $correctCount = 0;
    $wrongCount   = 0;
    $answerRows   = [];

    foreach ($questions as $q) {
        $pts          = (int)($q['points'] ?: 1); // fallback 1 jika points = 0 atau NULL
        $totalPoints += $pts;
        $qId              = (int)$q['id'];
        $selectedOptionId = (int)($answerMap[$qId] ?? 0);
        $correctOptionId  = (int)($correctMap[$qId] ?? -1);
        // is_correct: hanya true jika pelajar memilih jawaban DAN jawaban itu benar
        // Soal tidak dijawab ($selectedOptionId = 0) → is_correct = 0, tapi TIDAK dihitung sebagai wrongCount
        $isCorrect = ($selectedOptionId > 0 && $correctOptionId > 0 && $correctOptionId === $selectedOptionId) ? 1 : 0;
        if ($isCorrect) {
            $earnedPoints += $pts;
            $correctCount++;
        } elseif ($selectedOptionId > 0) {
            // Hanya hitung salah jika soal dijawab tapi salah
            $wrongCount++;
        }
        $answerRows[] = [$qId, $selectedOptionId ?: null, $isCorrect];
    }

    $score        = $totalPoints > 0 ? (int)round(($earnedPoints / $totalPoints) * 100) : 0;
    $passingScore = (int)($quiz['passing_score'] ?? 60);
    $passed       = $score >= $passingScore ? 1 : 0;

    // Insert attempt
    DB::execute(
        'INSERT INTO attempts (user_id, quiz_id, mode, score, total_points, correct_count, time_taken, completed_at) VALUES (?,?,?,?,?,?,?,NOW())',
        [$user['id'], $quizId, $mode, $score, $totalPoints, $correctCount, $timeTaken]
    );
    $attemptId = (int)DB::lastId();

    // Simpan attempt_id di session untuk akses result tanpa login
    startSecureSession();
    if (!isset($_SESSION['my_attempts'])) $_SESSION['my_attempts'] = [];
    $_SESSION['my_attempts'][$attemptId] = $user['id'];

    // Bulk insert answers
    foreach ($answerRows as [$qid, $oid, $correct]) {
        DB::execute(
            'INSERT INTO attempt_answers (attempt_id, question_id, option_id, is_correct) VALUES (?,?,?,?)',
            [$attemptId, $qid, $oid, $correct]
        );
    }

    // Jika tamu memberikan nama kustom → update nama di DB & session
    if (!empty($playerName) && ($user['is_anon'] ?? false)) {
        DB::execute('UPDATE users SET name = ? WHERE id = ?', [$playerName, $user['id']]);
        startSecureSession();
        $_SESSION['anon_user_name'] = $playerName;
        $user['name'] = $playerName;
    }

    // Update user stats (hanya user aktif)
    if (!($user['is_anon'] ?? false)) {
        DB::execute(
            'UPDATE users SET total_points = total_points + ?, quizzes_taken = quizzes_taken + 1 WHERE id = ?',
            [$score, $user['id']]
        );
    }

    // Update quiz attempts count
    DB::execute('UPDATE quizzes SET total_attempts = total_attempts + 1 WHERE id = ?', [$quizId]);

    // Update difficulty otomatis berdasarkan performa quiz
    updateQuizDifficultyFromStats($quizId);

    jsonSuccess([
        'attempt_id'      => $attemptId,
        'score'           => $score,
        'correct_count'   => $correctCount,
        'wrong_count'     => $wrongCount,
        'total_questions' => count($questions),
        'total_points'    => $totalPoints,
        'earned_points'   => $earnedPoints,
        'time_taken'      => $timeTaken,
        'passed'          => (bool)$passed,
        'user_name'       => $user['name'],
        'is_anon'         => $user['is_anon'] ?? false,
    ], 'Quiz berhasil diselesaikan');
    } catch (Throwable $e) {
        error_log("[attempt.submit] " . $e->__toString());
        jsonError('Terjadi kesalahan server', 500);
    }
}

function updateQuizDifficultyFromStats(int $quizId): void {
    $stats = DB::one(
        'SELECT COUNT(*) AS attempts, COALESCE(AVG(score), 0) AS avg_score
         FROM attempts
         WHERE quiz_id = ?',
        [$quizId]
    );

    if (!$stats || (int)$stats['attempts'] < 5) {
        return; // Belum cukup data untuk menentukan difficulty otomatis
    }

    $avgScore = (float)$stats['avg_score'];
    if ($avgScore >= 80) {
        $newDifficulty = 'easy';
    } elseif ($avgScore <= 50) {
        $newDifficulty = 'hard';
    } else {
        $newDifficulty = 'medium';
    }

    DB::execute(
        'UPDATE quizzes SET difficulty = ? WHERE id = ? AND difficulty <> ?',
        [$newDifficulty, $quizId, $newDifficulty]
    );
}

function attempt_result(): void {
    try {
    $attemptId = (int)($_GET['id'] ?? 0);
    if (!$attemptId) jsonError('Attempt ID diperlukan');

    startSecureSession();
    $sessionUserId = $_SESSION['user_id'] ?? null;
    $myAttempts    = $_SESSION['my_attempts'] ?? [];

    $attempt = DB::one(
        "SELECT a.id, a.user_id, a.quiz_id, a.score, a.total_points, a.correct_count, a.time_taken, a.completed_at,
                a.mode,
                q.title AS quiz_title, q.total_questions, q.passing_score, q.time_limit,
                c.name AS category_name, c.icon AS category_icon,
                u.name AS player_name
         FROM attempts a
         INNER JOIN quizzes q ON q.id = a.quiz_id
         INNER JOIN categories c ON c.id = q.category_id
         INNER JOIN users u ON u.id = a.user_id
         WHERE a.id = ?",
        [$attemptId]
    );
    if (!$attempt) jsonError('Hasil tidak ditemukan', 404);

    // Cek otorisasi
    $canView = false;
    if ($sessionUserId && (int)$attempt['user_id'] === (int)$sessionUserId) $canView = true;
    if (isset($myAttempts[$attemptId])) $canView = true;

    // Admin selalu boleh lihat
    if (!$canView && $sessionUserId) {
        $viewer = DB::one("SELECT id, role FROM users WHERE id = ?", [$sessionUserId]);
        if ($viewer && $viewer['role'] === 'admin') {
            $canView = true;
        } elseif ($viewer && $viewer['role'] === 'pengajar') {
            // Pengajar boleh lihat jika pemilik attempt adalah anggota salah satu kelasnya
            $inClass = DB::one(
                "SELECT cm.id FROM class_members cm
                 JOIN classes c ON c.id = cm.class_id
                 WHERE cm.user_id = ? AND c.teacher_id = ?
                 LIMIT 1",
                [$attempt['user_id'], $sessionUserId]
            );
            if ($inClass) $canView = true;
        }
    }

    if (!$canView) jsonError('Akses ditolak', 403);

    // Ambil jawaban lengkap dengan opsi
    $answers = DB::all(
        "SELECT aa.question_id, aa.option_id AS selected_option_id, aa.is_correct,
                q.question_text, q.explanation,
                (SELECT id   FROM options WHERE question_id = q.id AND is_correct = 1 LIMIT 1) AS correct_option_id,
                (SELECT option_text FROM options WHERE question_id = q.id AND is_correct = 1 LIMIT 1) AS correct_answer,
                (SELECT option_text FROM options WHERE id = aa.option_id LIMIT 1) AS selected_answer
         FROM attempt_answers aa
         INNER JOIN questions q ON q.id = aa.question_id
         WHERE aa.attempt_id = ?
         ORDER BY q.order_num",
        [$attemptId]
    );

    // Hitung field tambahan.
    // Gunakan jumlah baris attempt_answers sebagai total soal yang DITAMPILKAN
    // (bukan q.total_questions yang mencerminkan semua soal di database).
    $shownCount   = count($answers);
    $correctCount = (int)$attempt['correct_count'];
    $passingScore = (int)($attempt['passing_score'] ?? 60);
    $attempt['passed']          = $attempt['score'] >= $passingScore;
    $attempt['total_questions'] = $shownCount;
    $attempt['wrong_count']     = $shownCount - $correctCount;
    // Cast numerik ke int agar perbandingan === di JavaScript tidak kena type mismatch
    $attempt['id']      = (int)$attempt['id'];
    $attempt['user_id'] = (int)$attempt['user_id'];
    $attempt['quiz_id'] = (int)$attempt['quiz_id'];
    $attempt['score']   = (int)$attempt['score'];

    // Ambil semua opsi per soal untuk tampilan review
    // PENTING: cast ke tipe yang benar agar JSON tidak mengirim string "0"/"1"
    // Di JavaScript, string "0" adalah TRUTHY sehingga semua opsi akan tampil sebagai ✅
    foreach ($answers as &$row) {
        // Cast is_correct jawaban (dari attempt_answers) ke bool
        $row['is_correct']        = (bool)$row['is_correct'];
        // Cast ID ke int agar opt.id === selected_option_id bekerja di JS
        $row['selected_option_id'] = $row['selected_option_id'] !== null ? (int)$row['selected_option_id'] : null;
        $row['correct_option_id']  = $row['correct_option_id']  !== null ? (int)$row['correct_option_id']  : null;

        $row['options'] = DB::all(
            "SELECT id, option_text, is_correct, order_num FROM options WHERE question_id = ? ORDER BY order_num",
            [$row['question_id']]
        );
        // Cast setiap opsi: is_correct ke bool, id ke int
        foreach ($row['options'] as &$opt) {
            $opt['id']         = (int)$opt['id'];
            $opt['is_correct'] = (bool)$opt['is_correct'];
        }
        unset($opt);
    }
    unset($row);

    jsonSuccess([
        'attempt' => $attempt,
        'answers' => $answers,
    ]);
    } catch (Throwable $e) {
        error_log("[attempt.result] " . $e->__toString());
        jsonError('Terjadi kesalahan server', 500);
    }
}

function attempt_history(): void {
    try {
    $user = requireAuth();
    [$page, $limit, $offset] = getPaginationParams();

    $total = (int)DB::one(
        'SELECT COUNT(*) AS cnt FROM attempts WHERE user_id = ?',
        [$user['id']]
    )['cnt'];

    $history = DB::all(
        "SELECT a.id, a.score, a.correct_count, a.time_taken, a.completed_at,
                q.id AS quiz_id, q.title AS quiz_title, q.total_questions, q.difficulty, q.passing_score,
                c.name AS category_name, c.icon AS category_icon,
                u.name AS user_name,
                a.mode,
                IF(a.score >= q.passing_score, 1, 0) AS passed
         FROM attempts a
         INNER JOIN quizzes q ON q.id = a.quiz_id
         INNER JOIN categories c ON c.id = q.category_id
         LEFT JOIN users u ON u.id = a.user_id
         WHERE a.user_id = ?
         ORDER BY a.completed_at DESC
         LIMIT ? OFFSET ?",
        [$user['id'], $limit, $offset]
    );

    // Cast booleans
    foreach ($history as &$h) {
        $h['passed'] = (bool)$h['passed'];
    }
    unset($h);

    jsonPaginated($history, $total, $page, $limit);
    } catch (Throwable $e) {
        error_log("[attempt.history] " . $e->__toString());
        jsonError('Terjadi kesalahan server', 500);
    }
}

function attempt_dashboard(): void {
    try {
    $user = requireAuth();

    $stats = DB::one(
        "SELECT
            COUNT(*) AS total_attempts,
            COALESCE(SUM(correct_count), 0) AS total_correct,
            COALESCE(ROUND(AVG(score), 1), 0) AS avg_score,
            COALESCE(MAX(score), 0) AS best_score,
            COALESCE(SUM(time_taken), 0) AS total_time
         FROM attempts WHERE user_id = ?",
        [$user['id']]
    );

    $userInfo = DB::one(
        'SELECT name, email, total_points, quizzes_taken, created_at FROM users WHERE id = ?',
        [$user['id']]
    );

    // Gabungkan stats dari DB dan dari users table
    $stats['total_points']  = (int)($userInfo['total_points'] ?? 0);
    $stats['quizzes_taken'] = (int)($userInfo['quizzes_taken'] ?? 0);

    $recent = DB::all(
        "SELECT a.id, a.score, a.correct_count, a.time_taken, a.completed_at,
                q.id AS quiz_id, q.title AS quiz_title, q.total_questions, q.difficulty, q.passing_score,
                c.icon AS category_icon, c.name AS category_name,
                IF(a.score >= q.passing_score, 1, 0) AS passed
         FROM attempts a
         INNER JOIN quizzes q ON q.id = a.quiz_id
         INNER JOIN categories c ON c.id = q.category_id
         WHERE a.user_id = ?
         ORDER BY a.completed_at DESC LIMIT 5",
        [$user['id']]
    );

    foreach ($recent as &$r) {
        $r['passed'] = (bool)$r['passed'];
    }
    unset($r);

    jsonSuccess([
        'user'   => $userInfo,
        'stats'  => $stats,
        'recent' => $recent,
    ]);
    } catch (Throwable $e) {
        error_log("[attempt.dashboard] " . $e->__toString());
        jsonError('Terjadi kesalahan server', 500);
    }
}

function attempt_quiz_global_history(): void {
    try {
    [$page, $limit, $offset] = getPaginationParams();

    $total = DB::one("SELECT COUNT(*) AS cnt FROM attempts WHERE completed_at IS NOT NULL")['cnt'];

    $history = DB::all(
        "SELECT a.id, a.score, a.correct_count, a.time_taken, a.completed_at,
                a.mode,
                q.title AS quiz_title, q.difficulty, q.total_questions,
                COALESCE(u.name, 'Anonymous') AS user_name,
                CASE WHEN u.id IS NULL OR u.id = 0 THEN 1 ELSE 0 END AS is_anon
         FROM attempts a
         INNER JOIN quizzes q ON q.id = a.quiz_id
         LEFT JOIN users u ON u.id = a.user_id
         WHERE a.completed_at IS NOT NULL
         ORDER BY a.completed_at DESC
         LIMIT ? OFFSET ?",
        [$limit, $offset]
    );

    // Cast booleans
    foreach ($history as &$h) {
        $h['is_anon'] = (bool)$h['is_anon'];
    }
    unset($h);

    jsonPaginated($history, (int)$total, $page, $limit);
    } catch (Throwable $e) {
        error_log("[attempt.quiz_global_history] " . $e->__toString());
        jsonError('Terjadi kesalahan server', 500);
    }
}

function quiz_global_history(): void {
    attempt_quiz_global_history();
}

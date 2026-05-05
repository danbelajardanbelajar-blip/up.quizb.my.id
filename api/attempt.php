<?php
// ============================================
// api/attempt.php — Attempt & History Endpoints
// ============================================

function attempt_submit(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);

    // Siapapun bisa submit — login atau anonim
    $user = getCurrentUserOrAnon();
    $body = getBody();

    $quizId    = (int)($body['quiz_id']   ?? 0);
    $answers   = $body['answers']         ?? [];
    $timeTaken = (int)($body['time_taken'] ?? 0);

    if (!$quizId) jsonError('Quiz ID diperlukan');
    if (!is_array($answers)) jsonError('Format jawaban tidak valid');

    $quiz = DB::one('SELECT id, total_questions FROM quizzes WHERE id = ? AND is_published = 1', [$quizId]);
    if (!$quiz) jsonError('Quiz tidak ditemukan', 404);

    // Ubah format answers: [{question_id, option_id}] → map
    $answerMap = [];
    if (is_array($answers) && isset($answers[0]['question_id'])) {
        // Format array of objects dari quiz-engine.js
        foreach ($answers as $a) {
            $answerMap[(int)$a['question_id']] = (int)($a['option_id'] ?? 0);
        }
    } else {
        // Format associative {question_id: option_id}
        foreach ($answers as $qid => $oid) {
            $answerMap[(int)$qid] = (int)$oid;
        }
    }

    $questions = DB::all(
        'SELECT q.id, q.points FROM questions q WHERE q.quiz_id = ?',
        [$quizId]
    );

    $correctOptions = DB::all(
        "SELECT o.id AS option_id, o.question_id
         FROM options o
         INNER JOIN questions q ON q.id = o.question_id
         WHERE q.quiz_id = ? AND o.is_correct = 1",
        [$quizId]
    );

    $correctMap = [];
    foreach ($correctOptions as $co) {
        $correctMap[$co['question_id']] = $co['option_id'];
    }

    $totalPoints  = 0;
    $earnedPoints = 0;
    $correctCount = 0;
    $answerRows   = [];

    foreach ($questions as $q) {
        $totalPoints += $q['points'];
        $selectedOptionId = $answerMap[$q['id']] ?? 0;
        $isCorrect = ($selectedOptionId > 0 && ($correctMap[$q['id']] ?? -1) === $selectedOptionId) ? 1 : 0;
        if ($isCorrect) {
            $earnedPoints += $q['points'];
            $correctCount++;
        }
        $answerRows[] = [$q['id'], $selectedOptionId ?: null, $isCorrect];
    }

    $score = $totalPoints > 0 ? (int)round(($earnedPoints / $totalPoints) * 100) : 0;

    // Insert attempt
    DB::execute(
        'INSERT INTO attempts (user_id, quiz_id, score, total_points, correct_count, time_taken) VALUES (?,?,?,?,?,?)',
        [$user['id'], $quizId, $score, $totalPoints, $correctCount, $timeTaken]
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

    // Update user stats (untuk user aktif saja, bukan anonim)
    if (!($user['is_anon'] ?? false)) {
        DB::execute(
            'UPDATE users SET total_points = total_points + ?, quizzes_taken = quizzes_taken + 1 WHERE id = ?',
            [$score, $user['id']]
        );
    }

    // Update quiz attempts count
    DB::execute('UPDATE quizzes SET total_attempts = total_attempts + 1 WHERE id = ?', [$quizId]);

    jsonSuccess([
        'attempt_id'      => $attemptId,
        'score'           => $score,
        'correct_count'   => $correctCount,
        'total_questions' => count($questions),
        'total_points'    => $totalPoints,
        'earned_points'   => $earnedPoints,
        'time_taken'      => $timeTaken,
        'user_name'       => $user['name'],
        'is_anon'         => $user['is_anon'] ?? false,
    ], 'Quiz berhasil diselesaikan');
}

function attempt_result(): void {
    $attemptId = (int)($_GET['id'] ?? 0);
    if (!$attemptId) jsonError('Attempt ID diperlukan');

    // Cek akses: bisa via session (user login atau anonim yang baru submit)
    startSecureSession();
    $sessionUserId = $_SESSION['user_id'] ?? null;
    $myAttempts    = $_SESSION['my_attempts'] ?? [];

    $attempt = DB::one(
        "SELECT a.*, q.title AS quiz_title, q.total_questions, u.name AS player_name
         FROM attempts a
         INNER JOIN quizzes q ON q.id = a.quiz_id
         INNER JOIN users u ON u.id = a.user_id
         WHERE a.id = ?",
        [$attemptId]
    );
    if (!$attempt) jsonError('Hasil tidak ditemukan', 404);

    // Cek otorisasi: punya attempt ini?
    $canView = false;
    if ($sessionUserId && (int)$attempt['user_id'] === (int)$sessionUserId) $canView = true;
    if (isset($myAttempts[$attemptId])) $canView = true; // anonim yg baru submit
    if (!$canView) jsonError('Akses ditolak', 403);

    $answers = DB::all(
        "SELECT aa.question_id, aa.option_id AS selected_option_id, aa.is_correct,
                q.question_text, q.explanation,
                (SELECT option_text FROM options WHERE question_id = q.id AND is_correct = 1 LIMIT 1) AS correct_answer,
                (SELECT option_text FROM options WHERE id = aa.option_id LIMIT 1) AS selected_answer
         FROM attempt_answers aa
         INNER JOIN questions q ON q.id = aa.question_id
         WHERE aa.attempt_id = ?
         ORDER BY q.order_num",
        [$attemptId]
    );

    jsonSuccess(['attempt' => $attempt, 'answers' => $answers]);
}

function attempt_history(): void {
    $user = requireAuth();
    [$page, $limit, $offset] = getPaginationParams();

    $total = DB::one(
        'SELECT COUNT(*) AS cnt FROM attempts WHERE user_id = ?',
        [$user['id']]
    )['cnt'];

    $history = DB::all(
        "SELECT a.id, a.score, a.correct_count, a.time_taken, a.completed_at,
                q.id AS quiz_id, q.title AS quiz_title, q.total_questions, q.difficulty,
                c.name AS category_name, c.icon AS category_icon
         FROM attempts a
         INNER JOIN quizzes q ON q.id = a.quiz_id
         INNER JOIN categories c ON c.id = q.category_id
         WHERE a.user_id = ?
         ORDER BY a.completed_at DESC
         LIMIT ? OFFSET ?",
        [$user['id'], $limit, $offset]
    );

    jsonPaginated($history, (int)$total, $page, $limit);
}

function attempt_dashboard(): void {
    $user = requireAuth();

    $stats = DB::one(
        "SELECT
            COUNT(*) AS total_attempts,
            COALESCE(AVG(score), 0) AS avg_score,
            COALESCE(MAX(score), 0) AS best_score,
            COALESCE(SUM(time_taken), 0) AS total_time
         FROM attempts WHERE user_id = ?",
        [$user['id']]
    );

    $recent = DB::all(
        "SELECT a.id, a.score, a.completed_at, q.title, q.difficulty, c.icon
         FROM attempts a
         INNER JOIN quizzes q ON q.id = a.quiz_id
         INNER JOIN categories c ON c.id = q.category_id
         WHERE a.user_id = ?
         ORDER BY a.completed_at DESC LIMIT 5",
        [$user['id']]
    );

    $userInfo = DB::one(
        'SELECT name, email, total_points, quizzes_taken, created_at FROM users WHERE id = ?',
        [$user['id']]
    );

    jsonSuccess([
        'user'   => $userInfo,
        'stats'  => $stats,
        'recent' => $recent,
    ]);
}

<?php
// ============================================
// api/leaderboard.php — Leaderboard Endpoints
// ============================================

function leaderboard_global(): void {
    [$page, $limit, $offset] = getPaginationParams();
    $limit = min(50, $limit);

    $total = DB::one("SELECT COUNT(DISTINCT user_id) AS cnt FROM attempts")['cnt'];

    $rows = DB::all(
        "SELECT
            u.id, u.name, u.avatar,
            SUM(a.score) AS total_score,
            COUNT(a.id) AS total_attempts,
            ROUND(AVG(a.score), 1) AS avg_score,
            MAX(a.score) AS best_score,
            MAX(a.completed_at) AS last_attempt
         FROM users u
         INNER JOIN attempts a ON a.user_id = u.id
         WHERE u.is_active = 1
         GROUP BY u.id, u.name, u.avatar
         ORDER BY total_score DESC
         LIMIT ? OFFSET ?",
        [$limit, $offset]
    );

    // Add rank
    foreach ($rows as $i => &$r) {
        $r['rank'] = $offset + $i + 1;
    }
    unset($r);

    jsonPaginated($rows, (int)$total, $page, $limit);
}

function leaderboard_quiz(): void {
    $quizId = (int)($_GET['id'] ?? 0);
    if (!$quizId) jsonError('Quiz ID diperlukan');

    $quiz = DB::one('SELECT id, title FROM quizzes WHERE id = ? AND is_published = 1', [$quizId]);
    if (!$quiz) jsonError('Quiz tidak ditemukan', 404);

    $rows = DB::all(
        "SELECT
            u.id, u.name, u.avatar,
            MAX(a.score) AS best_score,
            COUNT(a.id) AS attempts,
            MIN(a.time_taken) AS best_time,
            MAX(a.completed_at) AS last_attempt
         FROM attempts a
         INNER JOIN users u ON u.id = a.user_id
         WHERE a.quiz_id = ? AND u.is_active = 1
         GROUP BY u.id, u.name, u.avatar
         ORDER BY best_score DESC, best_time ASC
         LIMIT 50",
        [$quizId]
    );

    foreach ($rows as $i => &$r) {
        $r['rank'] = $i + 1;
    }
    unset($r);

    jsonSuccess(['quiz' => $quiz, 'leaderboard' => $rows]);
}

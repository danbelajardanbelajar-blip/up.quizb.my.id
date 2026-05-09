<?php
// ============================================
// api/activity.php — Public Activity Feed
// ============================================

// -------------------------------------------------------
// Helper: hitung persen & badge, cast tipe
// -------------------------------------------------------
function _activityEnrich(array &$rows): void {
    foreach ($rows as &$row) {
        $row['score']           = (int)$row['score'];
        $row['total_questions'] = (int)$row['total_questions'];
        $row['correct_answers'] = (int)$row['correct_answers'];
        $row['time_taken']      = (int)$row['time_taken'];
        $row['user_id']         = (int)$row['user_id'];
        $row['quiz_id']         = (int)$row['quiz_id'];
        $row['passed']          = isset($row['passed']) ? (bool)$row['passed'] : null;

        $pct = $row['total_questions'] > 0
            ? round($row['correct_answers'] / $row['total_questions'] * 100)
            : 0;
        $row['percent'] = $pct;
        $row['badge']   = $pct >= 90 ? 'perfect'
                        : ($pct >= 70 ? 'good'
                        : ($pct >= 50 ? 'ok' : 'low'));
    }
    unset($row);
}

// -------------------------------------------------------
// Base SELECT fragment (dipakai oleh semua endpoint publik)
// -------------------------------------------------------
function _activitySelect(): string {
    return "SELECT
            a.id,
            u.id                                 AS user_id,
            u.name                               AS user_name,
            q.id                                 AS quiz_id,
            q.title                              AS quiz_title,
            q.total_questions                    AS total_questions,
            q.passing_score                      AS passing_score,
            c.name                               AS category_name,
            c.icon                               AS category_icon,
            a.score,
            a.correct_count                      AS correct_answers,
            a.time_taken,
            a.mode,
            a.completed_at,
            IF(a.score >= q.passing_score, 1, 0) AS passed
        FROM attempts a
        INNER JOIN users      u ON u.id = a.user_id
        INNER JOIN quizzes    q ON q.id = a.quiz_id
        LEFT  JOIN categories c ON c.id = q.category_id
        WHERE q.is_published = 1
          AND u.is_active = 1";
}

/**
 * GET ?action=activity.feed
 * Feed aktivitas publik terbaru. Dapat diakses semua role termasuk tamu.
 */
function activity_feed(): void {
    [$page, $limit, $offset] = getPaginationParams();
    $limit = min(30, max(5, $limit));

    $total = (int)DB::one("
        SELECT COUNT(*) AS cnt
        FROM attempts a
        INNER JOIN quizzes q ON q.id = a.quiz_id
        INNER JOIN users   u ON u.id = a.user_id
        WHERE q.is_published = 1
          AND u.is_active = 1
    ")['cnt'];

    $rows = DB::all(
        _activitySelect() . "
        ORDER BY a.completed_at DESC
        LIMIT ? OFFSET ?",
        [$limit, $offset]
    );

    _activityEnrich($rows);
    jsonPaginated($rows, $total, $page, $limit);
}

/**
 * GET ?action=activity.user_history&user_id=X
 * Semua percobaan publik dari satu user tertentu.
 */
function activity_user_history(): void {
    $userId = (int)($_GET['user_id'] ?? 0);
    if (!$userId) jsonError('user_id diperlukan');

    $user = DB::one("SELECT id, name FROM users WHERE id = ? AND is_active = 1", [$userId]);
    if (!$user) jsonError('User tidak ditemukan', 404);

    [$page, $limit, $offset] = getPaginationParams();
    $limit = min(30, max(5, $limit));

    $total = (int)DB::one(
        "SELECT COUNT(*) AS cnt
         FROM attempts a
         INNER JOIN quizzes q ON q.id = a.quiz_id
         WHERE a.user_id = ? AND q.is_published = 1",
        [$userId]
    )['cnt'];

    $rows = DB::all(
        _activitySelect() . " AND a.user_id = ?
        ORDER BY a.completed_at DESC
        LIMIT ? OFFSET ?",
        [$userId, $limit, $offset]
    );

    _activityEnrich($rows);

    jsonSuccess([
        'filter' => ['type' => 'user', 'id' => (int)$user['id'], 'label' => $user['name']],
        'data'   => $rows,
        'meta'   => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int)ceil($total / max(1, $limit)),
        ],
    ]);
}

/**
 * GET ?action=activity.quiz_history&quiz_id=X
 * Semua percobaan publik pada satu quiz tertentu.
 */
function activity_quiz_history(): void {
    $quizId = (int)($_GET['quiz_id'] ?? 0);
    if (!$quizId) jsonError('quiz_id diperlukan');

    $quiz = DB::one("SELECT id, title FROM quizzes WHERE id = ? AND is_published = 1", [$quizId]);
    if (!$quiz) jsonError('Quiz tidak ditemukan', 404);

    [$page, $limit, $offset] = getPaginationParams();
    $limit = min(30, max(5, $limit));

    $total = (int)DB::one(
        "SELECT COUNT(*) AS cnt
         FROM attempts a
         INNER JOIN users u ON u.id = a.user_id
         WHERE a.quiz_id = ? AND u.is_active = 1",
        [$quizId]
    )['cnt'];

    $rows = DB::all(
        _activitySelect() . " AND a.quiz_id = ?
        ORDER BY a.completed_at DESC
        LIMIT ? OFFSET ?",
        [$quizId, $limit, $offset]
    );

    _activityEnrich($rows);

    jsonSuccess([
        'filter' => ['type' => 'quiz', 'id' => (int)$quiz['id'], 'label' => $quiz['title']],
        'data'   => $rows,
        'meta'   => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int)ceil($total / max(1, $limit)),
        ],
    ]);
}

/**
 * GET ?action=activity.mode_history&mode=X
 * Semua percobaan publik pada satu mode tertentu.
 */
function activity_mode_history(): void {
    $allowed = ['exam', 'instant', 'end', 'challenge'];
    $mode    = sanitizeString($_GET['mode'] ?? '');
    if (!in_array($mode, $allowed, true)) jsonError('Mode tidak valid');

    [$page, $limit, $offset] = getPaginationParams();
    $limit = min(30, max(5, $limit));

    $total = (int)DB::one(
        "SELECT COUNT(*) AS cnt
         FROM attempts a
         INNER JOIN quizzes q ON q.id = a.quiz_id
         INNER JOIN users   u ON u.id = a.user_id
         WHERE a.mode = ? AND q.is_published = 1 AND u.is_active = 1",
        [$mode]
    )['cnt'];

    $rows = DB::all(
        _activitySelect() . " AND a.mode = ?
        ORDER BY a.completed_at DESC
        LIMIT ? OFFSET ?",
        [$mode, $limit, $offset]
    );

    _activityEnrich($rows);

    $modeLabels = ['exam' => 'Ujian', 'instant' => 'Instan', 'end' => 'Akhir', 'challenge' => 'Tantangan'];

    jsonSuccess([
        'filter' => ['type' => 'mode', 'id' => $mode, 'label' => $modeLabels[$mode] ?? $mode],
        'data'   => $rows,
        'meta'   => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int)ceil($total / max(1, $limit)),
        ],
    ]);
}

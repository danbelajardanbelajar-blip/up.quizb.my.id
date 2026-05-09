<?php
// ============================================
// api/activity.php — Public Activity Feed
// ============================================

/**
 * GET ?action=activity.feed
 * Menampilkan aktivitas publik terbaru: quiz selesai, tantangan, dsb.
 * Dapat diakses oleh semua role (termasuk tamu).
 */
function activity_feed(): void {
    [$page, $limit, $offset] = getPaginationParams();
    $limit = min(30, max(5, $limit));

    // Total aktivitas
    $total = (int)DB::one("
        SELECT COUNT(*) AS cnt
        FROM attempts a
        INNER JOIN quizzes q ON q.id = a.quiz_id
        INNER JOIN users   u ON u.id = a.user_id
        WHERE q.is_published = 1
          AND u.is_active = 1
    ")['cnt'];

    // Feed: attempt selesai beserta info user & quiz
    // Kolom yang benar: correct_count (bukan correct_answers), total_questions dari quizzes
    $rows = DB::all("
        SELECT
            a.id,
            'quiz'              AS type,
            u.id                AS user_id,
            u.name              AS user_name,
            q.id                AS quiz_id,
            q.title             AS quiz_title,
            q.total_questions   AS total_questions,
            c.name              AS category_name,
            c.icon              AS category_icon,
            a.score,
            a.correct_count     AS correct_answers,
            a.time_taken,
            a.mode,
            a.completed_at
        FROM attempts a
        INNER JOIN users      u ON u.id  = a.user_id
        INNER JOIN quizzes    q ON q.id  = a.quiz_id
        LEFT  JOIN categories c ON c.id  = q.category_id
        WHERE q.is_published = 1
          AND u.is_active = 1
        ORDER BY a.completed_at DESC
        LIMIT ? OFFSET ?
    ", [$limit, $offset]);

    // Hitung persentase & label badge
    foreach ($rows as &$row) {
        $row['score']           = (int)$row['score'];
        $row['total_questions'] = (int)$row['total_questions'];
        $row['correct_answers'] = (int)$row['correct_answers'];
        $row['time_taken']      = (int)$row['time_taken'];
        $row['user_id']         = (int)$row['user_id'];
        $row['quiz_id']         = (int)$row['quiz_id'];

        $pct = $row['total_questions'] > 0
            ? round($row['correct_answers'] / $row['total_questions'] * 100)
            : 0;
        $row['percent'] = $pct;
        $row['badge']   = $pct >= 90 ? 'perfect'
                        : ($pct >= 70 ? 'good'
                        : ($pct >= 50 ? 'ok' : 'low'));
    }
    unset($row);

    jsonPaginated($rows, $total, $page, $limit);
}

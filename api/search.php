<?php
// ============================================
// api/search.php — Realtime Search
// ============================================

function search_index(): void {
    $q = sanitizeString($_GET['q'] ?? '');

    if (strlen($q) < 2) {
        jsonSuccess([]);
    }

    $term = '%' . $q . '%';

    $quizzes = DB::all(
        "SELECT q.id, q.title, q.slug, q.difficulty, q.total_questions,
                q.total_questions AS question_count,
                c.name AS category_name, c.icon AS category_icon, c.color AS category_color
         FROM quizzes q
         INNER JOIN categories c ON c.id = q.category_id
         WHERE q.is_published = 1 AND (q.title LIKE ? OR q.description LIKE ? OR c.name LIKE ?)
         ORDER BY q.total_attempts DESC
         LIMIT 8",
        [$term, $term, $term]
    );

    $categories = DB::all(
        "SELECT id, name, slug, icon, color, quiz_count
         FROM categories
         WHERE name LIKE ? OR description LIKE ?
         LIMIT 4",
        [$term, $term]
    );

    jsonSuccess([
        'quizzes'    => $quizzes,
        'categories' => $categories,
        'query'      => $q,
    ]);
}

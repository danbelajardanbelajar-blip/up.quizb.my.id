<?php
// ============================================
// api/quiz.php — Quiz Endpoints
// ============================================

function quiz_list(): void {
    [$page, $limit, $offset] = getPaginationParams();
    $category = (int)($_GET['category'] ?? 0);
    $diff     = sanitizeString($_GET['difficulty'] ?? '');
    $search   = sanitizeString($_GET['search'] ?? '');

    $where  = ['q.is_published = 1'];
    $params = [];

    if ($category > 0) {
        $where[]  = 'q.category_id = ?';
        $params[] = $category;
    }
    if (in_array($diff, ['easy','medium','hard'])) {
        $where[]  = 'q.difficulty = ?';
        $params[] = $diff;
    }
    if ($search !== '') {
        $where[]  = '(q.title LIKE ? OR q.description LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }

    $whereStr = 'WHERE ' . implode(' AND ', $where);

    $total = DB::one(
        "SELECT COUNT(*) AS cnt FROM quizzes q $whereStr",
        $params
    )['cnt'];

    $quizzes = DB::all(
        "SELECT q.id, q.title, q.slug, q.description, q.time_limit, q.duration, q.difficulty,
                q.total_questions, q.total_questions AS question_count,
                q.total_attempts, q.total_attempts AS attempt_count,
                c.name AS category_name, c.icon AS category_icon, c.color AS category_color
         FROM quizzes q
         INNER JOIN categories c ON c.id = q.category_id
         $whereStr
         ORDER BY q.total_attempts DESC, q.id DESC
         LIMIT ? OFFSET ?",
        array_merge($params, [$limit, $offset])
    );

    jsonPaginated($quizzes, (int)$total, $page, $limit);
}

function quiz_get(): void {
    $id   = (int)($_GET['id']   ?? 0);
    $slug = sanitizeString($_GET['slug'] ?? '');

    $baseSelect = "SELECT q.*,
                q.total_questions AS question_count,
                q.total_attempts  AS attempt_count,
                c.name AS category_name, c.icon AS category_icon, c.color AS category_color
         FROM quizzes q INNER JOIN categories c ON c.id = q.category_id";

    if ($id > 0) {
        $quiz = DB::one("$baseSelect WHERE q.id = ? AND q.is_published = 1", [$id]);
    } elseif ($slug) {
        $quiz = DB::one("$baseSelect WHERE q.slug = ? AND q.is_published = 1", [$slug]);
    } else {
        jsonError('ID atau slug diperlukan');
    }

    if (!$quiz) jsonError('Quiz tidak ditemukan', 404);
    jsonSuccess($quiz);
}

function quiz_questions(): void {
    $quizId = (int)($_GET['id'] ?? 0);
    if (!$quizId) jsonError('Quiz ID diperlukan');

    $quiz = DB::one(
        'SELECT id, title, description, duration, time_limit, total_questions, passing_score
         FROM quizzes WHERE id = ? AND is_published = 1',
        [$quizId]
    );
    if (!$quiz) jsonError('Quiz tidak ditemukan', 404);

    $questions = DB::all(
        'SELECT id, question_text, type, points, order_num
         FROM questions WHERE quiz_id = ? ORDER BY order_num',
        [$quizId]
    );

    $labels = ['A','B','C','D','E','F'];
    foreach ($questions as &$q) {
        $opts = DB::all(
            'SELECT id, option_text, order_num FROM options WHERE question_id = ? ORDER BY order_num',
            [$q['id']]
        );
        // Tambahkan label A/B/C/D/E per opsi
        foreach ($opts as $i => &$opt) {
            $opt['label'] = $labels[$i] ?? chr(65 + $i);
        }
        unset($opt);
        $q['options'] = $opts;
    }
    unset($q);

    jsonSuccess(['quiz' => $quiz, 'questions' => $questions]);
}

function quiz_featured(): void {
    $quizzes = DB::all(
        "SELECT q.id, q.title, q.slug, q.description, q.time_limit, q.duration, q.difficulty,
                q.total_questions, q.total_questions AS question_count,
                q.total_attempts, q.total_attempts AS attempt_count,
                c.name AS category_name, c.icon AS category_icon, c.color AS category_color
         FROM quizzes q
         INNER JOIN categories c ON c.id = q.category_id
         WHERE q.is_published = 1
         ORDER BY q.total_attempts DESC
         LIMIT 6"
    );
    jsonSuccess($quizzes);
}

function quiz_stats(): void {
    $totalQuestions  = (int) DB::one("SELECT COUNT(*) AS n FROM questions")['n'];
    $totalQuizzes    = (int) DB::one("SELECT COUNT(*) AS n FROM quizzes WHERE is_published = 1")['n'];
    $totalCategories = (int) DB::one("SELECT COUNT(*) AS n FROM categories WHERE quiz_count > 0")['n'];
    $totalUsers      = (int) DB::one("SELECT COUNT(*) AS n FROM users WHERE is_active = 1")['n'];

    jsonSuccess([
        'total_questions'  => $totalQuestions,
        'total_quizzes'    => $totalQuizzes,
        'total_categories' => $totalCategories,
        'total_users'      => $totalUsers,
    ]);
}

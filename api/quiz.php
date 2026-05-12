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
    $quizId       = (int)($_GET['id']            ?? 0);
    $assignmentId = (int)($_GET['assignment_id'] ?? 0);
    if (!$quizId) jsonError('Quiz ID diperlukan');

    $quiz = DB::one(
        'SELECT id, title, description, duration, time_limit, total_questions, passing_score
         FROM quizzes WHERE id = ? AND is_published = 1',
        [$quizId]
    );
    if (!$quiz) jsonError('Quiz tidak ditemukan', 404);

    // ---- Ambil pengaturan dari assignment atau user ----
    // Prioritas: 1) assignment  2) user setting  3) default
    $limit           = null;
    $shuffleQuestions = null;   // null = belum ditentukan
    $shuffleOptions   = null;

    if ($assignmentId > 0) {
        $assignment = DB::one(
            'SELECT max_questions, shuffle_questions, shuffle_options, timer_per_question, duration_minutes
             FROM assignments WHERE id = ? AND quiz_id = ? AND is_active = 1',
            [$assignmentId, $quizId]
        );
        if ($assignment) {
            if ($assignment['max_questions'] !== null)    $limit            = (int)$assignment['max_questions'];
            if ($assignment['shuffle_questions'] !== null) $shuffleQuestions = (bool)(int)$assignment['shuffle_questions'];
            if ($assignment['shuffle_options']   !== null) $shuffleOptions   = (bool)(int)$assignment['shuffle_options'];

            // Expose timer_per_question and exam_duration (seconds) to client when playing from assignment
            $quiz['timer_per_question'] = $assignment['timer_per_question'] !== null ? (int)$assignment['timer_per_question'] : null;
            $quiz['exam_duration'] = $assignment['duration_minutes'] !== null ? ((int)$assignment['duration_minutes'] * 60) : null;
        }
    }

    // Isi dari user jika masih null
    $currentUser = getCurrentUser();
    if ($currentUser) {
        $userRow = DB::one(
            'SELECT quiz_questions_limit, shuffle_questions, shuffle_options FROM users WHERE id = ?',
            [$currentUser['id']]
        );
        if ($limit           === null) $limit           = (int)($userRow['quiz_questions_limit'] ?? 10);
        if ($shuffleQuestions === null) $shuffleQuestions = (bool)(int)($userRow['shuffle_questions'] ?? 1);
        if ($shuffleOptions   === null) $shuffleOptions   = (bool)(int)($userRow['shuffle_options']   ?? 1);
    } else {
        // Tamu: pakai default
        if ($limit           === null) $limit           = 10;
        if ($shuffleQuestions === null) $shuffleQuestions = true;
        if ($shuffleOptions   === null) $shuffleOptions   = true;
    }

    if ($limit < 1) $limit = 10;

    // ---- Ambil semua soal ----
    $allQuestions = DB::all(
        'SELECT id, question_text, type, points, order_num
         FROM questions WHERE quiz_id = ? ORDER BY order_num',
        [$quizId]
    );

    // ---- Acak urutan soal (jika aktif) lalu potong sesuai limit ----
    if ($shuffleQuestions) {
        shuffle($allQuestions);
    }
    if (count($allQuestions) > $limit) {
        $allQuestions = array_slice($allQuestions, 0, $limit);
    }
    // Jika tidak diacak, kembalikan ke urutan order_num asli
    if (!$shuffleQuestions) {
        usort($allQuestions, fn($a, $b) => (int)$a['order_num'] - (int)$b['order_num']);
    }

    // ---- Muat opsi untuk setiap soal ----
    $labels = ['A','B','C','D','E','F'];
    foreach ($allQuestions as &$q) {
        $q['question_text'] = html_entity_decode($q['question_text'], ENT_QUOTES, 'UTF-8');
        $opts = DB::all(
            'SELECT id, option_text, order_num FROM options WHERE question_id = ? ORDER BY order_num',
            [$q['id']]
        );

        // Acak urutan pilihan jawaban (jika aktif)
        if ($shuffleOptions) {
            shuffle($opts);
        }

        // Pasang label A/B/C/D/... setelah (mungkin) diacak
        foreach ($opts as $i => &$opt) {
            $opt['label'] = $labels[$i] ?? chr(65 + $i);
            $opt['option_text'] = html_entity_decode($opt['option_text'], ENT_QUOTES, 'UTF-8');
        }
        unset($opt);
        $q['options'] = $opts;

        // correct_option_id tetap statis (tidak berubah oleh shuffle)
        $correctOpt = DB::one(
            'SELECT id FROM options WHERE question_id = ? AND is_correct = 1 LIMIT 1',
            [$q['id']]
        );
        $q['correct_option_id'] = $correctOpt ? $correctOpt['id'] : null;
    }
    unset($q);

    jsonSuccess(['quiz' => $quiz, 'questions' => $allQuestions]);
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

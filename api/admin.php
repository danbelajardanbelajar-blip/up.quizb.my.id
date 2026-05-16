<?php
// ============================================
// api/admin.php — Admin CRUD Endpoints
// ============================================

function admin_fix_correct_answers(): void {
    requireAdmin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);

    $config = include __DIR__ . '/../config/db.php';
    try {
        $pdo = new PDO('mysql:host='.$config['host'].';dbname='.$config['database'], $config['username'], $config['password']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Cari questions yang tidak punya correct option
        $stmt = $pdo->prepare("
            SELECT q.id as question_id, q.quiz_id, COUNT(o.id) as option_count
            FROM questions q
            LEFT JOIN options o ON q.id = o.question_id
            WHERE NOT EXISTS (
                SELECT 1 FROM options o2 WHERE o2.question_id = q.id AND o2.is_correct = 1
            )
            GROUP BY q.id, q.quiz_id
            HAVING option_count > 0
        ");
        $stmt->execute();
        $questionsWithoutCorrect = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $fixed = 0;
        foreach ($questionsWithoutCorrect as $q) {
            // Ambil semua opsi untuk question ini
            $stmt = $pdo->prepare("SELECT id, option_text FROM options WHERE question_id = ? ORDER BY order_num");
            $stmt->execute([$q['question_id']]);
            $options = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($options) > 0) {
                // Pilih opsi pertama sebagai correct
                $correctOptionId = $options[0]['id'];

                // Set sebagai correct
                $stmt = $pdo->prepare("UPDATE options SET is_correct = 1 WHERE id = ?");
                $stmt->execute([$correctOptionId]);

                $fixed++;
            }
        }

        // Verifikasi perbaikan
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT q.id) as total_questions,
                   COUNT(DISTINCT CASE WHEN EXISTS(SELECT 1 FROM options o WHERE o.question_id = q.id AND o.is_correct = 1) THEN q.id END) as questions_with_correct
            FROM questions q
        ");
        $stmt->execute();
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        jsonSuccess([
            'message' => 'Correct answers fixed successfully',
            'fixed_questions' => $fixed,
            'total_questions' => (int)$stats['total_questions'],
            'questions_with_correct' => (int)$stats['questions_with_correct'],
            'percentage' => $stats['total_questions'] > 0 ? round(($stats['questions_with_correct'] / $stats['total_questions']) * 100, 1) : 0
        ]);

    } catch (Exception $e) {
        jsonError('Database error: ' . $e->getMessage(), 500);
    }
}

// ---- Quiz CRUD (Admin) ----

function admin_quiz_list(): void {
    requireAdmin();
    [$page, $limit, $offset] = getPaginationParams();

    $search = trim($_GET['search'] ?? '');
    $where  = ''; $params = [];
    if ($search !== '') {
        $where    = "WHERE (q.title LIKE ? OR c.name LIKE ?)";
        $like     = '%' . $search . '%';
        $params   = [$like, $like];
    }

    $total = (int)(DB::one(
        "SELECT COUNT(*) AS cnt
         FROM quizzes q LEFT JOIN categories c ON q.category_id = c.id $where",
        $params
    )['cnt'] ?? 0);

    $quizzes = DB::all(
        "SELECT q.*, c.name AS category_name,
                u.name AS creator_name,
                (SELECT COUNT(*) FROM questions WHERE quiz_id = q.id) AS question_count,
                (SELECT COUNT(*) FROM attempts WHERE quiz_id = q.id) AS attempt_count
         FROM quizzes q
         LEFT JOIN categories c ON q.category_id = c.id
         LEFT JOIN users u ON q.created_by = u.id
         $where
         ORDER BY q.created_at DESC
         LIMIT ? OFFSET ?",
        [...$params, $limit, $offset]
    );

    jsonSuccess([
        'quizzes' => $quizzes,
        'total'   => (int)$total,
        'page'    => $page,
        'limit'   => $limit
    ]);
}

function admin_quiz_create(): void {
    requireAdmin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);

    $body = getJsonBody();
    $title       = sanitizeString($body['title'] ?? '');
    $description = sanitizeString($body['description'] ?? '');
    $categoryId  = (int)($body['category_id'] ?? 0);
    $difficulty  = sanitizeString($body['difficulty'] ?? 'medium');
    $timeLimit   = (int)($body['time_limit'] ?? 600);
    $passingScore= (int)($body['passing_score'] ?? 60);
    $isPublished = (int)($body['is_published'] ?? 0);
    $maxAttempts = (int)($body['max_attempts'] ?? 0);

    if (strlen($title) < 3) jsonError('Judul minimal 3 karakter');
    if (!in_array($difficulty, ['easy','medium','hard'])) jsonError('Difficulty tidak valid');
    if ($categoryId <= 0) jsonError('Pilih kategori');

    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($title));
    $slug = trim($slug, '-');
    // Pastikan slug unik
    $slugBase  = $slug;
    $slugCount = 1;
    while (DB::one("SELECT id FROM quizzes WHERE slug = ?", [$slug])) {
        $slug = $slugBase . '-' . $slugCount++;
    }

    $pdo = DB::conn();
    $stmt = $pdo->prepare(
        "INSERT INTO quizzes (title, slug, description, category_id, difficulty, time_limit,
                              passing_score, is_published, max_attempts, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $title, $slug, $description, $categoryId, $difficulty, $timeLimit,
        $passingScore, $isPublished, $maxAttempts, $_SESSION['user_id']
    ]);
    $newId = $pdo->lastInsertId();

    // Update category quiz_count
    $pdo->prepare("UPDATE categories SET quiz_count = quiz_count + 1 WHERE id = ?")->execute([$categoryId]);

    $quiz = DB::one("SELECT q.*, c.name AS category_name FROM quizzes q LEFT JOIN categories c ON q.category_id = c.id WHERE q.id = ?", [$newId]);
    http_response_code(201);
    jsonSuccess($quiz, 'Quiz berhasil dibuat');
}

function admin_quiz_update(): void {
    requireAdmin();
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') jsonError('Method not allowed', 405);

    $id   = (int)($_GET['id'] ?? 0);
    $body = getJsonBody();
    if ($id <= 0) jsonError('ID tidak valid');

    $existing = DB::one("SELECT * FROM quizzes WHERE id = ?", [$id]);
    if (!$existing) jsonError('Quiz tidak ditemukan', 404);

    $title       = sanitizeString($body['title'] ?? $existing['title']);
    $description = sanitizeString($body['description'] ?? $existing['description']);
    $categoryId  = (int)($body['category_id'] ?? $existing['category_id']);
    $difficulty  = sanitizeString($body['difficulty'] ?? $existing['difficulty']);
    $timeLimit   = (int)($body['time_limit'] ?? $existing['time_limit']);
    $passingScore= (int)($body['passing_score'] ?? $existing['passing_score']);
    $isPublished = isset($body['is_published']) ? (int)$body['is_published'] : (int)$existing['is_published'];
    $maxAttempts = (int)($body['max_attempts'] ?? $existing['max_attempts']);

    DB::conn()->prepare(
        "UPDATE quizzes SET title=?, description=?, category_id=?, difficulty=?,
                            time_limit=?, passing_score=?, is_published=?, max_attempts=?,
                            updated_at=NOW()
         WHERE id=?"
    )->execute([$title, $description, $categoryId, $difficulty, $timeLimit, $passingScore, $isPublished, $maxAttempts, $id]);

    // Update category counts if category changed
    if ($categoryId !== (int)$existing['category_id']) {
        $pdo = DB::conn();
        $pdo->prepare("UPDATE categories SET quiz_count = GREATEST(0, quiz_count - 1) WHERE id = ?")->execute([$existing['category_id']]);
        $pdo->prepare("UPDATE categories SET quiz_count = quiz_count + 1 WHERE id = ?")->execute([$categoryId]);
    }

    $quiz = DB::one("SELECT q.*, c.name AS category_name FROM quizzes q LEFT JOIN categories c ON q.category_id = c.id WHERE q.id = ?", [$id]);
    jsonSuccess($quiz);
}

function admin_quiz_delete(): void {
    requireAdmin();
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') jsonError('Method not allowed', 405);

    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) jsonError('ID tidak valid');

    $quiz = DB::one("SELECT * FROM quizzes WHERE id = ?", [$id]);
    if (!$quiz) jsonError('Quiz tidak ditemukan', 404);

    // Cascade: answers → attempts → options → questions → quiz
    $pdo = DB::conn();
    $pdo->prepare("DELETE aa FROM attempt_answers aa JOIN attempts a ON aa.attempt_id = a.id WHERE a.quiz_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM attempts WHERE quiz_id = ?")->execute([$id]);
    $pdo->prepare("DELETE op FROM options op JOIN questions q ON op.question_id = q.id WHERE q.quiz_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM questions WHERE quiz_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM quizzes WHERE id = ?")->execute([$id]);
    $pdo->prepare("UPDATE categories SET quiz_count = GREATEST(0, quiz_count - 1) WHERE id = ?")->execute([$quiz['category_id']]);

    jsonSuccess(['message' => 'Quiz berhasil dihapus']);
}

// ---- Category CRUD (Admin) ----

function admin_category_list(): void {
    requireAdmin();
    $cats = DB::all(
        "SELECT id, name, slug, description, icon, color, quiz_count,
                COALESCE(group_id, 0) AS group_id
         FROM categories ORDER BY name ASC"
    );
    foreach ($cats as &$c) {
        $c['id']         = (int)$c['id'];
        $c['quiz_count'] = (int)$c['quiz_count'];
        $c['group_id']   = (int)$c['group_id'];
    }
    unset($c);
    jsonSuccess($cats);
}

function admin_category_create(): void {
    requireAdmin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    $body = getJsonBody();

    $name    = sanitizeString($body['name'] ?? '');
    $slug    = sanitizeString($body['slug'] ?? preg_replace('/[^a-z0-9]+/', '-', strtolower($name)));
    $desc    = sanitizeString($body['description'] ?? '');
    $icon    = sanitizeString($body['icon'] ?? '📚');
    $color   = sanitizeString($body['color'] ?? '#6366f1');
    $groupId = isset($body['group_id']) && (int)$body['group_id'] > 0
               ? (int)$body['group_id'] : null;

    if (strlen($name) < 2) jsonError('Nama minimal 2 karakter');

    // Pastikan slug unik
    $slugBase  = $slug;
    $slugCount = 1;
    while (DB::one("SELECT id FROM categories WHERE slug = ?", [$slug])) {
        $slug = $slugBase . '-' . $slugCount++;
    }

    $pdo = DB::conn();
    $pdo->prepare("INSERT INTO categories (name, slug, description, icon, color, group_id) VALUES (?,?,?,?,?,?)")
        ->execute([$name, $slug, $desc, $icon, $color, $groupId]);
    $newId = $pdo->lastInsertId();

    $cat = DB::one(
        "SELECT id, name, slug, description, icon, color, quiz_count, COALESCE(group_id,0) AS group_id
         FROM categories WHERE id = ?", [$newId]
    );
    $cat['id']         = (int)$cat['id'];
    $cat['quiz_count'] = (int)$cat['quiz_count'];
    $cat['group_id']   = (int)$cat['group_id'];
    jsonSuccess($cat, 201);
}

function admin_category_update(): void {
    requireAdmin();
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') jsonError('Method not allowed', 405);
    $id   = (int)($_GET['id'] ?? 0);
    $body = getJsonBody();
    if ($id <= 0) jsonError('ID tidak valid');

    $existing = DB::one("SELECT * FROM categories WHERE id = ?", [$id]);
    if (!$existing) jsonError('Kategori tidak ditemukan', 404);

    $name    = sanitizeString($body['name']  ?? $existing['name']);
    $slug    = sanitizeString($body['slug']  ?? $existing['slug']);
    $desc    = sanitizeString($body['description'] ?? $existing['description']);
    $icon    = sanitizeString($body['icon']  ?? $existing['icon']);
    $color   = sanitizeString($body['color'] ?? $existing['color']);

    // group_id: null = tanpa rumpun, 0 atau '' = tanpa rumpun, angka positif = assign ke rumpun
    if (array_key_exists('group_id', $body)) {
        $groupId = (int)$body['group_id'] > 0 ? (int)$body['group_id'] : null;
    } else {
        $groupId = isset($existing['group_id']) && (int)$existing['group_id'] > 0
                   ? (int)$existing['group_id'] : null;
    }

    DB::conn()->prepare(
        "UPDATE categories SET name=?, slug=?, description=?, icon=?, color=?, group_id=? WHERE id=?"
    )->execute([$name, $slug, $desc, $icon, $color, $groupId, $id]);

    $cat = DB::one(
        "SELECT id, name, slug, description, icon, color, quiz_count, COALESCE(group_id,0) AS group_id
         FROM categories WHERE id = ?", [$id]
    );
    $cat['id']         = (int)$cat['id'];
    $cat['quiz_count'] = (int)$cat['quiz_count'];
    $cat['group_id']   = (int)$cat['group_id'];
    jsonSuccess($cat);
}

function admin_category_delete(): void {
    requireAdmin();
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') jsonError('Method not allowed', 405);
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) jsonError('ID tidak valid');

    $quizCount = DB::one("SELECT COUNT(*) AS c FROM quizzes WHERE category_id = ?", [$id])['c'];
    if ($quizCount > 0) jsonError('Kategori masih memiliki quiz, hapus quiz dulu');

    DB::conn()->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
    jsonSuccess(['message' => 'Kategori berhasil dihapus']);
}

// ---- User Management (Admin) ----

function admin_user_list(): void {
    requireAdmin();
    [$page, $limit, $offset] = getPaginationParams();

    $search = trim($_GET['search'] ?? '');
    $where  = ''; $params = [];
    if ($search !== '') {
        $where  = "WHERE (name LIKE ? OR email LIKE ?)";
        $like   = '%' . $search . '%';
        $params = [$like, $like];
    }

    $total = (int)(DB::one("SELECT COUNT(*) AS cnt FROM users $where", $params)['cnt'] ?? 0);
    $users = DB::all(
        "SELECT id, name, email, role, total_points, quizzes_taken, is_active, created_at
         FROM users $where ORDER BY created_at DESC LIMIT ? OFFSET ?",
        [...$params, $limit, $offset]
    );
    jsonSuccess(['users' => $users, 'total' => (int)$total, 'page' => $page, 'limit' => $limit]);
}

function admin_user_update(): void {
    requireAdmin();
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') jsonError('Method not allowed', 405);
    $id   = (int)($_GET['id'] ?? 0);
    $body = getJsonBody();
    if ($id <= 0) jsonError('ID tidak valid');

    $existing = DB::one("SELECT * FROM users WHERE id = ?", [$id]);
    if (!$existing) jsonError('User tidak ditemukan', 404);

    // Prevent demoting yourself
    if ($id === (int)$_SESSION['user_id'] && isset($body['role']) && $body['role'] !== 'admin') {
        jsonError('Tidak bisa mengubah role diri sendiri');
    }

    $role     = in_array($body['role'] ?? $existing['role'], ['user','admin','pengajar','pelajar'])
                 ? ($body['role'] ?? $existing['role']) : $existing['role'];
    $isActive = isset($body['is_active']) ? (int)$body['is_active'] : (int)$existing['is_active'];
    $name     = sanitizeString($body['name'] ?? $existing['name']);

    DB::conn()->prepare("UPDATE users SET name=?, role=?, is_active=? WHERE id=?")
        ->execute([$name, $role, $isActive, $id]);
    jsonSuccess(['message' => 'User berhasil diperbarui']);
}

function admin_user_delete(): void {
    requireAdmin();
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') jsonError('Method not allowed', 405);
    $id = (int)($_GET['id'] ?? 0);

    if ($id === (int)$_SESSION['user_id']) jsonError('Tidak bisa menghapus akun sendiri');

    $pdo = DB::conn();
    $pdo->prepare("DELETE aa FROM attempt_answers aa JOIN attempts a ON aa.attempt_id = a.id WHERE a.user_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM attempts WHERE user_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
    jsonSuccess(['message' => 'User berhasil dihapus']);
}

// ---- Platform Statistics ----

function admin_stats(): void {
    requireAdmin();
    $pdo = DB::conn();

    // Helper untuk SELECT COUNT(*) dengan alias konsisten
    $count = function (string $sql, array $params = []): int {
        try {
            $row = DB::one($sql, $params);
            return (int)($row['cnt'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    };

    $avgRow = DB::one("SELECT AVG(score) AS avg_score FROM attempts WHERE completed_at IS NOT NULL");

    $stats = [
        'total_users'       => $count("SELECT COUNT(*) AS cnt FROM users"),
        'total_quizzes'     => $count("SELECT COUNT(*) AS cnt FROM quizzes"),
        'total_attempts'    => $count("SELECT COUNT(*) AS cnt FROM attempts"),
        'total_questions'   => $count("SELECT COUNT(*) AS cnt FROM questions"),
        'total_categories'  => $count("SELECT COUNT(*) AS cnt FROM categories"),
        'total_classes'     => $count("SELECT COUNT(*) AS cnt FROM classes"),
        'total_assignments' => $count("SELECT COUNT(*) AS cnt FROM assignments"),
        'published_quizzes' => $count("SELECT COUNT(*) AS cnt FROM quizzes WHERE is_published=1"),
        'active_users'      => $count("SELECT COUNT(*) AS cnt FROM users WHERE is_active=1"),
        'pengajar_count'    => $count("SELECT COUNT(*) AS cnt FROM users WHERE role='pengajar'"),
        'pelajar_count'     => $count("SELECT COUNT(*) AS cnt FROM users WHERE role IN ('pelajar','user')"),
        'avg_score'         => round((float)($avgRow['avg_score'] ?? 0), 1),
        'recent_attempts'   => DB::all(
            "SELECT a.id, u.name AS user_name, q.title AS quiz_title, a.score, a.completed_at
             FROM attempts a
             JOIN users u ON a.user_id = u.id
             JOIN quizzes q ON a.quiz_id = q.id
             WHERE a.completed_at IS NOT NULL
             ORDER BY a.completed_at DESC LIMIT 10"
        ),
        'top_quizzes' => DB::all(
            "SELECT q.id, q.title, COUNT(a.id) AS attempt_count, AVG(a.score) AS avg_score
             FROM quizzes q LEFT JOIN attempts a ON q.id = a.quiz_id
             GROUP BY q.id ORDER BY attempt_count DESC LIMIT 5"
        ),
        'users_by_role' => DB::all(
            "SELECT role, COUNT(*) AS count FROM users GROUP BY role ORDER BY count DESC"
        ),
    ];

    jsonSuccess($stats);
}

// ============================================
// GROUP (RUMPUN) ENDPOINTS
// ============================================

function admin_group_list(): void {
    requireAdmin();
    $groups = DB::all(
        "SELECT g.id, g.name, g.icon, g.color, g.description, g.order_num,
                COUNT(c.id) AS category_count
         FROM category_groups g
         LEFT JOIN categories c ON c.group_id = g.id
         GROUP BY g.id
         ORDER BY g.order_num, g.id"
    );
    foreach ($groups as &$g) {
        $g['id']             = (int)$g['id'];
        $g['order_num']      = (int)$g['order_num'];
        $g['category_count'] = (int)$g['category_count'];
        $g['categories']     = DB::all(
            "SELECT id, name, slug, icon, color, quiz_count, group_id
             FROM categories WHERE group_id = ? ORDER BY name",
            [$g['id']]
        );
        foreach ($g['categories'] as &$c) {
            $c['id']         = (int)$c['id'];
            $c['quiz_count'] = (int)($c['quiz_count'] ?? 0);
            $c['group_id']   = (int)$c['group_id'];
        }
        unset($c);
    }
    unset($g);

    $allCategories = DB::all(
        "SELECT id, name, icon, color, COALESCE(group_id, 0) AS group_id
         FROM categories ORDER BY name"
    );
    foreach ($allCategories as &$c) {
        $c['id']       = (int)$c['id'];
        $c['group_id'] = (int)$c['group_id'];
    }
    unset($c);

    jsonSuccess(['groups' => $groups, 'all_categories' => $allCategories]);
}

function admin_group_create(): void {
    requireAdmin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    $body     = getJsonBody();
    $name     = sanitizeString($body['name']        ?? '');
    $icon     = sanitizeString($body['icon']        ?? '📚');
    $color    = sanitizeString($body['color']       ?? '#6366f1');
    $desc     = sanitizeString($body['description'] ?? '');
    $orderNum = (int)($body['order_num']            ?? 0);
    if (strlen($name) < 2) jsonError('Nama rumpun minimal 2 karakter');
    DB::execute(
        "INSERT INTO category_groups (name, icon, color, description, order_num) VALUES (?,?,?,?,?)",
        [$name, $icon, $color, $desc, $orderNum]
    );
    $id = (int)DB::lastId();
    jsonSuccess(DB::one("SELECT * FROM category_groups WHERE id = ?", [$id]), 201);
}

function admin_group_update(): void {
    requireAdmin();
    $id = (int)($_GET['id'] ?? 0);
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') jsonError('Method not allowed', 405);
    if ($id <= 0) jsonError('ID tidak valid');
    $body     = getJsonBody();
    $name     = sanitizeString($body['name']        ?? '');
    $icon     = sanitizeString($body['icon']        ?? '📚');
    $color    = sanitizeString($body['color']       ?? '#6366f1');
    $desc     = sanitizeString($body['description'] ?? '');
    $orderNum = (int)($body['order_num']            ?? 0);
    if (strlen($name) < 2) jsonError('Nama rumpun minimal 2 karakter');
    DB::execute(
        "UPDATE category_groups SET name=?, icon=?, color=?, description=?, order_num=? WHERE id=?",
        [$name, $icon, $color, $desc, $orderNum, $id]
    );
    jsonSuccess(DB::one("SELECT * FROM category_groups WHERE id = ?", [$id]));
}

function admin_group_delete(): void {
    requireAdmin();
    $id = (int)($_GET['id'] ?? 0);
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') jsonError('Method not allowed', 405);
    if ($id <= 0) jsonError('ID tidak valid');
    DB::execute("UPDATE categories SET group_id = NULL WHERE group_id = ?", [$id]);
    DB::execute("DELETE FROM category_groups WHERE id = ?", [$id]);
    jsonSuccess(['message' => 'Rumpun berhasil dihapus']);
}

function admin_group_assign(): void {
    requireAdmin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    $body        = getJsonBody();
    $groupId     = (int)($body['group_id']    ?? 0);
    $categoryIds = array_map('intval', $body['category_ids'] ?? []);
    if ($groupId <= 0) jsonError('group_id tidak valid');
    if (!DB::one("SELECT id FROM category_groups WHERE id = ?", [$groupId])) {
        jsonError('Rumpun tidak ditemukan', 404);
    }
    // Lepas semua kategori dari rumpun ini dulu
    DB::execute("UPDATE categories SET group_id = NULL WHERE group_id = ?", [$groupId]);
    // Assign yang baru
    if (!empty($categoryIds)) {
        $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
        DB::execute(
            "UPDATE categories SET group_id = ? WHERE id IN ($placeholders)",
            array_merge([$groupId], $categoryIds)
        );
    }
    jsonSuccess(['message' => 'Kategori berhasil diperbarui', 'assigned' => count($categoryIds)]);
}

// ============================================
// REVIEW SOAL — Admin & Pengajar
// ============================================

function admin_review_soal(): void {
    requirePengajar(); // admin + pengajar
    try {
        // Statistik per quiz
        $quizStats = DB::all(
            "SELECT
                q.id,
                q.title,
                q.difficulty,
                COUNT(DISTINCT a.id)          AS total_plays,
                COALESCE(SUM(a.correct_count), 0) AS total_correct,
                COALESCE(AVG(a.score), 0)     AS avg_score
             FROM quizzes q
             LEFT JOIN attempts a ON a.quiz_id = q.id
             WHERE q.is_published = 1
             GROUP BY q.id, q.title, q.difficulty
             ORDER BY total_plays DESC"
        );

        // Jawaban salah per quiz dari attempt_answers
        $wrongRows = DB::all(
            "SELECT
                a.quiz_id,
                SUM(CASE WHEN aa.option_id IS NOT NULL AND aa.is_correct = 0 THEN 1 ELSE 0 END) AS wrong_count,
                SUM(CASE WHEN aa.option_id IS NOT NULL THEN 1 ELSE 0 END)                        AS total_answered
             FROM attempt_answers aa
             JOIN attempts a ON aa.attempt_id = a.id
             GROUP BY a.quiz_id"
        );
        $wrongMap = [];
        foreach ($wrongRows as $r) {
            $wrongMap[(int)$r['quiz_id']] = [
                'wrong'    => (int)$r['wrong_count'],
                'answered' => (int)$r['total_answered'],
            ];
        }

        $result = [];
        foreach ($quizStats as $q) {
            $id  = (int)$q['id'];
            $avg = round((float)$q['avg_score'], 1);
            $w   = $wrongMap[$id] ?? ['wrong' => 0, 'answered' => 0];

            $errorPct  = $w['answered'] > 0 ? round(($w['wrong'] / $w['answered']) * 100, 1) : 0;
            $diffRatio = $w['answered'] > 0 ? round($w['wrong'] / $w['answered'], 3) : 0;

            if ($avg >= 80)     $diffLabel = 'Mudah';
            elseif ($avg >= 50) $diffLabel = 'Sedang';
            else                $diffLabel = 'Sulit';

            $result[] = [
                'id'               => $id,
                'title'            => $q['title'],
                'difficulty'       => $q['difficulty'],
                'total_plays'      => (int)$q['total_plays'],
                'total_correct'    => (int)$q['total_correct'],
                'total_wrong'      => $w['wrong'],
                'total_answered'   => $w['answered'],
                'avg_score'        => $avg,
                'error_pct'        => $errorPct,
                'difficulty_ratio' => $diffRatio,
                'difficulty_label' => $diffLabel,
            ];
        }

        jsonSuccess($result);
    } catch (Throwable $e) {
        error_log('[admin.review_soal] ' . $e->__toString());
        jsonError('Terjadi kesalahan server', 500);
    }
}

function admin_user_history(): void {
    requireAdmin();
    $userId = (int)($_GET['user_id'] ?? 0);
    if (!$userId) jsonError('User ID diperlukan');

    [$page, $limit, $offset] = getPaginationParams();
    $limit = min(20, max(1, $limit));

    $total = (int)(DB::one(
        "SELECT COUNT(*) AS cnt FROM attempts WHERE user_id = ?", [$userId]
    )['cnt'] ?? 0);

    $rows = DB::all(
        "SELECT a.id, a.score, a.correct_count, a.time_taken, a.completed_at, a.mode,
                q.title AS quiz_title
         FROM attempts a
         JOIN quizzes q ON a.quiz_id = q.id
         WHERE a.user_id = ?
         ORDER BY a.completed_at DESC
         LIMIT ? OFFSET ?",
        [$userId, $limit, $offset]
    );

    foreach ($rows as &$r) {
        $r['score']         = (int)$r['score'];
        $r['correct_count'] = (int)$r['correct_count'];
        $r['time_taken']    = (int)$r['time_taken'];
    }
    unset($r);

    jsonPaginated($rows, $total, $page, $limit);
}

function admin_quiz_attempts(): void {
    requirePengajar();
    $quizId = (int)($_GET['quiz_id'] ?? 0);
    if (!$quizId) jsonError('Quiz ID diperlukan');

    [$page, $limit, $offset] = getPaginationParams();
    $limit = min(10, max(1, $limit));

    $total = (int)(DB::one(
        "SELECT COUNT(*) AS cnt FROM attempts WHERE quiz_id = ?", [$quizId]
    )['cnt'] ?? 0);

    $rows = DB::all(
        "SELECT a.id, a.score, a.correct_count, a.time_taken, a.completed_at, a.mode,
                u.name AS user_name, u.is_active
         FROM attempts a
         JOIN users u ON a.user_id = u.id
         WHERE a.quiz_id = ?
         ORDER BY a.completed_at DESC
         LIMIT ? OFFSET ?",
        [$quizId, $limit, $offset]
    );

    foreach ($rows as &$r) {
        $r['is_anon']       = !(bool)(int)$r['is_active'];
        $r['score']         = (int)$r['score'];
        $r['correct_count'] = (int)$r['correct_count'];
        $r['time_taken']    = (int)$r['time_taken'];
        unset($r['is_active']);
    }
    unset($r);

    jsonPaginated($rows, $total, $page, $limit);
}

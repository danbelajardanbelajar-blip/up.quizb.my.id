<?php
// ============================================
// api/admin.php — Admin CRUD Endpoints
// ============================================

// ---- Quiz CRUD (Admin) ----

function admin_quiz_list(): void {
    requireAdmin();
    [$page, $limit, $offset] = getPaginationParams();

    $total = DB::one("SELECT COUNT(*) FROM quizzes")['COUNT(*)'];
    $quizzes = DB::all(
        "SELECT q.*, c.name AS category_name,
                u.name AS creator_name,
                (SELECT COUNT(*) FROM questions WHERE quiz_id = q.id) AS question_count,
                (SELECT COUNT(*) FROM attempts WHERE quiz_id = q.id) AS attempt_count
         FROM quizzes q
         LEFT JOIN categories c ON q.category_id = c.id
         LEFT JOIN users u ON q.created_by = u.id
         ORDER BY q.created_at DESC
         LIMIT ? OFFSET ?",
        [$limit, $offset]
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
    $cats = DB::all("SELECT * FROM categories ORDER BY name ASC");
    jsonSuccess($cats);
}

function admin_category_create(): void {
    requireAdmin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    $body = getJsonBody();

    $name = sanitizeString($body['name'] ?? '');
    $slug = sanitizeString($body['slug'] ?? preg_replace('/[^a-z0-9]+/', '-', strtolower($name)));
    $desc = sanitizeString($body['description'] ?? '');
    $icon = sanitizeString($body['icon'] ?? '📚');
    $color= sanitizeString($body['color'] ?? '#6366f1');

    if (strlen($name) < 2) jsonError('Nama minimal 2 karakter');

    $exists = DB::one("SELECT id FROM categories WHERE slug = ?", [$slug]);
    if ($exists) jsonError('Slug sudah digunakan');

    $pdo = DB::conn();
    $pdo->prepare("INSERT INTO categories (name, slug, description, icon, color) VALUES (?,?,?,?,?)")
        ->execute([$name, $slug, $desc, $icon, $color]);
    $cat = DB::one("SELECT * FROM categories WHERE id = ?", [$pdo->lastInsertId()]);
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

    $name  = sanitizeString($body['name'] ?? $existing['name']);
    $slug  = sanitizeString($body['slug'] ?? $existing['slug']);
    $desc  = sanitizeString($body['description'] ?? $existing['description']);
    $icon  = sanitizeString($body['icon'] ?? $existing['icon']);
    $color = sanitizeString($body['color'] ?? $existing['color']);

    DB::conn()->prepare("UPDATE categories SET name=?, slug=?, description=?, icon=?, color=? WHERE id=?")
        ->execute([$name, $slug, $desc, $icon, $color, $id]);
    jsonSuccess(DB::one("SELECT * FROM categories WHERE id = ?", [$id]));
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

    $total = DB::one("SELECT COUNT(*) FROM users")['COUNT(*)'];
    $users = DB::all(
        "SELECT id, name, email, role, total_points, quizzes_taken, is_active, created_at
         FROM users ORDER BY created_at DESC LIMIT ? OFFSET ?",
        [$limit, $offset]
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

    $stats = [
        'total_users'       => (int)DB::one("SELECT COUNT(*) FROM users")['COUNT(*)'],
        'total_quizzes'     => (int)DB::one("SELECT COUNT(*) FROM quizzes")['COUNT(*)'],
        'total_attempts'    => (int)DB::one("SELECT COUNT(*) FROM attempts")['COUNT(*)'],
        'total_questions'   => (int)DB::one("SELECT COUNT(*) FROM questions")['COUNT(*)'],
        'total_categories'  => (int)DB::one("SELECT COUNT(*) FROM categories")['COUNT(*)'],
        'total_classes'     => (int)(DB::one("SELECT COUNT(*) FROM classes") ? DB::one("SELECT COUNT(*) FROM classes")['COUNT(*)'] : 0),
        'total_assignments' => (int)(DB::one("SELECT COUNT(*) FROM assignments") ? DB::one("SELECT COUNT(*) FROM assignments")['COUNT(*)'] : 0),
        'published_quizzes' => (int)DB::one("SELECT COUNT(*) FROM quizzes WHERE is_published=1")['COUNT(*)'],
        'active_users'      => (int)DB::one("SELECT COUNT(*) FROM users WHERE is_active=1")['COUNT(*)'],
        'pengajar_count'    => (int)DB::one("SELECT COUNT(*) FROM users WHERE role='pengajar'")['COUNT(*)'],
        'pelajar_count'     => (int)DB::one("SELECT COUNT(*) FROM users WHERE role IN ('pelajar','user')")['COUNT(*)'],
        'avg_score'         => round((float)(DB::one("SELECT AVG(score) FROM attempts WHERE completed_at IS NOT NULL")['AVG(score)'] ?? 0), 1),
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

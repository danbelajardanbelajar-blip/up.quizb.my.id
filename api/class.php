<?php
// ============================================
// api/class.php — Class (Kelas) Endpoints
// Roles: pengajar (buat/kelola), pelajar (join/lihat)
// ============================================

// ---- Helper: generate join code ----
function generateJoinCode(int $length = 6): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code  = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $code;
}

function requirePengajarOrAdmin(): array {
    $user = requireAuth();
    if (!in_array($user['role'], ['pengajar', 'admin'])) {
        jsonError('Hanya pengajar yang dapat melakukan aksi ini', 403);
    }
    return $user;
}

// ============================================
// GET /api?action=class.list
// Pengajar: list kelas miliknya
// Pelajar: list kelas yang diikuti
// ============================================
function class_list(): void {
    $user = requireAuth();
    $userId = $user['id'];

    if (in_array($user['role'], ['pengajar', 'admin'])) {
        // Pengajar: tampilkan kelas yang dibuat
        $classes = DB::all(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM class_members WHERE class_id = c.id) AS member_count,
                    (SELECT COUNT(*) FROM assignments WHERE class_id = c.id AND is_active = 1) AS assignment_count
             FROM classes c
             WHERE c.teacher_id = ?
             ORDER BY c.created_at DESC",
            [$userId]
        );
    } else {
        // Pelajar: tampilkan kelas yang diikuti
        $classes = DB::all(
            "SELECT c.*,
                    u.name AS teacher_name,
                    cm.joined_at,
                    (SELECT COUNT(*) FROM assignments WHERE class_id = c.id AND is_active = 1) AS assignment_count,
                    (SELECT COUNT(*) FROM assignments a
                     LEFT JOIN assignment_submissions s ON a.id = s.assignment_id AND s.user_id = ?
                     WHERE a.class_id = c.id AND a.is_active = 1 AND s.id IS NULL) AS pending_assignments
             FROM classes c
             JOIN class_members cm ON cm.class_id = c.id
             JOIN users u ON u.id = c.teacher_id
             WHERE cm.user_id = ? AND c.is_active = 1
             ORDER BY cm.joined_at DESC",
            [$userId, $userId]
        );
    }

    jsonSuccess($classes);
}

// ============================================
// GET /api?action=class.get&id=X
// ============================================
function class_get(): void {
    $user    = requireAuth();
    $classId = (int)($_GET['id'] ?? 0);
    if ($classId <= 0) jsonError('ID kelas tidak valid');

    $class = DB::one(
        "SELECT c.*, u.name AS teacher_name
         FROM classes c JOIN users u ON u.id = c.teacher_id
         WHERE c.id = ?",
        [$classId]
    );
    if (!$class) jsonError('Kelas tidak ditemukan', 404);

    // Cek akses: pengajar pemilik atau anggota kelas
    $isTeacher = in_array($user['role'], ['pengajar', 'admin']) && (int)$class['teacher_id'] === $user['id'];
    $isMember  = DB::one(
        "SELECT id FROM class_members WHERE class_id = ? AND user_id = ?",
        [$classId, $user['id']]
    );
    if (!$isTeacher && !$isMember && $user['role'] !== 'admin') {
        jsonError('Anda tidak memiliki akses ke kelas ini', 403);
    }

    // Ambil anggota
    $members = DB::all(
        "SELECT u.id, u.name, u.email, u.avatar, cm.joined_at
         FROM class_members cm JOIN users u ON u.id = cm.user_id
         WHERE cm.class_id = ?
         ORDER BY cm.joined_at ASC",
        [$classId]
    );

    // Ambil tugas
    $assignments = DB::all(
        "SELECT a.*, q.title AS quiz_title, q.total_questions,
                (SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = a.id) AS submission_count
         FROM assignments a JOIN quizzes q ON q.id = a.quiz_id
         WHERE a.class_id = ? AND a.is_active = 1
         ORDER BY a.created_at DESC",
        [$classId]
    );

    jsonSuccess([
        'class'       => $class,
        'members'     => $members,
        'assignments' => $assignments,
        'is_teacher'  => $isTeacher,
    ]);
}

// ============================================
// POST /api?action=class.create
// Hanya pengajar/admin
// ============================================
function class_create(): void {
    $user = requirePengajarOrAdmin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);

    $body = getJsonBody();
    $name = sanitizeString($body['name'] ?? '');
    $desc = sanitizeString($body['description'] ?? '');

    if (strlen($name) < 3) jsonError('Nama kelas minimal 3 karakter');

    // Generate unique join code
    $joinCode = '';
    do {
        $joinCode = generateJoinCode(6);
        $exists   = DB::one("SELECT id FROM classes WHERE join_code = ?", [$joinCode]);
    } while ($exists);

    $pdo = DB::conn();
    $pdo->prepare(
        "INSERT INTO classes (name, description, join_code, teacher_id) VALUES (?, ?, ?, ?)"
    )->execute([$name, $desc, $joinCode, $user['id']]);

    $class = DB::one("SELECT * FROM classes WHERE id = ?", [$pdo->lastInsertId()]);
    jsonSuccess($class, 201);
}

// ============================================
// PUT /api?action=class.update&id=X
// ============================================
function class_update(): void {
    $user    = requirePengajarOrAdmin();
    $classId = (int)($_GET['id'] ?? 0);
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') jsonError('Method not allowed', 405);
    if ($classId <= 0) jsonError('ID tidak valid');

    $class = DB::one("SELECT * FROM classes WHERE id = ?", [$classId]);
    if (!$class) jsonError('Kelas tidak ditemukan', 404);
    if ((int)$class['teacher_id'] !== $user['id'] && $user['role'] !== 'admin') {
        jsonError('Anda bukan pemilik kelas ini', 403);
    }

    $body     = getJsonBody();
    $name     = sanitizeString($body['name']        ?? $class['name']);
    $desc     = sanitizeString($body['description'] ?? $class['description']);
    $isActive = isset($body['is_active']) ? (int)$body['is_active'] : (int)$class['is_active'];

    DB::conn()->prepare(
        "UPDATE classes SET name = ?, description = ?, is_active = ?, updated_at = NOW() WHERE id = ?"
    )->execute([$name, $desc, $isActive, $classId]);

    jsonSuccess(DB::one("SELECT * FROM classes WHERE id = ?", [$classId]));
}

// ============================================
// DELETE /api?action=class.delete&id=X
// ============================================
function class_delete(): void {
    $user    = requirePengajarOrAdmin();
    $classId = (int)($_GET['id'] ?? 0);
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') jsonError('Method not allowed', 405);
    if ($classId <= 0) jsonError('ID tidak valid');

    $class = DB::one("SELECT * FROM classes WHERE id = ?", [$classId]);
    if (!$class) jsonError('Kelas tidak ditemukan', 404);
    if ((int)$class['teacher_id'] !== $user['id'] && $user['role'] !== 'admin') {
        jsonError('Anda bukan pemilik kelas ini', 403);
    }

    DB::conn()->prepare("DELETE FROM classes WHERE id = ?")->execute([$classId]);
    jsonSuccess(['message' => 'Kelas berhasil dihapus']);
}

// ============================================
// POST /api?action=class.join
// Body: { join_code: "XXXXXX" }
// Pelajar bergabung ke kelas
// ============================================
function class_join(): void {
    $user = requireAuth();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);

    $body     = getJsonBody();
    $joinCode = strtoupper(trim($body['join_code'] ?? ''));

    if (strlen($joinCode) < 4) jsonError('Kode kelas tidak valid');

    $class = DB::one(
        "SELECT * FROM classes WHERE join_code = ? AND is_active = 1",
        [$joinCode]
    );
    if (!$class) jsonError('Kode kelas tidak ditemukan atau kelas tidak aktif', 404);

    // Cek sudah join?
    $existing = DB::one(
        "SELECT id FROM class_members WHERE class_id = ? AND user_id = ?",
        [$class['id'], $user['id']]
    );
    if ($existing) jsonError('Anda sudah bergabung ke kelas ini');

    DB::conn()->prepare(
        "INSERT INTO class_members (class_id, user_id) VALUES (?, ?)"
    )->execute([$class['id'], $user['id']]);

    jsonSuccess(['message' => 'Berhasil bergabung ke kelas ' . $class['name'], 'class' => $class]);
}

// ============================================
// DELETE /api?action=class.kick&id=X (class_id)
// Body: { user_id: Y }
// Pengajar mengeluarkan anggota
// ============================================
function class_kick(): void {
    $user    = requirePengajarOrAdmin();
    $classId = (int)($_GET['id'] ?? 0);
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') jsonError('Method not allowed', 405);

    $body      = getJsonBody();
    $targetId  = (int)($body['user_id'] ?? 0);
    if ($classId <= 0 || $targetId <= 0) jsonError('ID tidak valid');

    $class = DB::one("SELECT * FROM classes WHERE id = ?", [$classId]);
    if (!$class) jsonError('Kelas tidak ditemukan', 404);
    if ((int)$class['teacher_id'] !== $user['id'] && $user['role'] !== 'admin') {
        jsonError('Anda bukan pemilik kelas ini', 403);
    }

    // Pastikan target memang anggota agar pesan sukses tidak menyesatkan
    $member = DB::one(
        "SELECT id FROM class_members WHERE class_id = ? AND user_id = ?",
        [$classId, $targetId]
    );
    if (!$member) jsonError('Anggota tidak ditemukan di kelas ini', 404);

    DB::conn()->prepare(
        "DELETE FROM class_members WHERE class_id = ? AND user_id = ?"
    )->execute([$classId, $targetId]);

    jsonSuccess(['message' => 'Anggota berhasil dikeluarkan']);
}

// ============================================
// GET /api?action=class.my_assignments
// Pelajar: lihat semua tugas di kelas yang diikuti
// ============================================
function class_my_assignments(): void {
    $user = requireAuth();
    $userId = $user['id'];

    $assignments = DB::all(
        "SELECT a.*, q.title AS quiz_title, q.total_questions, q.difficulty,
                c.name AS class_name,
                u.name AS teacher_name,
                s.id AS submission_id,
                s.submitted_at,
                att.score AS my_score
         FROM assignments a
         JOIN classes c ON c.id = a.class_id
         JOIN quizzes q ON q.id = a.quiz_id
         JOIN users u ON u.id = a.teacher_id
         JOIN class_members cm ON cm.class_id = c.id AND cm.user_id = ?
         LEFT JOIN assignment_submissions s ON s.assignment_id = a.id AND s.user_id = ?
         LEFT JOIN attempts att ON att.id = s.attempt_id
         WHERE a.is_active = 1
         ORDER BY a.created_at DESC",
        [$userId, $userId]
    );

    jsonSuccess($assignments);
}

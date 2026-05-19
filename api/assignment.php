<?php
// ============================================
// api/assignment.php — Assignment (Tugas) Endpoints
// ============================================

// ============================================
// POST /api?action=assignment.create
// Pengajar membuat tugas untuk kelas
// ============================================
function assignment_create(): void {
    // Re-use helper from class.php (loaded via require_once chain)
    $user = requireAuth();
    if (!in_array($user['role'], ['pengajar', 'admin'])) {
        jsonError('Hanya pengajar yang dapat membuat tugas', 403);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);

    $body             = getJsonBody();
    $classId          = (int)($body['class_id']             ?? 0);
    $quizId           = (int)($body['quiz_id']              ?? 0);
    $title            = sanitizeString($body['title']       ?? '');
    $mode             = sanitizeString($body['mode']        ?? 'bebas');
    $deadline         = $body['deadline']                   ?? null;
    $maxQuestions     = isset($body['max_questions'])       ? (int)$body['max_questions']        : null;
    $timerPerQ        = isset($body['timer_per_question'])  ? (int)$body['timer_per_question']   : null;
    $durationMins     = isset($body['duration_minutes'])    ? (int)$body['duration_minutes']     : null;
    $shuffleQuestions = array_key_exists('shuffle_questions', $body)
                        ? (int)(bool)$body['shuffle_questions'] : null;
    $shuffleOptions   = array_key_exists('shuffle_options', $body)
                        ? (int)(bool)$body['shuffle_options']   : null;
    $requireFull      = isset($body['require_full_score']) ? (int)(bool)$body['require_full_score'] : 0;

    if ($classId <= 0) jsonError('Pilih kelas');
    if ($quizId  <= 0) jsonError('Pilih paket soal');
    if (strlen($title) < 3) jsonError('Judul tugas minimal 3 karakter');
    if (!in_array($mode, ['instant','end','exam','bebas'])) jsonError('Mode tidak valid');

    // Verifikasi kelas milik pengajar ini
    $class = DB::one("SELECT * FROM classes WHERE id = ?", [$classId]);
    if (!$class) jsonError('Kelas tidak ditemukan', 404);
    if ((int)$class['teacher_id'] !== $user['id'] && $user['role'] !== 'admin') {
        jsonError('Bukan kelas milik Anda', 403);
    }

    // Verifikasi quiz ada
    $quiz = DB::one("SELECT id FROM quizzes WHERE id = ?", [$quizId]);
    if (!$quiz) jsonError('Paket soal tidak ditemukan', 404);

    $pdo = DB::conn();
    $pdo->prepare(
        "INSERT INTO assignments
         (class_id, quiz_id, teacher_id, title, mode, deadline,
          max_questions, timer_per_question, duration_minutes,
          shuffle_questions, shuffle_options, require_full_score)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        $classId, $quizId, $user['id'], $title, $mode,
        $deadline ?: null,
        $maxQuestions,
        $timerPerQ,
        $durationMins,
        $shuffleQuestions,
        $shuffleOptions,
        $requireFull,
    ]);
    $newId = $pdo->lastInsertId();

    $assignment = DB::one(
        "SELECT a.*, q.title AS quiz_title, c.name AS class_name
         FROM assignments a JOIN quizzes q ON q.id = a.quiz_id JOIN classes c ON c.id = a.class_id
         WHERE a.id = ?",
        [$newId]
    );
    jsonSuccess($assignment, 201);
}

// ============================================
// GET /api?action=assignment.list&class_id=X
// ============================================
function assignment_list(): void {
    $user    = requireAuth();
    $classId = (int)($_GET['class_id'] ?? 0);
    if ($classId <= 0) jsonError('class_id diperlukan');

    // Verifikasi akses ke kelas
    $isTeacher = in_array($user['role'], ['pengajar', 'admin']);
    if ($isTeacher) {
        $class = DB::one("SELECT id FROM classes WHERE id = ? AND teacher_id = ?", [$classId, $user['id']]);
        if (!$class && $user['role'] !== 'admin') jsonError('Bukan kelas milik Anda', 403);
    } else {
        $member = DB::one(
            "SELECT id FROM class_members WHERE class_id = ? AND user_id = ?",
            [$classId, $user['id']]
        );
        if (!$member) jsonError('Anda bukan anggota kelas ini', 403);
    }

    $assignments = DB::all(
        "SELECT a.*, q.title AS quiz_title, q.total_questions, q.difficulty,
                (SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = a.id) AS submission_count
         FROM assignments a
         JOIN quizzes q ON q.id = a.quiz_id
         WHERE a.class_id = ? AND a.is_active = 1
         ORDER BY a.created_at DESC",
        [$classId]
    );

    // Untuk pelajar: tandai apakah sudah dikerjakan
    if (!$isTeacher) {
        foreach ($assignments as &$a) {
            $sub = DB::one(
                "SELECT s.id, s.submitted_at, att.score
                 FROM assignment_submissions s
                 JOIN attempts att ON att.id = s.attempt_id
                 WHERE s.assignment_id = ? AND s.user_id = ?",
                [$a['id'], $user['id']]
            );
            $a['my_submission'] = $sub;
            $a['is_done'] = false;
            if (!empty($sub)) {
                if (!empty($a['require_full_score'])) {
                    $a['is_done'] = isset($sub['score']) && (int)$sub['score'] === 100;
                } else {
                    $a['is_done'] = true;
                }
            }
        }
        unset($a);
    }

    jsonSuccess($assignments);
}

// ============================================
// GET /api?action=assignment.get&id=X
// ============================================
function assignment_get(): void {
    $user = requireAuth();
    $id   = (int)($_GET['id'] ?? 0);
    if ($id <= 0) jsonError('ID tidak valid');

    $assignment = DB::one(
        "SELECT a.*, q.title AS quiz_title, q.total_questions, q.difficulty,
                c.name AS class_name, u.name AS teacher_name
         FROM assignments a
         JOIN quizzes q ON q.id = a.quiz_id
         JOIN classes c ON c.id = a.class_id
         JOIN users u ON u.id = a.teacher_id
         WHERE a.id = ?",
        [$id]
    );
    if (!$assignment) jsonError('Tugas tidak ditemukan', 404);

    // Cek akses
    $isTeacher = in_array($user['role'], ['pengajar','admin']) &&
                 (int)$assignment['teacher_id'] === $user['id'];
    $isMember  = DB::one(
        "SELECT id FROM class_members WHERE class_id = ? AND user_id = ?",
        [$assignment['class_id'], $user['id']]
    );
    if (!$isTeacher && !$isMember && $user['role'] !== 'admin') {
        jsonError('Anda tidak memiliki akses ke tugas ini', 403);
    }

    // Pelajar: cek status pengerjaan
    if (!$isTeacher) {
        $sub = DB::one(
            "SELECT s.*, att.score FROM assignment_submissions s
             JOIN attempts att ON att.id = s.attempt_id
             WHERE s.assignment_id = ? AND s.user_id = ?",
            [$id, $user['id']]
        );
        $assignment['my_submission'] = $sub;
        $assignment['is_done'] = false;
        if (!empty($sub)) {
            if (!empty($assignment['require_full_score'])) {
                $assignment['is_done'] = isset($sub['score']) && (int)$sub['score'] === 100;
            } else {
                $assignment['is_done'] = true;
            }
        }
    }

    // Pengajar: daftar submission
    if ($isTeacher || $user['role'] === 'admin') {
        $assignment['submissions'] = DB::all(
            "SELECT s.*, u.name AS student_name, att.score, att.correct_count, att.time_taken
             FROM assignment_submissions s
             JOIN users u ON u.id = s.user_id
             JOIN attempts att ON att.id = s.attempt_id
             WHERE s.assignment_id = ?
             ORDER BY att.score DESC, s.submitted_at ASC",
            [$id]
        );
    }

    jsonSuccess($assignment);
}

// ============================================
// PUT /api?action=assignment.update&id=X
// ============================================
function assignment_update(): void {
    $user = requireAuth();
    $id   = (int)($_GET['id'] ?? 0);
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') jsonError('Method not allowed', 405);
    if ($id <= 0) jsonError('ID tidak valid');

    $assignment = DB::one("SELECT * FROM assignments WHERE id = ?", [$id]);
    if (!$assignment) jsonError('Tugas tidak ditemukan', 404);
    if ((int)$assignment['teacher_id'] !== $user['id'] && $user['role'] !== 'admin') {
        jsonError('Bukan tugas milik Anda', 403);
    }

    $body         = getJsonBody();
    $title        = sanitizeString($body['title'] ?? $assignment['title']);
    $mode         = sanitizeString($body['mode']  ?? $assignment['mode']);
    $deadline     = $body['deadline']    ?? $assignment['deadline'];
    $isActive     = isset($body['is_active'])          ? (int)$body['is_active']           : (int)$assignment['is_active'];
    $maxQ         = isset($body['max_questions'])       ? (int)$body['max_questions']       : $assignment['max_questions'];
    $timerQ       = isset($body['timer_per_question'])  ? (int)$body['timer_per_question']  : $assignment['timer_per_question'];
    $durMins      = isset($body['duration_minutes'])    ? (int)$body['duration_minutes']    : $assignment['duration_minutes'];
    // shuffle: jika key ada di body → pakai nilai baru; jika tidak ada → pertahankan lama (termasuk NULL)
    $shuffleQ     = array_key_exists('shuffle_questions', $body)
                    ? (strlen((string)$body['shuffle_questions']) ? (int)(bool)$body['shuffle_questions'] : null)
                    : $assignment['shuffle_questions'];
    $shuffleO     = array_key_exists('shuffle_options', $body)
                    ? (strlen((string)$body['shuffle_options'])   ? (int)(bool)$body['shuffle_options']   : null)
                    : $assignment['shuffle_options'];
    $requireFull  = array_key_exists('require_full_score', $body)
                    ? (int)(bool)$body['require_full_score']
                    : (int)$assignment['require_full_score'];

    DB::conn()->prepare(
        "UPDATE assignments SET title=?, mode=?, deadline=?, is_active=?,
                max_questions=?, timer_per_question=?, duration_minutes=?,
                shuffle_questions=?, shuffle_options=?, require_full_score=?
         WHERE id=?"
    )->execute([$title, $mode, $deadline ?: null, $isActive,
                $maxQ, $timerQ, $durMins,
                $shuffleQ, $shuffleO, $requireFull,
                $id]);

    jsonSuccess(DB::one("SELECT * FROM assignments WHERE id = ?", [$id]));
}

// ============================================
// DELETE /api?action=assignment.delete&id=X
// ============================================
function assignment_delete(): void {
    $user = requireAuth();
    $id   = (int)($_GET['id'] ?? 0);
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') jsonError('Method not allowed', 405);
    if ($id <= 0) jsonError('ID tidak valid');

    $assignment = DB::one("SELECT * FROM assignments WHERE id = ?", [$id]);
    if (!$assignment) jsonError('Tugas tidak ditemukan', 404);
    if ((int)$assignment['teacher_id'] !== $user['id'] && $user['role'] !== 'admin') {
        jsonError('Bukan tugas milik Anda', 403);
    }

    DB::conn()->prepare("DELETE FROM assignments WHERE id = ?")->execute([$id]);
    jsonSuccess(['message' => 'Tugas berhasil dihapus']);
}

// ============================================
// POST /api?action=assignment.submit
// Pelajar submit hasil attempt ke tugas
// Body: { assignment_id, attempt_id }
// ============================================
function assignment_submit(): void {
    $user = requireAuth();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);

    $body         = getJsonBody();
    $assignmentId = (int)($body['assignment_id'] ?? 0);
    $attemptId    = (int)($body['attempt_id']    ?? 0);
    if ($assignmentId <= 0 || $attemptId <= 0) jsonError('Data tidak lengkap');

    // Verifikasi assignment
    $assignment = DB::one("SELECT * FROM assignments WHERE id = ? AND is_active = 1", [$assignmentId]);
    if (!$assignment) jsonError('Tugas tidak ditemukan atau tidak aktif', 404);

    // Verifikasi anggota kelas
    $member = DB::one(
        "SELECT id FROM class_members WHERE class_id = ? AND user_id = ?",
        [$assignment['class_id'], $user['id']]
    );
    if (!$member) jsonError('Anda bukan anggota kelas ini', 403);

    // Verifikasi attempt milik user dan untuk quiz yang sama
    $attempt = DB::one(
        "SELECT * FROM attempts WHERE id = ? AND user_id = ? AND quiz_id = ?",
        [$attemptId, $user['id'], $assignment['quiz_id']]
    );
    if (!$attempt) jsonError('Attempt tidak valid', 404);

    // Cek deadline
    if ($assignment['deadline'] && strtotime($assignment['deadline']) < time()) {
        jsonError('Batas waktu tugas sudah berakhir');
    }

    // Cek sudah submit?
    $existing = DB::one(
        "SELECT id, attempt_id FROM assignment_submissions WHERE assignment_id = ? AND user_id = ?",
        [$assignmentId, $user['id']]
    );

    if ($existing) {
        // Jika tugas meminta full score, izinkan update jika skor lama < 100
        if (!empty($assignment['require_full_score'])) {
            $oldAttempt = DB::one("SELECT score FROM attempts WHERE id = ?", [$existing['attempt_id']]);
            $oldScore = $oldAttempt && isset($oldAttempt['score']) ? (int)$oldAttempt['score'] : null;
            if ($oldScore !== null && $oldScore === 100) {
                jsonError('Anda sudah mengumpulkan tugas ini');
            }
            // Update existing submission record ke attempt baru
            DB::conn()->prepare(
                "UPDATE assignment_submissions SET attempt_id = ?, submitted_at = NOW() WHERE id = ?"
            )->execute([$attemptId, $existing['id']]);
            jsonSuccess(['message' => 'Tugas berhasil diperbarui', 'score' => $attempt['score']]);
        }

        // Jika tidak require full score, tolak submit ulang
        jsonError('Anda sudah mengumpulkan tugas ini');
    }

    DB::conn()->prepare(
        "INSERT INTO assignment_submissions (assignment_id, user_id, attempt_id) VALUES (?, ?, ?)"
    )->execute([$assignmentId, $user['id'], $attemptId]);

    jsonSuccess(['message' => 'Tugas berhasil dikumpulkan', 'score' => $attempt['score']]);
}

// ============================================
// GET /api?action=assignment.results&id=X
// Pengajar lihat hasil semua pelajar untuk 1 tugas
// ============================================
function assignment_results(): void {
    $user = requireAuth();
    $id   = (int)($_GET['id'] ?? 0);
    if ($id <= 0) jsonError('ID tidak valid');

    $assignment = DB::one("SELECT * FROM assignments WHERE id = ?", [$id]);
    if (!$assignment) jsonError('Tugas tidak ditemukan', 404);
    if ((int)$assignment['teacher_id'] !== $user['id'] && $user['role'] !== 'admin') {
        jsonError('Bukan tugas milik Anda', 403);
    }

    // Total anggota kelas
    $totalMembers = (int)DB::one(
        "SELECT COUNT(*) AS c FROM class_members WHERE class_id = ?",
        [$assignment['class_id']]
    )['c'];

    // Semua submission
    $submissions = DB::all(
        "SELECT u.id AS user_id, u.name AS student_name, u.email,
                s.submitted_at,
                att.score, att.correct_count, att.time_taken, att.total_points
         FROM class_members cm
         JOIN users u ON u.id = cm.user_id
         LEFT JOIN assignment_submissions s ON s.assignment_id = ? AND s.user_id = u.id
         LEFT JOIN attempts att ON att.id = s.attempt_id
         WHERE cm.class_id = ?
         ORDER BY att.score DESC, s.submitted_at ASC",
        [$id, $assignment['class_id']]
    );

    $submitted = array_filter($submissions, fn($s) => !empty($s['submitted_at']));
    $avgScore  = count($submitted) > 0
        ? round(array_sum(array_column($submitted, 'score')) / count($submitted), 1)
        : 0;

    jsonSuccess([
        'assignment'    => $assignment,
        'total_members' => $totalMembers,
        'submitted'     => count($submitted),
        'not_submitted' => $totalMembers - count($submitted),
        'avg_score'     => $avgScore,
        'submissions'   => array_values($submissions),
    ]);
}

// ============================================
// POST /api?action=assignment.progress_update
// Siswa kirim heartbeat posisi soal
// ============================================
function assignment_progress_update(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    $user = requireAuth();
    $body = getJsonBody();

    $assignmentId = (int)($body['assignment_id']    ?? 0);
    $currentQ     = (int)($body['current_question'] ?? 0);
    $totalQ       = (int)($body['total_questions']  ?? 0);
    if (!$assignmentId) jsonError('assignment_id diperlukan');

    $existing = DB::one(
        'SELECT is_forced_stop FROM assignment_progress WHERE assignment_id = ? AND user_id = ?',
        [$assignmentId, $user['id']]
    );
    if ($existing && (int)$existing['is_forced_stop']) {
        jsonSuccess(['is_forced_stop' => true]);
        return;
    }

    DB::execute(
        "INSERT INTO assignment_progress
         (assignment_id, user_id, current_question, total_questions, last_seen_at)
         VALUES (?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
           current_question = VALUES(current_question),
           total_questions  = VALUES(total_questions),
           last_seen_at     = NOW()",
        [$assignmentId, $user['id'], $currentQ, $totalQ]
    );
    jsonSuccess(['is_forced_stop' => false]);
}

// ============================================
// GET /api?action=assignment.monitor&id=X
// Guru pantau progress live semua siswa
// ============================================
function assignment_monitor(): void {
    $user = requireAuth();
    if (!in_array($user['role'], ['pengajar', 'admin'])) jsonError('Akses ditolak', 403);

    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) jsonError('ID tidak valid');

    $assignment = DB::one(
        "SELECT a.*, q.title AS quiz_title, c.name AS class_name
         FROM assignments a
         JOIN quizzes q ON q.id = a.quiz_id
         JOIN classes  c ON c.id = a.class_id
         WHERE a.id = ?",
        [$id]
    );
    if (!$assignment) jsonError('Tugas tidak ditemukan', 404);
    if ((int)$assignment['teacher_id'] !== $user['id'] && $user['role'] !== 'admin')
        jsonError('Bukan tugas milik Anda', 403);

    $students = DB::all(
        "SELECT u.id AS user_id, u.name AS student_name,
                p.current_question, p.total_questions,
                p.started_at, p.last_seen_at, p.is_forced_stop,
                s.submitted_at, att.score, att.correct_count, att.time_taken
         FROM   class_members cm
         JOIN   users u ON u.id = cm.user_id
         LEFT JOIN assignment_progress p ON p.assignment_id = ? AND p.user_id = u.id
         LEFT JOIN assignment_submissions s ON s.assignment_id = ? AND s.user_id = u.id
         LEFT JOIN attempts att ON att.id = s.attempt_id
         WHERE  cm.class_id = ?
         ORDER BY
           CASE
             WHEN s.submitted_at IS NOT NULL THEN 3
             WHEN p.last_seen_at > DATE_SUB(NOW(), INTERVAL 30 SECOND) THEN 1
             ELSE 2
           END,
           p.last_seen_at DESC, u.name ASC",
        [$id, $id, $assignment['class_id']]
    );

    $active = 0; $submitted = 0;
    foreach ($students as &$s) {
        if (!empty($s['submitted_at'])) {
            $s['status'] = 'submitted'; $submitted++;
        } elseif (!empty($s['last_seen_at']) && strtotime($s['last_seen_at']) > time() - 30) {
            $s['status'] = 'active'; $active++;
        } elseif (!empty($s['started_at'])) {
            $s['status'] = (int)($s['is_forced_stop'] ?? 0) ? 'stopped' : 'idle';
        } else {
            $s['status'] = 'not_started';
        }
    }
    unset($s);

    jsonSuccess([
        'assignment'      => $assignment,
        'students'        => $students,
        'active_count'    => $active,
        'submitted_count' => $submitted,
        'total_count'     => count($students),
    ]);
}

// ============================================
// POST /api?action=assignment.force_stop
// Guru paksa hentikan pengerjaan siswa
// ============================================
function assignment_force_stop(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    $user = requireAuth();
    if (!in_array($user['role'], ['pengajar', 'admin'])) jsonError('Akses ditolak', 403);

    $body      = getJsonBody();
    $assignId  = (int)($body['assignment_id'] ?? 0);
    $studentId = (int)($body['student_id']    ?? 0);
    if (!$assignId || !$studentId) jsonError('Data tidak lengkap');

    $assignment = DB::one('SELECT * FROM assignments WHERE id = ?', [$assignId]);
    if (!$assignment) jsonError('Tugas tidak ditemukan', 404);
    if ((int)$assignment['teacher_id'] !== $user['id'] && $user['role'] !== 'admin')
        jsonError('Bukan tugas milik Anda', 403);

    DB::execute(
        "INSERT INTO assignment_progress (assignment_id, user_id, is_forced_stop)
         VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE is_forced_stop = 1",
        [$assignId, $studentId]
    );
    jsonSuccess([], 'Pekerjaan siswa berhasil dihentikan.');
}

// ============================================
// GET /api?action=assignment.my_dashboard
// Ringkasan tugas untuk dashboard (pelajar & pengajar)
// ============================================
function assignment_my_dashboard(): void {
    $user = requireAuth();
    $role = $user['role'];

    if (!in_array($role, ['pelajar', 'pengajar', 'admin'])) {
        jsonSuccess(['assignments' => [], 'role' => $role]);
        return;
    }

    if ($role === 'pelajar') {
        // Ambil semua tugas aktif dari kelas yang diikuti pelajar,
        // lengkap dengan status submission
        $rows = DB::all(
            "SELECT
                a.id, a.title, a.deadline, a.mode,
                a.timer_per_question, a.duration_minutes,
                q.id     AS quiz_id,
                q.title  AS quiz_title,
                q.total_questions,
                cl.id    AS class_id,
                cl.name  AS class_name,
                s.id     AS submission_id,
                s.submitted_at,
                (SELECT MAX(score) FROM attempts WHERE user_id = ? AND quiz_id = a.quiz_id) AS my_score,
                CASE
                    WHEN s.id IS NOT NULL AND (a.require_full_score = 0 OR (a.require_full_score = 1 AND IFNULL(att.score,0) = 100)) THEN 'done'
                    WHEN s.id IS NOT NULL AND a.require_full_score = 1 AND IFNULL(att.score,0) < 100 THEN 'incomplete'
                    WHEN a.deadline IS NOT NULL AND a.deadline < NOW() THEN 'overdue'
                    ELSE 'pending'
                END AS status
            FROM class_members cm
            INNER JOIN classes      cl ON cl.id = cm.class_id AND cl.is_active = 1
            INNER JOIN assignments  a  ON a.class_id = cl.id  AND a.is_active  = 1
            INNER JOIN quizzes      q  ON q.id = a.quiz_id
            LEFT  JOIN assignment_submissions s
                   ON s.assignment_id = a.id AND s.user_id = ?
            LEFT  JOIN attempts att ON att.id = s.attempt_id
            WHERE cm.user_id = ?
            ORDER BY
                CASE
                    WHEN s.id IS NULL AND (a.deadline IS NULL OR a.deadline > NOW()) THEN 0
                    WHEN a.deadline IS NOT NULL AND a.deadline < NOW() AND s.id IS NULL THEN 1
                    ELSE 2
                END,
                a.deadline ASC,
                a.created_at DESC
        ", [$user['id'], $user['id'], $user['id']]);

        foreach ($rows as &$r) {
            $r['id']             = (int)$r['id'];
            $r['quiz_id']        = (int)$r['quiz_id'];
            $r['class_id']       = (int)$r['class_id'];
            $r['total_questions']= (int)$r['total_questions'];
            $r['my_score']       = $r['my_score'] !== null ? (int)$r['my_score'] : null;
        }
        unset($r);

        jsonSuccess(['assignments' => $rows, 'role' => 'pelajar']);

    } else {
        // Pengajar / admin: semua tugas aktif milik mereka + jumlah submission
        $teacherCond = $role === 'admin' ? '' : 'AND a.teacher_id = ?';
        $params      = $role === 'admin' ? [] : [$user['id']];

        $rows = DB::all("
            SELECT
                a.id, a.title, a.deadline, a.mode,
                q.title AS quiz_title,
                q.total_questions,
                cl.id   AS class_id,
                cl.name AS class_name,
                (SELECT COUNT(*) FROM class_members
                 WHERE class_id = cl.id) AS member_count,
                (SELECT COUNT(*) FROM assignment_submissions
                 WHERE assignment_id = a.id) AS submission_count,
                CASE
                    WHEN a.deadline IS NOT NULL AND a.deadline < NOW() THEN 'expired'
                    ELSE 'active'
                END AS status
            FROM assignments a
            INNER JOIN quizzes q  ON q.id  = a.quiz_id
            INNER JOIN classes cl ON cl.id = a.class_id AND cl.is_active = 1
            WHERE a.is_active = 1 $teacherCond
            ORDER BY a.deadline ASC, a.created_at DESC
            LIMIT 20
        ", $params);

        foreach ($rows as &$r) {
            $r['id']              = (int)$r['id'];
            $r['class_id']        = (int)$r['class_id'];
            $r['total_questions'] = (int)$r['total_questions'];
            $r['member_count']    = (int)$r['member_count'];
            $r['submission_count']= (int)$r['submission_count'];
        }
        unset($r);

        jsonSuccess(['assignments' => $rows, 'role' => 'pengajar']);
    }
}

// ============================================
// GET /api?action=assignment.class_report&class_id=X
// Pengajar unduh rekap nilai semua tugas × semua siswa dalam 1 kelas
// ============================================
function assignment_class_report(): void {
    $user    = requireAuth();
    $classId = (int)($_GET['class_id'] ?? 0);
    if ($classId <= 0) jsonError('class_id diperlukan');

    // Verifikasi kepemilikan kelas
    $class = DB::one("SELECT * FROM classes WHERE id = ?", [$classId]);
    if (!$class) jsonError('Kelas tidak ditemukan', 404);
    if ((int)$class['teacher_id'] !== $user['id'] && $user['role'] !== 'admin') {
        jsonError('Bukan kelas milik Anda', 403);
    }

    // Semua anggota kelas (siswa, bukan pengajar)
    $members = DB::all(
        "SELECT u.id, u.name, u.email
         FROM class_members cm
         JOIN users u ON u.id = cm.user_id
         WHERE cm.class_id = ?
         ORDER BY u.name ASC",
        [$classId]
    );

    // Semua tugas aktif di kelas ini
    $assignments = DB::all(
        "SELECT a.id, a.title, a.mode, a.deadline, a.created_at,
                q.title AS quiz_title
         FROM assignments a
         JOIN quizzes q ON q.id = a.quiz_id
         WHERE a.class_id = ? AND a.is_active = 1
         ORDER BY a.created_at ASC",
        [$classId]
    );

    if (empty($assignments)) {
        jsonSuccess([
            'class'       => $class,
            'members'     => $members,
            'assignments' => [],
            'scores'      => [],
        ]);
        return;
    }

    // Ambil semua submission untuk kelas ini sekaligus (satu query)
    $assignmentIds = array_column($assignments, 'id');
    $placeholders  = implode(',', array_fill(0, count($assignmentIds), '?'));

    $rows = DB::all(
        "SELECT s.assignment_id, s.user_id,
                att.score, att.correct_count, att.time_taken, s.submitted_at
         FROM assignment_submissions s
         JOIN attempts att ON att.id = s.attempt_id
         WHERE s.assignment_id IN ($placeholders)",
        $assignmentIds
    );

    // Index: scores[user_id][assignment_id] = { score, correct_count, time_taken, submitted_at }
    $scores = [];
    foreach ($rows as $r) {
        $scores[$r['user_id']][$r['assignment_id']] = [
            'score'         => $r['score'] !== null ? (int)$r['score'] : null,
            'correct_count' => $r['correct_count'] !== null ? (int)$r['correct_count'] : null,
            'time_taken'    => $r['time_taken'] !== null ? (int)$r['time_taken'] : null,
            'submitted_at'  => $r['submitted_at'],
        ];
    }

    jsonSuccess([
        'class'       => $class,
        'members'     => $members,
        'assignments' => $assignments,
        'scores'      => $scores,
    ]);
}

// ============================================
// GET /api?action=assignment.attempts&id=X
// Return attempts by current user for assignment's quiz (student view)
// ============================================
function assignment_attempts(): void {
    $user = requireAuth();
    $id   = (int)($_GET['id'] ?? 0);
    if ($id <= 0) jsonError('ID tidak valid');

    $assignment = DB::one("SELECT * FROM assignments WHERE id = ?", [$id]);
    if (!$assignment) jsonError('Tugas tidak ditemukan', 404);

    // Pastikan user adalah anggota kelas atau guru/admin
    $isTeacher = in_array($user['role'], ['pengajar','admin']) && (int)$assignment['teacher_id'] === $user['id'];
    $isMember  = DB::one("SELECT id FROM class_members WHERE class_id = ? AND user_id = ?", [$assignment['class_id'], $user['id']]);
    if (!$isTeacher && !$isMember && $user['role'] !== 'admin') jsonError('Anda tidak memiliki akses ke tugas ini', 403);

    // Untuk pelajar: ambil semua attempt milik user untuk quiz assignment ini
    $attempts = DB::all(
        "SELECT id, score, correct_count, time_taken, completed_at, mode
         FROM attempts
         WHERE user_id = ? AND quiz_id = ?
         ORDER BY completed_at DESC",
        [$user['id'], $assignment['quiz_id']]
    );

    jsonSuccess(['assignment' => $assignment, 'attempts' => $attempts]);
}

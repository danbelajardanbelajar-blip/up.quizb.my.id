<?php
// Shared by attempt.submit (atomic contract v2) and legacy assignment.submit.
class AssignmentSubmissionException extends RuntimeException {}

function assignmentSubmissionTables(): void {
    DB::execute('CREATE TABLE IF NOT EXISTS assignment_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY, assignment_id INT, user_id INT,
        attempt_id INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB');
    DB::execute('CREATE TABLE IF NOT EXISTS submission_requests (
        user_id INT NOT NULL, submission_id VARCHAR(64) NOT NULL,
        payload_hash CHAR(64) NOT NULL, response_json MEDIUMTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, submission_id)
    ) ENGINE=InnoDB');
    proctoringReceiptTable();
    if (!assignmentSubmissionTransactional()) throw new AssignmentSubmissionException('Penyimpanan tugas belum mendukung transaksi aman. Hubungi pengelola.', 503);
}

function assignmentSubmissionTransactional(): bool {
    $tables = DB::all("SELECT TABLE_NAME AS table_name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('assignments','assignment_submissions','attempts','attempt_answers','users','quizzes','assignment_attempts','submission_requests','proctoring_receipts')");
    $engines = array_column($tables, 'engine', 'table_name');
    foreach (['assignments','assignment_submissions','attempts','attempt_answers','users','quizzes','assignment_attempts','submission_requests','proctoring_receipts'] as $table) {
        if (strtolower($engines[$table] ?? '') !== 'innodb') return false;
    }
    return true;
}

function submissionPayloadHash(array $body): string {
    $answers = [];
    foreach (($body['answers'] ?? []) as $key => $value) {
        if (is_array($value)) $answers[(int)$value['question_id']] = (int)($value['option_id'] ?? 0);
        else $answers[(int)$key] = (int)($value ?? 0);
    }
    ksort($answers, SORT_NUMERIC);
    return hash('sha256', json_encode([
        (int)($body['quiz_id'] ?? 0), (int)($body['assignment_id'] ?? 0),
        $body['mode'] ?? 'exam', $body['question_ids'] ?? [],
        $body['reached_question_ids'] ?? [], $answers, (int)($body['time_taken'] ?? 0)
    ], JSON_THROW_ON_ERROR));
}

function assignmentSubmissionScope(array $user, int $assignmentId, int $quizId): array {
    $assignment = DB::one('SELECT * FROM assignments WHERE id = ? AND is_active = 1 FOR UPDATE', [$assignmentId]);
    if (!$assignment) throw new AssignmentSubmissionException('Tugas tidak ditemukan atau tidak aktif', 404);
    $member = DB::one('SELECT id FROM class_members WHERE class_id = ? AND user_id = ?', [$assignment['class_id'], $user['id']]);
    $teacher = ($user['role'] ?? '') === 'admin' || (int)$assignment['teacher_id'] === (int)$user['id'];
    if (!$member && !$teacher) throw new AssignmentSubmissionException('Anda bukan anggota kelas ini', 403);
    if ((int)$assignment['quiz_id'] !== $quizId && !DB::one(
        'SELECT quiz_id FROM assignment_quiz_packages WHERE assignment_id = ? AND quiz_id = ?', [$assignmentId, $quizId]
    )) throw new AssignmentSubmissionException('Quiz tidak terkait dengan tugas ini', 403);
    return $assignment;
}

function assignmentPersistSubmission(array $user, array $assignment, array $attempt): array {
    $assignmentId = (int)$assignment['id']; $attemptId = (int)$attempt['id'];
    $existing = DB::one('SELECT id, attempt_id FROM assignment_submissions WHERE assignment_id = ? AND user_id = ?', [$assignmentId, $user['id']]);
    // An acknowledged retry stays successful even after the deadline or a perfect score.
    if ($existing && (int)$existing['attempt_id'] === $attemptId) {
        return ['assignment_submitted' => true, 'score' => (int)$attempt['score']];
    }
    if (!empty($assignment['deadline']) && strtotime($assignment['deadline']) < time()) {
        throw new AssignmentSubmissionException('Batas waktu tugas sudah berakhir', 409);
    }
    if ($existing && !empty($assignment['require_full_score'])) {
        $old = DB::one('SELECT score FROM attempts WHERE id = ?', [$existing['attempt_id']]);
        if ($old && (int)$old['score'] === 100) throw new AssignmentSubmissionException('Tugas ini sudah mendapatkan nilai sempurna (100).', 409);
    }
    $quiz = DB::one('SELECT require_camera FROM quizzes WHERE id = ?', [$attempt['quiz_id']]);
    if (!empty($assignment['require_camera']) || !empty($quiz['require_camera'])) {
        $proof = DB::one('SELECT attempt_id FROM proctoring_receipts WHERE attempt_id = ? AND user_id = ? AND assignment_id = ? AND quiz_id = ?', [$attemptId, $user['id'], $assignmentId, $attempt['quiz_id']]);
        if (!$proof) throw new AssignmentSubmissionException('Bukti verifikasi kamera untuk tugas ini belum tersedia. Hubungi pengajar.', 409);
    }
    if (!DB::one('SELECT id FROM assignment_attempts WHERE assignment_id = ? AND user_id = ? AND attempt_id = ?', [$assignmentId, $user['id'], $attemptId])) {
        DB::execute('INSERT INTO assignment_attempts (assignment_id, user_id, attempt_id) VALUES (?, ?, ?)', [$assignmentId, $user['id'], $attemptId]);
    }
    if ($existing) DB::execute('UPDATE assignment_submissions SET attempt_id = ?, submitted_at = NOW() WHERE id = ?', [$attemptId, $existing['id']]);
    else DB::execute('INSERT INTO assignment_submissions (assignment_id, user_id, attempt_id) VALUES (?, ?, ?)', [$assignmentId, $user['id'], $attemptId]);
    return ['assignment_submitted' => true, 'score' => (int)$attempt['score']];
}

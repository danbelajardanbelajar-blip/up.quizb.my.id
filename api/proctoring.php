<?php
// ============================================
// api/proctoring.php — AI Proctoring Controller
// ============================================

require_once __DIR__ . '/../includes/proctoring.php';
require_once __DIR__ . '/attempt.php';

/**
 * Cek status foto acuan ujian pengguna
 * GET /api.php?action=proctoring.check_reference
 */
function proctoring_check_reference(): void {
    $user = requireAuth();
    $status = getUserExamReference((int)$user['id']);

    $refPhotoBase64 = null;
    if ($status['has_reference'] && !empty($status['photo_path'])) {
        $fullPath = __DIR__ . '/../' . ltrim(str_replace('\\', '/', $status['photo_path']), '/');
        if (is_file($fullPath)) {
            $refPhotoBase64 = 'data:image/jpeg;base64,' . base64_encode((string)file_get_contents($fullPath));
        }
    }

    jsonResponse([
        'success'         => true,
        'user_id'         => (int)$user['id'],
        'has_reference'   => $status['has_reference'] && ($refPhotoBase64 !== null),
        'reference_photo' => $refPhotoBase64,
        'updated_at'      => $status['updated_at']
    ]);
}

/**
 * Simpan foto acuan biometrik awal (Hands-Free Zero-Click Capture)
 * POST /api.php?action=proctoring.save_reference
 */
function proctoring_save_reference(): void {
    $user = requireAuth();
    $body = getJsonBody();

    $photo = $body['photo'] ?? '';
    if (empty($photo)) {
        jsonError('Data foto acuan kamera tidak ditemukan', 400);
    }

    $res = saveExamReferencePhoto((int)$user['id'], $photo);
    if (!$res['success']) {
        jsonError($res['message'] ?? 'Gagal menyimpan foto acuan', 400);
    }

    jsonResponse([
        'success' => true,
        'message' => $res['message'] ?? 'Foto acuan berhasil disimpan',
        'has_reference' => true
    ]);
}

/**
 * Reset foto acuan ujian mahasiswa oleh Admin atau Pengajar
 * POST /api.php?action=proctoring.reset_reference
 */
function proctoring_reset_reference(): void {
    $user = requireAuth();
    if (!in_array($user['role'] ?? '', ['admin', 'teacher'], true)) {
        jsonError('Hanya Admin atau Pengajar yang berhak mereset foto acuan ujian.', 403);
    }

    $body = getJsonBody();
    $targetUserId = (int)($body['user_id'] ?? 0);
    if ($targetUserId <= 0) {
        jsonError('ID mahasiswa tidak valid', 400);
    }

    // Jika pengajar, pastikan mahasiswa tergabung di salah satu kelas pengajar tersebut
    if (($user['role'] ?? '') === 'teacher') {
        $isMyStudent = DB::one(
            "SELECT cm.id FROM class_members cm
             JOIN classes c ON c.id = cm.class_id
             WHERE cm.user_id = ? AND c.teacher_id = ? AND c.is_active = 1
             LIMIT 1",
            [$targetUserId, $user['id']]
        );
        if (!$isMyStudent) {
            jsonError('Mahasiswa ini tidak terdaftar di kelas Anda', 403);
        }
    }

    $resetOk = resetExamReferencePhoto($targetUserId);
    if (!$resetOk) {
        jsonError('Pengguna tidak ditemukan atau gagal mereset foto acuan', 404);
    }

    jsonResponse([
        'success' => true,
        'message' => 'Foto acuan ujian mahasiswa berhasil direset. Mahasiswa dapat memindai ulang wajahnya.'
    ]);
}

/**
 * Log event pengawasan AI (initial check-in, periodic, noise violation, fullscreen exit)
 * POST /api.php?action=proctoring.log_event
 */
function proctoring_log_event(): void {
    $user = requireAuth();
    $body = getJsonBody();

    ensureProctoringTables();

    $assignmentId = (int)($body['assignment_id'] ?? 0);
    $attemptId    = !empty($body['attempt_id']) ? (int)$body['attempt_id'] : null;
    $eventType    = sanitizeString($body['event_type'] ?? 'periodic_face');
    $status       = sanitizeString($body['status'] ?? 'OK');
    $simScore     = isset($body['similarity_score']) ? (float)$body['similarity_score'] : null;
    $notes        = sanitizeString($body['notes'] ?? '');
    $snapshotB64  = $body['snapshot'] ?? '';

    $snapshotRelPath = null;
    if (!empty($snapshotB64)) {
        if (strpos($snapshotB64, ',') !== false) {
            @list(, $snapshotB64) = explode(',', $snapshotB64, 2);
        }
        $imgData = base64_decode($snapshotB64, true);
        if ($imgData && strlen($imgData) > 500) {
            $snapDir = __DIR__ . '/../uploads/snapshots';
            ensureProctoringProtectedDirectories();

            $snapName = 'snap_proc_' . $assignmentId . '_' . $user['id'] . '_' . time() . '_' . rand(100, 999) . '.jpg';
            $snapPath = $snapDir . '/' . $snapName;

            if (attempt_save_optimized_snapshot($imgData, $snapPath, 640, 70)) {
                $snapshotRelPath = 'uploads/snapshots/' . $snapName;
            }
        }
    }

    DB::execute(
        "INSERT INTO assignment_proctoring_logs 
         (assignment_id, attempt_id, user_id, event_type, similarity_score, status, snapshot_path, notes, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
        [$assignmentId, $attemptId, $user['id'], $eventType, $simScore, $status, $snapshotRelPath, $notes]
    );

    $logId = (int)DB::lastId();

    jsonResponse([
        'success' => true,
        'log_id'  => $logId
    ]);
}

/**
 * Swap soal saat terjadi pelanggaran suara keramaian / crowd noise
 * POST /api.php?action=proctoring.swap_question
 */
function proctoring_swap_question(): void {
    $user = requireAuth();
    $body = getJsonBody();

    $quizId            = (int)($body['quiz_id'] ?? 0);
    $assignmentId      = (int)($body['assignment_id'] ?? 0);
    $currentQId        = (int)($body['current_question_id'] ?? 0);
    $excludeIds        = array_map('intval', (array)($body['exclude_question_ids'] ?? []));
    $snapshotB64       = $body['snapshot'] ?? '';
    $notes             = sanitizeString($body['notes'] ?? 'Suara keramaian / percakapan terdeteksi');

    if ($quizId <= 0 && $assignmentId <= 0) {
        jsonError('Parameter kuis atau tugas tidak valid', 400);
    }

    ensureProctoringTables();

    // Catat log pelanggaran audio secara otomatis
    $snapshotRelPath = null;
    if (!empty($snapshotB64)) {
        if (strpos($snapshotB64, ',') !== false) {
            @list(, $snapshotB64) = explode(',', $snapshotB64, 2);
        }
        $imgData = base64_decode($snapshotB64, true);
        if ($imgData && strlen($imgData) > 500) {
            $snapDir = __DIR__ . '/../uploads/snapshots';
            ensureProctoringProtectedDirectories();

            $snapName = 'snap_noise_' . $assignmentId . '_' . $user['id'] . '_' . time() . '_' . rand(100, 999) . '.jpg';
            $snapPath = $snapDir . '/' . $snapName;

            if (attempt_save_optimized_snapshot($imgData, $snapPath, 640, 70)) {
                $snapshotRelPath = 'uploads/snapshots/' . $snapName;
            }
        }
    }

    DB::execute(
        "INSERT INTO assignment_proctoring_logs 
         (assignment_id, attempt_id, user_id, event_type, similarity_score, status, snapshot_path, notes, created_at)
         VALUES (?, NULL, ?, 'audio_violation', NULL, 'VIOLATION', ?, ?, NOW())",
        [$assignmentId, $user['id'], $snapshotRelPath, $notes]
    );

    // Kumpulkan semua pool soal dari kuis atau paket tugas
    $allQIds = [];
    if ($assignmentId > 0) {
        $pkgs = DB::all(
            "SELECT quiz_id FROM assignment_quiz_packages WHERE assignment_id = ? ORDER BY order_index ASC",
            [$assignmentId]
        );
        if (!empty($pkgs)) {
            $quizIds = array_column($pkgs, 'quiz_id');
            $inClause = implode(',', array_map('intval', $quizIds));
            $rows = DB::all("SELECT id FROM questions WHERE quiz_id IN ($inClause)");
            $allQIds = array_column($rows, 'id');
        }
    }

    if (empty($allQIds) && $quizId > 0) {
        $rows = DB::all("SELECT id FROM questions WHERE quiz_id = ?", [$quizId]);
        $allQIds = array_column($rows, 'id');
    }

    if (empty($allQIds)) {
        jsonError('Soal tidak ditemukan untuk kuis ini', 404);
    }

    // Cari soal yang belum pernah dikerjakan mahasiswa ini (tidak ada di excludeIds)
    $candidatePool = array_diff($allQIds, $excludeIds);
    $selectedQId = 0;

    if (!empty($candidatePool)) {
        $shuffled = array_values($candidatePool);
        shuffle($shuffled);
        $selectedQId = (int)$shuffled[0];
    } else {
        // Jika seluruh soal sudah masuk daftar aktif, ambil soal selain currentQId atau kocok ulang opsi
        $fallbackPool = array_diff($allQIds, [$currentQId]);
        if (!empty($fallbackPool)) {
            $shuffled = array_values($fallbackPool);
            shuffle($shuffled);
            $selectedQId = (int)$shuffled[0];
        } else {
            $selectedQId = $currentQId;
        }
    }

    // Ambil data detail soal baru
    $questionRow = DB::one(
        "SELECT id, quiz_id, question_text, type, points, order_num FROM questions WHERE id = ?",
        [$selectedQId]
    );

    if (!$questionRow) {
        jsonError('Gagal memuat soal pengganti', 500);
    }

    // Ambil opsi jawaban (A, B, C, D) dengan TIDAK membocorkan correct_option_id (P0 Security Standard)
    $options = DB::all(
        "SELECT id, question_id, option_text, order_num FROM question_options WHERE question_id = ? ORDER BY RAND()",
        [$selectedQId]
    );

    $questionRow['options'] = $options;

    jsonResponse([
        'success'      => true,
        'swapped'      => true,
        'old_id'       => $currentQId,
        'new_question' => $questionRow,
        'message'      => 'Soal telah diganti karena terdeteksi suara keramaian.'
    ]);
}

/**
 * Mengambil log pengawasan AI untuk guru/admin
 * GET /api.php?action=proctoring.get_logs&assignment_id=X
 */
function proctoring_get_logs(): void {
    $user = requireAuth();
    if (!in_array($user['role'] ?? '', ['admin', 'teacher'], true)) {
        jsonError('Akses ditolak', 403);
    }

    ensureProctoringTables();

    $assignmentId = (int)($_GET['assignment_id'] ?? 0);
    $studentId    = (int)($_GET['user_id'] ?? 0);

    if ($assignmentId <= 0 && $studentId <= 0) {
        jsonError('Parameter tugas atau mahasiswa diperlukan', 400);
    }

    $where = [];
    $params = [];

    if ($assignmentId > 0) {
        $where[] = "l.assignment_id = ?";
        $params[] = $assignmentId;
    }
    if ($studentId > 0) {
        $where[] = "l.user_id = ?";
        $params[] = $studentId;
    }

    $whereSql = implode(' AND ', $where);

    $logs = DB::all(
        "SELECT l.*, u.name as user_name, u.email as user_email
         FROM assignment_proctoring_logs l
         JOIN users u ON u.id = l.user_id
         WHERE $whereSql
         ORDER BY l.created_at DESC LIMIT 200",
        $params
    );

    jsonResponse([
        'success' => true,
        'total'   => count($logs),
        'logs'    => $logs
    ]);
}

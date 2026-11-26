<?php
// ============================================
// api/proctoring.php — AI Proctoring Controller
// ============================================

require_once __DIR__ . '/../includes/proctoring.php';

/** POST: return only the authenticated user's canonical reference for an already verified session.
 * The protected upload path is never exposed and another user's ID is never accepted. */
function proctoring_local_reference(): void {
    $user = requireAuth();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    $body = getJsonBody();
    $key = proctoringSessionKey((string)($body['session'] ?? ''), $user);
    $session = $_SESSION['proctoring_v2'][$key];
    proctoringScope($user, (int)$session['quiz_id'], (int)$session['assignment_id']);
    if ($session['stage'] !== 'ready' || empty($session['verified_at']) || time() - $session['verified_at'] > 60) {
        jsonError('Selesaikan verifikasi wajah awal sebelum memuat pemeriksaan lokal.', 409);
    }
    $reference = proctoringReference((int)$user['id']);
    if (!$reference['binary'] || $reference['version'] !== $session['reference_version']) {
        jsonError('Foto acuan berubah. Mulai verifikasi wajah ulang.', 409);
    }
    jsonSuccess([
        'user_id' => (int)$user['id'],
        'reference_version' => $reference['version'],
        'photo' => 'data:image/jpeg;base64,'.base64_encode($reference['binary']),
        'model' => 'opencv-yunet-sface-v1',
    ]);
}

/**
 * Cek status foto acuan ujian pengguna
 * GET /api.php?action=proctoring.check_reference
 */
function proctoring_check_reference(): void {
    $user = requireAuth();
    $status = getUserExamReference((int)$user['id']);

    jsonSuccess([
        'success'         => true,
        'user_id'         => (int)$user['id'],
        'has_reference'   => $status['has_reference'],
        'protocol'       => 2,
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

    try { $binary=proctoringImage((string)$photo); }
    catch (RuntimeException $e) { jsonError($e->getMessage(),400); }
    $quality=proctoringRuntime(['frame'=>base64_encode($binary)]);
    if (($quality['status']??'')==='UNAVAILABLE') jsonError('Layanan verifikasi kamera belum siap. Hubungi pengajar; foto belum disimpan.',503);
    if (($quality['status']??'')!=='FACE_PRESENT' || abs($quality['roll']??1)>0.12) jsonError('Pastikan satu wajah terlihat jelas dan kepala tegak untuk foto acuan.',422);

    $res = saveExamReferencePhoto((int)$user['id'], $photo);
    if (!$res['success']) {
        jsonError($res['message'] ?? 'Gagal menyimpan foto acuan', 400);
    }

    jsonSuccess([
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
    $user = requirePengajar();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed',405);
    $body = getJsonBody();
    $targetUserId = (int)($body['user_id'] ?? 0);
    $classId = (int)($body['class_id'] ?? 0);
    if ($targetUserId <= 0) jsonError('ID mahasiswa tidak valid',400);

    if (($user['role'] ?? '') === 'pengajar') {
        if ($classId <= 0) jsonError('Pilih kelas mahasiswa yang akan direset.',400);
        $isMyStudent = DB::one(
            "SELECT cm.id FROM class_members cm
             JOIN classes c ON c.id = cm.class_id
             JOIN users u ON u.id = cm.user_id
             WHERE cm.user_id = ? AND c.id = ? AND c.teacher_id = ? AND c.is_active = 1
               AND u.role IN ('user','pelajar')
             LIMIT 1",
            [$targetUserId,$classId,$user['id']]
        );
        if (!$isMyStudent) jsonError('Mahasiswa ini bukan siswa di kelas aktif yang Anda kelola.',403);
    }

    $resetOk = resetExamReferencePhoto($targetUserId);
    if (!$resetOk) {
        jsonError('Pengguna tidak ditemukan atau gagal mereset foto acuan', 404);
    }

    jsonSuccess([
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

    // Browser reports are not server identity verdicts and never create a receipt.
    $scope=proctoringScope($user,(int)($body['quiz_id']??0),$assignmentId);
    if ($attemptId && !DB::one('SELECT id FROM attempts WHERE id = ? AND user_id = ?',[$attemptId,$user['id']])) jsonError('Attempt tidak valid.',403);
    $status='CLIENT_REPORTED'; $simScore=null;
    if (!in_array($eventType,['initial_checkin','periodic_face','fullscreen_violation','tab_switch_violation','camera_error'],true)) jsonError('Jenis laporan tidak valid.',400);

    DB::execute(
        "INSERT INTO assignment_proctoring_logs 
         (assignment_id, attempt_id, user_id, event_type, similarity_score, status, notes, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
        [$assignmentId, $attemptId, $user['id'], $eventType, $simScore, $status, $notes]
    );

    $logId = (int)DB::lastId();

    jsonSuccess([
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
    $notes             = sanitizeString($body['notes'] ?? 'Suara keramaian / percakapan terdeteksi');

    if ($quizId <= 0 && $assignmentId <= 0) {
        jsonError('Parameter kuis atau tugas tidak valid', 400);
    }

    ensureProctoringTables();

    proctoringScope($user,$quizId,$assignmentId);

    DB::execute(
        "INSERT INTO assignment_proctoring_logs 
         (assignment_id, attempt_id, user_id, event_type, similarity_score, status, notes, created_at)
         VALUES (?, NULL, ?, 'audio_violation', NULL, 'VIOLATION', ?, NOW())",
        [$assignmentId, $user['id'], $notes]
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
        "SELECT id, question_id, option_text, order_num FROM options WHERE question_id = ? ORDER BY RAND()",
        [$selectedQId]
    );

    $questionRow['options'] = $options;

    jsonSuccess([
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
    if (($user['role']??'')==='teacher') {
        $where[]='EXISTS (SELECT 1 FROM assignments a WHERE a.id = l.assignment_id AND a.teacher_id = ?)';
        $params[]=$user['id'];
    }

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

    jsonSuccess([
        'success' => true,
        'total'   => count($logs),
        'logs'    => $logs
    ]);
}

/** Open an authenticated, assignment-scoped server verification challenge. */
function proctoring_begin(): void {
    $user=requireAuth();
    if ($_SERVER['REQUEST_METHOD']!=='POST') jsonError('Method not allowed',405);
    $body=getJsonBody();
    $scope=proctoringScope($user,(int)($body['quiz_id']??0),(int)($body['assignment_id']??0));
    if (!$scope['required']) jsonError('Kuis ini tidak memerlukan sesi kamera.',400);
    $reference=proctoringReference((int)$user['id']);
    if (!$reference['binary']) jsonError('Foto acuan belum tersedia. Lakukan pemindaian terlebih dahulu.',409);
    $background=($body['entry_mode']??'')==='background';
    if ($background && !proctoringUsesVps()) jsonError('Pemeriksaan latar belakang memerlukan layanan VPS.',503);
    if (!$background && (proctoringRuntime([],true)['status']??'')!=='READY') jsonError('Layanan verifikasi kamera belum siap. Hubungi pengajar.',503);
    jsonSuccess(array_merge(['success'=>true,'protocol'=>2],proctoringCreateSession($user,$scope,$reference['version'],$background)));
}

/** The only endpoint permitted to issue a server-verified camera lease. */
function proctoring_verify(): void {
    $user=requireAuth();
    if ($_SERVER['REQUEST_METHOD']!=='POST') jsonError('Method not allowed',405);
    $body=getJsonBody();
    $key=proctoringSessionKey((string)($body['session']??''),$user);
    $session=&$_SESSION['proctoring_v2'][$key];
    if (!is_string($body['nonce']??null) || !hash_equals($session['nonce'],$body['nonce'])) jsonError('Tantangan kamera sudah berubah. Verifikasi ulang.',409);
    if (microtime(true)-($session['last_request']??0)<0.7) jsonError('Tunggu sebentar sebelum verifikasi kamera berikutnya.',429);
    $session['last_request']=microtime(true);
    proctoringScope($user,(int)$session['quiz_id'],(int)$session['assignment_id']);
    $reference=proctoringReference((int)$user['id']);
    if (!$reference['binary'] || $reference['version']!==$session['reference_version']) jsonError('Foto acuan berubah atau tidak tersedia. Mulai verifikasi ulang.',409);
    try { $binary=proctoringImage((string)($body['snapshot']??'')); }
    catch (RuntimeException $e) { $session['verified_at']=0;$session['final_verified_at']=0;jsonError($e->getMessage(),400); }
    $input=['frame'=>base64_encode($binary),'reference'=>base64_encode($reference['binary'])];
    $hash=hash('sha256',$binary);
    if (($session['engine_protocol']??'')==='pose-burst-v2' && $session['stage']!=='ready') {
        if (time()-$session['started_at']>90) jsonError('Tantangan gerakan kedaluwarsa. Sambungkan dan verifikasi ulang.',409);
        $frames=$body['frames']??null;
        if (!is_array($frames) || count($frames)!==9) jsonError('Ikuti tiga langkah gerakan kamera sebelum melanjutkan.',409);
        $input['frames']=[];$hashes=[];
        try { foreach ($frames as $frame) { if (!is_string($frame)) throw new RuntimeException('Foto tantangan tidak valid.'); $image=proctoringImage($frame); if (strlen($frame)>700000) throw new RuntimeException('Foto tantangan terlalu besar.');$input['frames'][]=base64_encode($image);$hashes[]=hash('sha256',$image); } }
        catch (RuntimeException $e) { jsonError($e->getMessage(),400); }
        if (count(array_unique($hashes))!==9) jsonError('Foto tantangan berulang. Ulangi dari kamera aktif.',400);
        $input['direction']=$session['direction'];$hash=hash('sha256',implode(':',$hashes));
    }
    $result=proctoringRuntime($input);
    $status=proctoringAdvance($session,$result,$hash,time(),($body['purpose']??'')==='final');
    $messages=[
        'MOTION_REQUIRED'=>'Gerakan belum terbaca. Ulangi posisi lurus, menoleh sesuai petunjuk, lalu kembali lurus.',
        'TILT_HEAD'=>'Miringkan kepala sedikit ke samping untuk pemeriksaan gerakan.',
        'CENTER_HEAD'=>'Sekarang tegakkan kembali kepala dan lihat ke kamera.',
        'VERIFIED'=>'Kamera berhasil diverifikasi terhadap foto acuan.',
        'NO_FACE'=>'Wajah belum terdeteksi. Hadapkan wajah ke kamera.',
        'MULTIPLE_FACES'=>'Pastikan hanya satu wajah berada dalam kamera.',
        'LOW_QUALITY'=>'Perbaiki pencahayaan, jarak, atau ketajaman kamera.',
        'RECHECK'=>'Kemiripan belum cukup. Coba posisi dan pencahayaan yang sama dengan foto acuan; hubungi pengajar jika berulang.',
        'REFERENCE_INVALID'=>'Foto acuan tidak dapat dipakai. Hubungi pengajar untuk pemeriksaan atau reset acuan.',
        'FRAME_REPLAY'=>'Frame kamera tidak berubah. Pastikan kamera aktif, lalu gerakkan kepala sedikit.',
        'UNAVAILABLE'=>'Layanan pemeriksa kamera sedang tidak tersedia. Coba kembali atau hubungi pengajar.',
        'SERVER_BUSY'=>'Pemeriksa kamera sedang penuh. Tunggu sebentar; pemeriksaan akan dicoba kembali.',
    ];
    // Log only transitions (or once each 30s); identity status is computed here, never supplied by the browser.
    if (($session['last_log_status']??null)!==$status || time()-($session['last_log_at']??0)>30) {
        ensureProctoringTables();
        DB::execute('INSERT INTO assignment_proctoring_logs (assignment_id,user_id,event_type,similarity_score,status,notes,created_at) VALUES (?,?,?,?,?,?,NOW())',[$session['assignment_id'],$user['id'],'server_face',isset($result['cosine'])?(float)$result['cosine']:null,$status,'YuNet/SFace cosine; similarity is not an identity probability.']);
        $session['last_log_status']=$status;$session['last_log_at']=time();
    }
    jsonSuccess(['success'=>true,'status'=>$status,'verified'=>$status==='VERIFIED','next_nonce'=>$session['nonce'],'message'=>$messages[$status]??'Verifikasi kamera belum berhasil. Coba kembali atau hubungi pengajar.']);
}

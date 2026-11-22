<?php
require_once __DIR__.'/proctoring-remote.php';
// Server-authoritative camera receipts. Browser scores never authorize a submission.
function proctoringRuntime(array $input = [], bool $health = false): array {
    if (proctoringUsesVps()) return proctoringRemote($input,$health);
    $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: dirname(__DIR__, 3));
    $configFile = __DIR__ . '/../config/proctoring.php';
    $config = is_file($configFile) ? require $configFile : [];
    $python = $config['python'] ?? (getenv('QUIZB_PROCTORING_PYTHON') ?: ($home . '/.local/share/quizb-proctoring/venv/'.(PHP_OS_FAMILY==='Windows'?'Scripts/python.exe':'bin/python')));
    $models = $config['models'] ?? (getenv('QUIZB_FACE_MODELS') ?: ($home . '/.local/share/quizb-proctoring/models'));
    if (!function_exists('proc_open') || !is_file($python)) return ['status'=>'UNAVAILABLE'];
    $lockDir=dirname($models).'/locks';
    if (!is_dir($lockDir) && !@mkdir($lockDir,0700,true)) return ['status'=>'UNAVAILABLE'];
    $lock=null;
    for ($slot=0;$slot<2;$slot++) {
        $candidate=@fopen($lockDir.'/worker-'.$slot.'.lock','c');
        if ($candidate && flock($candidate,LOCK_EX|LOCK_NB)) { $lock=$candidate;break; }
        if ($candidate) fclose($candidate);
    }
    if (!$lock) return ['status'=>'SERVER_BUSY'];
    $command = [$python, __DIR__ . '/../bin/face-verify.py'];
    if ($health) $command[] = '--health';
    $environment = array_merge(getenv(), ['QUIZB_FACE_MODELS'=>$models, 'OPENBLAS_NUM_THREADS'=>'1', 'OMP_NUM_THREADS'=>'1','OPENCV_IO_MAX_IMAGE_PIXELS'=>'4000000']);
    $process = @proc_open($command, [['pipe','r'],['pipe','w'],['pipe','w']], $pipes, null, $environment);
    if (!is_resource($process)) { fclose($lock);return ['status'=>'UNAVAILABLE']; }
    $payload = json_encode($input);
    $offset=0;
    while ($offset < strlen($payload)) {
        $written = @fwrite($pipes[0], substr($payload,$offset,65536));
        if (!$written) break;
        $offset += $written;
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1],false); stream_set_blocking($pipes[2],false);
    $output=''; $deadline=microtime(true)+12;
    do {
        $output .= stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]); // Drain diagnostics without disclosing images or configuration.
        $state=proc_get_status($process);
        if (strlen($output)>8192 || microtime(true)>$deadline) { proc_terminate($process); $output=''; break; }
        if (!$state['running']) break;
        usleep(20000);
    } while (true);
    $output .= stream_get_contents($pipes[1]);
    fclose($pipes[1]); fclose($pipes[2]); $exitCode=proc_close($process);fclose($lock);
    if (($state['exitcode']??-1)!==0 && $exitCode!==0) return ['status'=>'UNAVAILABLE'];
    $result=json_decode($output,true);
    return is_array($result) && isset($result['status']) ? $result : ['status'=>'UNAVAILABLE'];
}

function proctoringImage(string $photo): string {
    if (strlen($photo)>2800000) throw new RuntimeException('Foto kamera terlalu besar.');
    $encoded = strpos($photo,',') !== false ? explode(',',$photo,2)[1] : $photo;
    $binary=base64_decode($encoded,true);
    if ($binary===false || strlen($binary)<500 || strlen($binary)>2000000) throw new RuntimeException('Foto kamera tidak valid.');
    $info=@getimagesizefromstring($binary);
    if (!$info || !in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true) || min($info[0],$info[1])<160 || $info[0]*$info[1]>4000000) throw new RuntimeException('Resolusi atau format foto kamera tidak valid.');
    return $binary;
}

function proctoringScope(array $user, int $quizId, int $assignmentId): array {
    $quiz=DB::one('SELECT id, require_camera FROM quizzes WHERE id = ?',[$quizId]);
    if (!$quiz) jsonError('Kuis tidak ditemukan.',404);
    $required=!empty($quiz['require_camera']);
    if ($assignmentId>0) {
        $assignment=DB::one('SELECT * FROM assignments WHERE id = ? AND is_active = 1',[$assignmentId]);
        if (!$assignment) jsonError('Tugas tidak aktif.',404);
        $member=DB::one('SELECT id FROM class_members WHERE class_id = ? AND user_id = ?',[$assignment['class_id'],$user['id']]);
        if (!$member && ($user['role']??'')!=='admin' && (int)$assignment['teacher_id']!==(int)$user['id']) jsonError('Anda bukan peserta tugas ini.',403);
        $package=(int)$assignment['quiz_id']===$quizId || DB::one('SELECT quiz_id FROM assignment_quiz_packages WHERE assignment_id = ? AND quiz_id = ?',[$assignmentId,$quizId]);
        if (!$package) jsonError('Kuis bukan bagian tugas ini.',403);
        if (!empty($assignment['deadline']) && strtotime($assignment['deadline'])<time()) jsonError('Batas waktu tugas sudah berakhir.',409);
        $required=$required || !empty($assignment['require_camera']);
    }
    return ['required'=>$required,'quiz_id'=>$quizId,'assignment_id'=>$assignmentId];
}

function proctoringReference(int $userId): array {
    $reference=getUserExamReference($userId);
    if (!$reference['has_reference']) return ['binary'=>null,'version'=>null];
    $root=realpath(__DIR__.'/../uploads/references');
    $file=realpath(__DIR__.'/../'.$reference['photo_path']);
    if (!$root || !$file || strpos($file,$root.DIRECTORY_SEPARATOR)!==0 || filesize($file)>2000000) return ['binary'=>null,'version'=>null];
    $binary=file_get_contents($file);
    return ['binary'=>$binary,'version'=>hash('sha256',$binary)];
}

function proctoringSessionKey(string $token, array $user): string {
    if (!preg_match('/^[a-f0-9]{64}$/D',$token)) jsonError('Sesi kamera tidak valid. Verifikasi ulang kamera.',409);
    $key=hash('sha256',$token);
    $session=$_SESSION['proctoring_v2'][$key]??null;
    if (!$session || (int)$session['user_id']!==(int)$user['id'] || time()-$session['started_at']>21600 || !empty($session['attempt_id'])) jsonError('Sesi kamera kedaluwarsa atau telah digunakan. Verifikasi ulang.',409);
    return $key;
}

function proctoringCreateSession(array $user, array $scope, string $referenceVersion): array {
    $carry=[];
    foreach (($_SESSION['proctoring_v2']??[]) as $key=>$session) {
        if ((int)$session['user_id']===(int)$user['id'] && (int)$session['quiz_id']===$scope['quiz_id'] && (int)$session['assignment_id']===$scope['assignment_id'] && empty($session['attempt_id'])) {
            $carry=array_intersect_key($session,array_flip(['questions_released_at','last_good_at','monitoring_gaps','review_required']));
            unset($_SESSION['proctoring_v2'][$key]);
        }
        if (time()-($session['started_at']??0)>21600 || !empty($session['attempt_id'])) unset($_SESSION['proctoring_v2'][$key]);
    }
    if (count($_SESSION['proctoring_v2']??[])>=8) jsonError('Terlalu banyak sesi ujian terbuka. Tutup sesi lama atau masuk ulang.',409);
    $token=bin2hex(random_bytes(32));$nonce=bin2hex(random_bytes(32));
    $key=hash('sha256',$token);
    $_SESSION['proctoring_v2'][$key]=array_merge($scope,[
        'user_id'=>(int)$user['id'],'reference_version'=>$referenceVersion,'started_at'=>time(),
        'engine_protocol'=>proctoringUsesVps()?'pose-burst-v2':'local-roll-v1','direction'=>random_int(0,1)?'left':'right',
        'stage'=>'tilt','nonce'=>$nonce,'verified_at'=>0,'final_verified_at'=>0,'sample_count'=>0,'frames'=>[],
    ],$carry);
    $_SESSION['proctoring_current'][$user['id'].':'.$scope['quiz_id'].':'.$scope['assignment_id']]=$key;
    return ['session'=>$token,'nonce'=>$nonce,'capture_protocol'=>$_SESSION['proctoring_v2'][$key]['engine_protocol'],'direction'=>$_SESSION['proctoring_v2'][$key]['direction'],'status'=>'TILT_HEAD','message'=>'Miringkan kepala sedikit ke samping, lalu ikuti petunjuk kamera.'];
}

function proctoringAdvance(array &$session, array $result, string $frameHash, int $now, bool $final): string {
    if (!empty($session['questions_released_at']) && $now-($session['last_good_at']??$session['questions_released_at'])>30) {
        $session['review_required']=true;
        $session['monitoring_gaps']=($session['monitoring_gaps']??0)+1;
    }
    // Every attempt consumes its nonce, including a rejected frame.
    $session['nonce']=bin2hex(random_bytes(32));
    $session['final_verified_at']=0;
    $session['verified_at']=0;
    if (in_array($frameHash,$session['frames'],true)) return 'FRAME_REPLAY';
    $session['frames'][]=$frameHash;$session['frames']=array_slice($session['frames'],-12);
    $vps=($session['engine_protocol']??'')==='pose-burst-v2';
    if (($result['status']??'')!=='MATCH') {
        if (!$vps && !in_array($result['status']??'',['UNAVAILABLE','SERVER_BUSY'],true)) $session['stage']='tilt';
        return $result['status']??'UNAVAILABLE';
    }
    if ($vps && ($result['engine']??'')!=='opencv-sface-v1') return 'INVALID_FEATURE';
    $roll=$result['roll']??null;$score=$result['cosine']??null;
    if (!is_numeric($roll) || !is_finite((float)$roll) || !is_numeric($score) || !is_finite((float)$score) || $score<($vps?0.45:0.50) || $score>1.001) return 'INVALID_FEATURE';
    if ($vps && $session['stage']!=='ready') {
        if (($result['engine']??'')!=='opencv-sface-v1' || ($result['motion_passed']??false)!==true || $now-$session['started_at']<3) return 'MOTION_REQUIRED';
        $session['stage']='ready';
    }
    if ($session['stage']==='tilt') {
        if (abs($roll)<0.12) return 'TILT_HEAD';
        $session['stage']='center';$session['tilted_at']=$now;
        return 'CENTER_HEAD';
    }
    if ($session['stage']==='center') {
        if (abs($roll)>0.09 || $now<=($session['tilted_at']??$now)) return 'CENTER_HEAD';
        $session['stage']='ready';
    }
    $session['verified_at']=$now;
    $session['last_good_at']=$now;
    $session['sample_count']++;
    if ($final) $session['final_verified_at']=$now;
    return 'VERIFIED';
}

function proctoringRequireReceipt(array $user, array $quiz, int $assignmentId, array $body): ?string {
    $scope=proctoringScope($user,(int)$quiz['id'],$assignmentId);
    if (!$scope['required']) return null;
    $key=proctoringSessionKey((string)($body['proctoring_session']??''),$user);
    $session=$_SESSION['proctoring_v2'][$key];
    $reference=proctoringReference((int)$user['id']);
    if ((int)$session['quiz_id']!==(int)$quiz['id'] || (int)$session['assignment_id']!==$assignmentId || $session['reference_version']!==$reference['version'] || $session['stage']!=='ready' || empty($session['questions_released_at']) || $session['sample_count']<2 || time()-$session['final_verified_at']>15 || $session['final_verified_at']<=0) jsonError('Verifikasi kamera belum lengkap atau sudah kedaluwarsa. Verifikasi ulang sebelum mengumpulkan.',409);
    return $key;
}

function proctoringReceiptTable(): void {
    DB::execute('CREATE TABLE IF NOT EXISTS proctoring_receipts (
        attempt_id INT PRIMARY KEY, user_id INT NOT NULL, quiz_id INT NOT NULL, assignment_id INT NOT NULL,
        verified_at DATETIME NOT NULL, model_version VARCHAR(80) NOT NULL,
        review_required TINYINT NOT NULL DEFAULT 0, monitoring_gaps INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

function proctoringBindReceipt(string $key, int $attemptId): void {
    $s=$_SESSION['proctoring_v2'][$key];
    DB::execute('INSERT INTO proctoring_receipts (attempt_id,user_id,quiz_id,assignment_id,verified_at,model_version,review_required,monitoring_gaps) VALUES (?,?,?,?,FROM_UNIXTIME(?),?,?,?)',[$attemptId,$s['user_id'],$s['quiz_id'],$s['assignment_id'],$s['final_verified_at'],($s['engine_protocol']??'')==='pose-burst-v2'?'opencv-sface-v1':'yunet-2023mar-sface-2021dec-v1',(int)($s['review_required']??false),(int)($s['monitoring_gaps']??0)]);
    $_SESSION['proctoring_v2'][$key]['attempt_id']=$attemptId;
}

/** Never deliver protected questions until this browser/session has passed server verification. */
function proctoringReleaseQuestions(array $user, int $quizId, int $assignmentId, bool $preview): bool {
    proctoringScope($user,$quizId,$assignmentId);
    if ($preview) return false;
    $key=$_SESSION['proctoring_current'][$user['id'].':'.$quizId.':'.$assignmentId]??'';
    $session=$_SESSION['proctoring_v2'][$key]??null;
    if (!$session || (int)$session['user_id']!==(int)$user['id'] || !empty($session['attempt_id']) || $session['stage']!=='ready' || empty($session['verified_at']) || time()-$session['verified_at']>20 || time()-$session['started_at']>21600) return false;
    $reference=proctoringReference((int)$user['id']);
    if ($reference['version']!==$session['reference_version']) return false;
    if (empty($_SESSION['proctoring_v2'][$key]['questions_released_at'])) $_SESSION['proctoring_v2'][$key]['questions_released_at']=time();
    return true;
}

<?php
// Persistent outbox. Network I/O runs only in the CLI worker, never in user requests.
function ensurePushQueue(): void {
    static $ready = false;
    if ($ready) return;
    if (DB::conn()->inTransaction()) throw new RuntimeException('Initialize push schema before starting a transaction');
    DB::execute("CREATE TABLE IF NOT EXISTS notification_push_jobs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
        notification_id BIGINT UNSIGNED NULL, payload MEDIUMTEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending', attempts INT NOT NULL DEFAULT 0,
        available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, locked_at DATETIME NULL,
        last_error VARCHAR(100) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_push_due (status, available_at), UNIQUE KEY uq_push_notification (notification_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    DB::execute("CREATE TABLE IF NOT EXISTS notification_push_deliveries (
        job_id BIGINT UNSIGNED NOT NULL, token_hash CHAR(64) NOT NULL,
        status VARCHAR(20) NOT NULL, last_error VARCHAR(100) NULL,
        PRIMARY KEY (job_id, token_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ready = true;
}

function enqueuePush(int $userId, string $title, string $body, array $data, ?int $notificationId = null): void {
    $data['recipient_user_id'] = (string)$userId;
    if ($notificationId) $data['notification_id'] = (string)$notificationId;
    $payload = json_encode(compact('title', 'body', 'data'), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    DB::execute('INSERT INTO notification_push_jobs (user_id, notification_id, payload) VALUES (?, ?, ?)', [$userId, $notificationId, $payload]);
}

// Compatibility entry point: callers now enqueue instead of blocking on Firebase.
function sendFcmNotification(int|array $userIds, string $title, string $body = '', array $data = []): void {
    ensurePushQueue();
    foreach (array_unique(array_filter(array_map('intval', is_array($userIds) ? $userIds : [$userIds]))) as $id) {
        enqueuePush($id, $title, $body, $data);
    }
}

function pushDeviceTokens(int $userId): array {
    $tokens = [];
    $hasTable = true;
    try {
        $rows = DB::all("SELECT t.token FROM user_fcm_tokens t JOIN users u ON u.id = t.user_id
            WHERE t.user_id = ? AND u.is_active = 1 AND NOT EXISTS (
                SELECT 1 FROM user_fcm_tokens newer WHERE newer.token = t.token
                AND (newer.updated_at > t.updated_at OR (newer.updated_at = t.updated_at AND newer.id > t.id)))", [$userId]);
        foreach ($rows as $row) $tokens[] = $row['token'];
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) !== 1146) throw $e;
        $hasTable = false;
    }
    // Migrate legacy registrations conservatively: ambiguous/shared tokens are never targeted.
    try {
        $rows = DB::all("SELECT u.fcm_token AS token FROM users u WHERE u.id = ? AND u.is_active = 1
            AND u.fcm_token IS NOT NULL AND u.fcm_token != ''
            AND NOT EXISTS (SELECT 1 FROM users other WHERE other.id != u.id AND other.fcm_token = u.fcm_token)" .
            ($hasTable ? " AND NOT EXISTS (SELECT 1 FROM user_fcm_tokens t WHERE t.token = u.fcm_token)" : ''), [$userId]);
        foreach ($rows as $row) $tokens[] = $row['token'];
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) !== 1054) throw $e;
    }
    return array_values(array_unique(array_filter($tokens)));
}

function pushConfiguration(): array {
    $path = __DIR__ . '/../config/notifications.php';
    $config = is_file($path) ? require $path : [];
    if (!is_array($config)) throw new RuntimeException('Invalid notification configuration');
    return ['credentials' => getenv('QUIZB_FIREBASE_CREDENTIALS') ?: ($config['credentials'] ?? __DIR__ . '/../config/firebase-service-account.json')];
}

function fcmClassifyResponse(int $httpStatus, array $response, int $retryAfter = 0): array {
    $errorCode = (string)($response['error']['status'] ?? 'HTTP_' . $httpStatus);
    foreach ($response['error']['details'] ?? [] as $detail) {
        if (($detail['@type'] ?? '') === 'type.googleapis.com/google.firebase.fcm.v1.FcmError') $errorCode = $detail['errorCode'] ?? $errorCode;
    }
    $ok = $httpStatus >= 200 && $httpStatus < 300 && !empty($response['name']);
    $invalid = $errorCode === 'UNREGISTERED';
    $retry = !$ok && !$invalid && ($httpStatus === 0 || $httpStatus === 429 || $httpStatus >= 500 || in_array($httpStatus, [401, 403], true));
    return ['ok'=>$ok, 'invalid'=>$invalid, 'retry'=>$retry, 'code'=>$ok ? 'SENT' : preg_replace('/[^A-Z0-9_]/i', '', substr($errorCode, 0, 80)), 'retry_after'=>max(0, $retryAfter)];
}

function deliverFcmV1(string $token, array $payload): array {
    if (!extension_loaded('curl') || !extension_loaded('openssl')) return ['ok'=>false,'retry'=>true,'invalid'=>false,'code'=>'MISSING_PHP_EXTENSION'];
    $credentials = pushConfiguration()['credentials'];
    if (!is_file($credentials)) return ['ok'=>false,'retry'=>true,'invalid'=>false,'code'=>'CREDENTIALS_MISSING'];
    $account = json_decode(file_get_contents($credentials), true);
    $projectId = $account['project_id'] ?? '';
    if (!is_string($projectId) || !preg_match('/^[a-z0-9][a-z0-9-]+$/', $projectId)) return ['ok'=>false,'retry'=>false,'invalid'=>false,'code'=>'INVALID_PROJECT'];
    $access = getGoogleFcmV1AccessToken($credentials);
    if (!$access) return ['ok'=>false,'retry'=>true,'invalid'=>false,'code'=>'OAUTH_FAILED'];
    $data = [];
    foreach ($payload['data'] ?? [] as $key=>$value) $data[(string)$key] = is_scalar($value) ? (string)$value : json_encode($value);
    $message = ['token'=>$token, 'notification'=>['title'=>$payload['title'], 'body'=>$payload['body']], 'data'=>$data, 'android'=>['priority'=>'HIGH']];
    if (!empty($data['notification_id'])) $message['android']['notification'] = ['tag'=>'quizb_' . $data['notification_id']];
    $retryAfter = 0;
    $ch = curl_init('https://fcm.googleapis.com/v1/projects/' . $projectId . '/messages:send');
    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode(['message'=>$message], JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER=>['Authorization: Bearer ' . $access, 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_TIMEOUT=>5,
        CURLOPT_HEADERFUNCTION=>function($curl, $header) use (&$retryAfter) {
            if (stripos($header, 'Retry-After:') === 0) {
                $value = trim(substr($header, 12));
                $retryAfter = ctype_digit($value) ? (int)$value : max(0, (strtotime($value) ?: time()) - time());
            }
            return strlen($header);
        }]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $result = fcmClassifyResponse($raw === false ? 0 : $status, json_decode($raw ?: '{}', true) ?: [], $retryAfter);
    if ($status === 401) getGoogleFcmV1AccessToken($credentials, true); // Refresh for the scheduled retry.
    return $result;
}

function processPushQueue(int $limit = 50, int $seconds = 45, ?callable $transport = null): array {
    ensurePushQueue();
    $summary = ['sent'=>0,'skipped'=>0,'retry'=>0,'failed'=>0,'busy'=>false];
    $lock = 'quizb_push_' . substr(hash('sha256', __DIR__), 0, 40);
    if ((int)(DB::one('SELECT GET_LOCK(?, 0) AS acquired', [$lock])['acquired'] ?? 0) !== 1) { $summary['busy']=true; return $summary; }
    $deadline = microtime(true) + max(1, $seconds);
    $transport ??= 'deliverFcmV1';
    try {
        DB::execute("UPDATE notification_push_jobs SET status = 'pending', locked_at = NULL WHERE status = 'processing' AND locked_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
        for ($i=0; $i<max(1,$limit) && microtime(true)<$deadline; $i++) {
            $job = DB::one("SELECT *, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_seconds FROM notification_push_jobs WHERE status = 'pending' AND available_at <= NOW() ORDER BY available_at, id LIMIT 1");
            if (!$job) break;
            DB::execute("UPDATE notification_push_jobs SET status = 'processing', locked_at = NOW(), attempts = attempts + 1 WHERE id = ?", [$job['id']]);
            $retry = false; $failed = false; $sent = false; $error = null; $retryAfter = 0;
            try {
                $payload = json_decode($job['payload'], true, 512, JSON_THROW_ON_ERROR);
                if ((int)$job['age_seconds'] > 86400) { $error='EXPIRED'; }
                else foreach (pushDeviceTokens((int)$job['user_id']) as $token) {
                    if (microtime(true)>=$deadline) { $retry=true; $error='WORKER_TIME_BUDGET'; break; }
                    $hash = hash('sha256', $token);
                    $done = DB::one("SELECT status FROM notification_push_deliveries WHERE job_id = ? AND token_hash = ?", [$job['id'], $hash]);
                    if ($done && in_array($done['status'], ['sent','invalid','failed'], true)) { $sent = $sent || $done['status']==='sent'; $failed = $failed || $done['status']==='failed'; continue; }
                    $deviceLock = 'quizb_token_' . substr($hash, 0, 40);
                    if ((int)(DB::one('SELECT GET_LOCK(?, 5) AS acquired', [$deviceLock])['acquired'] ?? 0) !== 1) { $retry=true; $error='DEVICE_BUSY'; continue; }
                    try {
                        // Recheck after locking: registration/logout cannot move this token during delivery.
                        if (!in_array($token, pushDeviceTokens((int)$job['user_id']), true)) continue;
                        $result = $transport($token, $payload);
                        if (!empty($result['invalid'])) {
                            DB::execute('DELETE FROM user_fcm_tokens WHERE token = ?', [$token]);
                            DB::execute('UPDATE users SET fcm_token = NULL WHERE fcm_token = ?', [$token]);
                        }
                        if (!empty($result['retry'])) { $retry=true; $error=$result['code']; $retryAfter=max($retryAfter, (int)($result['retry_after'] ?? 0)); continue; }
                        $state = !empty($result['ok']) ? 'sent' : (!empty($result['invalid']) ? 'invalid' : 'failed');
                        $sent = $sent || $state==='sent'; $failed = $failed || $state==='failed';
                        DB::execute('INSERT INTO notification_push_deliveries (job_id, token_hash, status, last_error) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status), last_error = VALUES(last_error)', [$job['id'], $hash, $state, $result['code'] ?? null]);
                        if ($state==='failed') $error=$result['code'];
                    } finally { DB::one('SELECT RELEASE_LOCK(?) AS released', [$deviceLock]); }
                }
            } catch (Throwable $e) { $retry=true; $error='WORKER_EXCEPTION'; error_log('[Push worker] ' . get_class($e)); }
            if ($retry && (int)$job['attempts'] < 5) {
                $delay = max($retryAfter, min(3600, 60 * (2 ** (int)$job['attempts'])) + random_int(0, 30));
                DB::execute("UPDATE notification_push_jobs SET status = 'pending', available_at = TIMESTAMPADD(SECOND, ?, NOW()), last_error = ?, locked_at = NULL WHERE id = ?", [$delay, $error, $job['id']]);
                $summary['retry']++;
            } else {
                $state = ($retry || $failed) ? 'failed' : ($sent ? 'sent' : 'skipped');
                DB::execute('UPDATE notification_push_jobs SET status = ?, payload = ?, last_error = ?, locked_at = NULL WHERE id = ?', [$state, '{}', $error, $job['id']]);
                $summary[$state]++;
            }
        }
        DB::execute("DELETE d FROM notification_push_deliveries d JOIN notification_push_jobs j ON j.id = d.job_id WHERE j.status IN ('sent','skipped','failed') AND j.updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        DB::execute("DELETE FROM notification_push_jobs WHERE status IN ('sent','skipped','failed') AND updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    } finally { DB::one('SELECT RELEASE_LOCK(?) AS released', [$lock]); }
    return $summary;
}

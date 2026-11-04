<?php
// ============================================================
// includes/helpers.php — Fungsi pembantu lintas modul
// ============================================================

/**
 * Push notifikasi ke user tertentu (Database & Google FCM untuk Android).
 * Dipanggil dari challenge.php, message.php, dsb.
 */
require_once __DIR__ . '/push_queue.php';

function pushNotification(int $userId, string $type, string $title, string $body = '', string $link = '', array $extraData = []): void {
    $pdo = DB::conn();
    $ownsTransaction = !$pdo->inTransaction();
    try {
        ensurePushQueue();
        if ($ownsTransaction) $pdo->beginTransaction();
        DB::execute('INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, ?, ?, ?, ?)', [$userId, $type, $title, $body ?: null, $link ?: null]);
        $notificationId = (int)DB::lastId();
        $data = array_merge(['type'=>$type, 'link'=>$link, 'title'=>$title, 'body'=>$body], $extraData);
        enqueuePush($userId, $title, $body, $data, $notificationId);
        if ($ownsTransaction) $pdo->commit();

        // Segera kirim antrean notifikasi di latar belakang agar diterima secara realtime
        if (function_exists('fastcgi_finish_request')) {
            register_shutdown_function(function() {
                @fastcgi_finish_request();
                try {
                    processPushQueue(5, 3);
                } catch (Throwable $_) {}
            });
        }
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[Notification outbox] ' . get_class($e));
    }
}

/**
 * Dapatkan Google OAuth2 Access Token menggunakan Firebase Service Account JSON.
 */
function getGoogleFcmV1AccessToken(string $serviceAccountPath, bool $forceRefresh = false): ?string {
    if (!file_exists($serviceAccountPath)) return null;
    $raw = @file_get_contents($serviceAccountPath);
    if (!$raw) return null;
    $sa = json_decode($raw, true);
    if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) return null;

    $cacheFile = sys_get_temp_dir() . '/quizb_fcm_token_' . md5($serviceAccountPath) . '.json';
    if (!$forceRefresh && file_exists($cacheFile)) {
        $cached = json_decode(@file_get_contents($cacheFile), true);
        if (is_array($cached) && !empty($cached['token']) && !empty($cached['expires_at'])) {
            if ($cached['expires_at'] > (time() + 120)) {
                return $cached['token'];
            }
        }
    }

    $now = time();
    $b64Url = fn($data) => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    $header = $b64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claims = $b64Url(json_encode([
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600
    ]));

    $signInput = "$header.$claims";
    $signature = '';
    $privateKey = openssl_pkey_get_private($sa['private_key']);
    if (!$privateKey) return null;

    if (!openssl_sign($signInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        return null;
    }
    $jwt = "$signInput." . $b64Url($signature);

    $postData = http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt
    ]);

    if (function_exists('curl_init')) {
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $resp = curl_exec($ch);
        curl_close($ch);
    } else {
        $opts = ['http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $postData,
            'timeout' => 5
        ]];
        $resp = @file_get_contents('https://oauth2.googleapis.com/token', false, stream_context_create($opts));
    }

    if (!$resp) return null;
    $resJson = json_decode($resp, true);
    if (!is_array($resJson) || empty($resJson['access_token'])) return null;

    $token = $resJson['access_token'];
    $expiresIn = (int)($resJson['expires_in'] ?? 3600);
    $temporaryCache = $cacheFile . '.' . bin2hex(random_bytes(6));
    @file_put_contents($temporaryCache, json_encode([
        'token'      => $token,
        'expires_at' => $now + $expiresIn
    ]), LOCK_EX);
    @chmod($temporaryCache, 0600);
    if (!@rename($temporaryCache, $cacheFile)) @unlink($temporaryCache);
    return $token;
}

/**
 * Broadcast notifikasi soal baru ke semua user aktif, kecuali user tertentu (misal: admin yg nambah).
 * Dipanggil dari question_create, question_import_save, question_import_quizb.
 */
function broadcastNewQuestion(int $quizId, int $excludeUserId = 0): void {
    try {
        $quiz = DB::one('SELECT title, is_published FROM quizzes WHERE id = ?', [$quizId]);
        if (!$quiz || (int)$quiz['is_published'] !== 1) return;
        $quizTitle = $quiz['title'] ?? 'Quiz';

        $recipients = DB::all(
            "SELECT id FROM users WHERE is_active = 1 AND id != ?", [$excludeUserId]
        );
        foreach ($recipients as $rec) {
            pushNotification(
                (int)$rec['id'],
                'new_question',
                '📝 Soal baru ditambahkan',
                'Soal baru tersedia di kuis "' . $quizTitle . '".',
                '/quiz/' . $quizId
            );
        }
    } catch (Throwable $e) {
        error_log('[broadcastNewQuestion] ' . $e->getMessage());
    }
}

/**
 * Broadcast notifikasi update aplikasi ke seluruh user aktif.
 */
function broadcastAppUpdate(string $versionName = '1.0.1', string $description = 'Pembaruan aplikasi QuizB telah tersedia di Google Play Store dengan fitur Pengawasan Kamera dan Pesan Langsung.'): void {
    try {
        $recipients = DB::all("SELECT id FROM users WHERE is_active = 1");
        foreach ($recipients as $rec) {
            pushNotification(
                (int)$rec['id'],
                'app_update',
                '🚀 Pembaruan Aplikasi QuizB Versi ' . $versionName,
                $description,
                'https://play.google.com/store/apps/details?id=id.my.quizb.android'
            );
        }
    } catch (Throwable $e) {
        error_log('[broadcastAppUpdate] ' . $e->getMessage());
    }
}

<?php
// ============================================================
// includes/helpers.php — Fungsi pembantu lintas modul
// ============================================================

/**
 * Push notifikasi ke user tertentu (Database & Google FCM untuk Android).
 * Dipanggil dari challenge.php, message.php, dsb.
 */
function pushNotification(int $userId, string $type, string $title, string $body = '', string $link = '', array $extraData = []): void {
    try {
        DB::execute(
            "INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, ?, ?, ?, ?)",
            [$userId, $type, $title, $body ?: null, $link ?: null]
        );
    } catch (Throwable $e) {
        // Jangan sampai gagal notif mengganggu operasi utama
        error_log('[pushNotification DB] ' . $e->getMessage());
    }

    // Kirim notifikasi instan ke HP Android via Google Firebase Cloud Messaging (FCM)
    try {
        $fcmData = array_merge([
            'type'  => $type,
            'link'  => $link,
            'title' => $title,
            'body'  => $body
        ], $extraData);
        sendFcmNotification($userId, $title, $body, $fcmData);
    } catch (Throwable $e) {
        error_log('[pushNotification FCM] ' . $e->getMessage());
    }
}

/**
 * Dapatkan Google OAuth2 Access Token menggunakan Firebase Service Account JSON.
 */
function getGoogleFcmV1AccessToken(string $serviceAccountPath): ?string {
    if (!file_exists($serviceAccountPath)) return null;
    $raw = @file_get_contents($serviceAccountPath);
    if (!$raw) return null;
    $sa = json_decode($raw, true);
    if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) return null;

    $cacheFile = sys_get_temp_dir() . '/quizb_fcm_token_' . md5($serviceAccountPath) . '.json';
    if (file_exists($cacheFile)) {
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
    @file_put_contents($cacheFile, json_encode([
        'token'      => $token,
        'expires_at' => $now + $expiresIn
    ]));
    return $token;
}

/**
 * Kirim pesan push notifikasi ke HP Android melalui Google FCM.
 * Mendukung Firebase Cloud Messaging HTTP v1 API terbaru.
 */
function sendFcmNotification(int|array $userIds, string $title, string $body = '', array $data = []): void {
    try {
        $targetUserIds = is_array($userIds) ? $userIds : [$userIds];
        $targetUserIds = array_filter(array_map('intval', $targetUserIds));
        if (empty($targetUserIds)) return;

        $tokens = [];
        $placeholders = str_repeat('?,', count($targetUserIds) - 1) . '?';

        // 1. Ambil dari tabel user_fcm_tokens jika ada
        try {
            $tokenRows = DB::all("SELECT token FROM user_fcm_tokens WHERE user_id IN ($placeholders)", $targetUserIds);
            foreach ($tokenRows as $r) {
                if (!empty($r['token'])) {
                    $tokens[] = $r['token'];
                }
            }
        } catch (Throwable $e) {}

        // 2. Ambil dari kolom users.fcm_token jika ada
        try {
            $userRows = DB::all("SELECT fcm_token FROM users WHERE id IN ($placeholders) AND fcm_token IS NOT NULL AND fcm_token != ''", $targetUserIds);
            foreach ($userRows as $r) {
                if (!empty($r['fcm_token'])) {
                    $tokens[] = $r['fcm_token'];
                }
            }
        } catch (Throwable $e) {}

        $tokens = array_unique(array_filter($tokens));
        if (empty($tokens)) {
            return;
        }

        // Format data values semuanya menjadi string agar aman di bundle Android
        $formattedData = [];
        foreach ($data as $k => $v) {
            $formattedData[(string)$k] = is_scalar($v) ? (string)$v : json_encode($v);
        }

        // Cek Service Account Firebase untuk FCM HTTP v1 (Google API Standar)
        $saPaths = [
            __DIR__ . '/../config/firebase-service-account.json',
            __DIR__ . '/../config/firebase service account.json',
            __DIR__ . '/../config/firebase_service_account.json',
            __DIR__ . '/../config/service-account.json',
            __DIR__ . '/../firebase-service-account.json'
        ];
        $activeSaPath = null;
        foreach ($saPaths as $p) {
            if (file_exists($p)) {
                $activeSaPath = $p;
                break;
            }
        }
        if (!$activeSaPath) {
            $globFiles = glob(__DIR__ . '/../config/*firebase*.json') ?: [];
            if (!empty($globFiles)) {
                $activeSaPath = $globFiles[0];
            }
        }

        if ($activeSaPath) {
            $accessToken = getGoogleFcmV1AccessToken($activeSaPath);
            if ($accessToken) {
                $saRaw = @file_get_contents($activeSaPath);
                $saData = json_decode($saRaw ?: '{}', true);
                $projectId = $saData['project_id'] ?? 'zainul-apkp';
                $v1Url = "https://fcm.googleapis.com/v1/projects/$projectId/messages:send";

                $headers = [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json; charset=utf-8'
                ];

                foreach ($tokens as $t) {
                    $v1Payload = [
                        'message' => [
                            'token' => $t,
                            'notification' => [
                                'title' => $title,
                                'body'  => $body
                            ],
                            'data' => $formattedData,
                            'android' => [
                                'priority' => 'HIGH'
                            ]
                        ]
                    ];
                    $jsonPayload = json_encode($v1Payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    if (function_exists('curl_init')) {
                        $ch = curl_init($v1Url);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                        curl_exec($ch);
                        curl_close($ch);
                    }
                }
                return;
            }
        }

        // Fallback ke Legacy Endpoint jika ada Server Key
        $serverKey = 'AAAAqWjQCzc:APA91bFcNyJeYJmOFXqufqJ96JaR_ywXhvB4Q8bhJab2lcTZrekj2YEK1J9oIgIJB2V1JaMNjut2HqufrHQsqQ75lVhou2k8wjBMRdIFxIqkRkAbE_llVHeG9YuOKTMX03W-G9_iOF';
        if (empty($serverKey)) return;

        $payload = [
            'priority'     => 'high',
            'notification' => [
                'title' => $title,
                'body'  => $body,
                'sound' => 'default',
                'icon'  => 'ic_quizb_logo'
            ],
            'data' => $formattedData
        ];

        if (count($tokens) === 1) {
            $payload['to'] = reset($tokens);
        } else {
            $payload['registration_ids'] = array_values($tokens);
        }

        $headers = [
            'Authorization: key=' . $serverKey,
            'Content-Type: application/json; charset=utf-8'
        ];

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (function_exists('curl_init')) {
            $ch = curl_init('https://fcm.googleapis.com/fcm/send');
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_exec($ch);
            curl_close($ch);
        }
    } catch (Throwable $e) {
        error_log('[FCM Send Error] ' . $e->getMessage());
    }
}

/**
 * Broadcast notifikasi soal baru ke semua user aktif, kecuali user tertentu (misal: admin yg nambah).
 * Dipanggil dari question_create, question_import_save, question_import_quizb.
 */
function broadcastNewQuestion(int $quizId, int $excludeUserId = 0): void {
    try {
        $quiz = DB::one('SELECT title FROM quizzes WHERE id = ?', [$quizId]);
        $quizTitle = $quiz['title'] ?? 'Quiz';

        $recipients = DB::all(
            "SELECT id FROM users WHERE is_active = 1" . ($excludeUserId > 0 ? " AND id != $excludeUserId" : "")
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

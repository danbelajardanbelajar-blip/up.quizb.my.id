<?php
// CLI only: this must never become a public endpoint that sends notifications.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__.'/../config/db.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../api/auth.php';

$options = getopt('', ['migrate', 'check', 'probe-firebase', 'retry-failed', 'limit:', 'seconds:']);
try {
    if (isset($options['migrate'])) {
        ensurePushQueue(); ensureFcmTokenTableExists();
        require_once __DIR__.'/../includes/challenge_lifecycle.php';
        ensureChallengeDismissals();
        echo "Notification schema ready.\n";
    }
    if (isset($options['check'])) {
        $checks = ['php_supported'=>PHP_VERSION_ID>=80000, 'curl'=>extension_loaded('curl'), 'openssl'=>extension_loaded('openssl')];
        $path = pushConfiguration()['credentials'];
        $account = is_file($path) ? json_decode(file_get_contents($path), true) : [];
        $checks['credentials_present'] = is_file($path);
        $checks['project_configured'] = !empty($account['project_id']);
        $checks['service_account_key_parses'] = function_exists('openssl_pkey_get_private') && !empty($account['private_key']) && (bool)openssl_pkey_get_private($account['private_key']);
        $checks['database_accessible'] = (int)(DB::one('SELECT 1 AS ok')['ok'] ?? 0)===1;
        foreach ($checks as $name=>$ok) echo ($ok ? 'OK   ' : 'FAIL ') . $name . "\n";
        if (in_array(false, $checks, true)) exit(1);
        $counts = DB::all('SELECT status, COUNT(*) AS count FROM notification_push_jobs GROUP BY status');
        echo json_encode(['queue'=>$counts], JSON_UNESCAPED_SLASHES) . "\n";
    }
    if (isset($options['probe-firebase'])) {
        // Exchanges credentials only; never prints the token or sends a user notification.
        $valid = (bool)getGoogleFcmV1AccessToken(pushConfiguration()['credentials'], true);
        echo $valid ? "OK   Google OAuth accepted\n" : "FAIL Google OAuth rejected or unreachable\n";
        if (!$valid) exit(1);
    }
    if (isset($options['retry-failed'])) {
        ensurePushQueue();
        $rows = DB::all("SELECT j.id, n.user_id, n.id AS notification_id, n.title, n.body, n.type, n.link
            FROM notification_push_jobs j JOIN notifications n ON n.id = j.notification_id
            WHERE j.status = 'failed' AND n.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) LIMIT 500");
        foreach ($rows as $row) {
            $data = ['type'=>$row['type'],'link'=>$row['link'],'title'=>$row['title'],'body'=>$row['body'],'recipient_user_id'=>(string)$row['user_id'],'notification_id'=>(string)$row['notification_id']];
            parse_str(parse_url($row['link'] ?? '', PHP_URL_QUERY) ?: '', $query);
            if (!empty($query['cid'])) $data['challenge_id'] = (string)$query['cid'];
            if (preg_match('~/play/(\d+)~', $row['link'] ?? '', $match)) $data['quiz_id'] = $match[1];
            $payload = json_encode(['title'=>$row['title'],'body'=>$row['body'] ?? '', 'data'=>$data], JSON_THROW_ON_ERROR);
            // Sent/invalid device receipts remain terminal, so retries cannot resend to those devices.
            DB::execute("UPDATE notification_push_jobs SET status = 'pending', attempts = 0, available_at = NOW(), payload = ? WHERE id = ?", [$payload,$row['id']]);
        }
        echo count($rows) . " failed jobs scheduled for retry.\n";
    }
    if (!$options || isset($options['limit']) || isset($options['seconds'])) {
        echo json_encode(processPushQueue(min(200,max(1,(int)($options['limit'] ?? 50))), min(50,max(1,(int)($options['seconds'] ?? 40)))) , JSON_UNESCAPED_SLASHES) . "\n";
    }
} catch (Throwable $e) {
    error_log('[Notification worker] ' . get_class($e));
    fwrite(STDERR, "Notification worker failed. Check database schema, credentials, and server error log.\n");
    exit(1);
}

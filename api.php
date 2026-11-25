<?php
// ============================================
// api.php — REST API Router (single entry)
// ============================================

// Buffer semua output agar PHP warning/notice tidak merusak JSON response
ob_start();

// ============================================
// CORS & Security Headers
// ============================================
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// Whitelist domain resmi yang diizinkan mengakses API dengan kredensial sesi
$isAllowedOrigin = false;
if (!empty($origin)) {
    // 1. Cek domain resmi quizb.my.id dan seluruh subdomainnya (*.quizb.my.id)
    if (preg_match('#^https?://([a-z0-9-]+\.)*quizb\.my\.id(:[0-9]+)?$#i', $origin)) {
        $isAllowedOrigin = true;
    }
    // 2. Cek mobile app webview / hybrid schemes
    elseif (in_array($origin, ['capacitor://localhost', 'ionic://localhost', 'http://localhost'], true)) {
        $isAllowedOrigin = true;
    }
    // 3. Cek local development (localhost & 127.0.0.1 dengan port sembarang)
    elseif (preg_match('#^https?://(localhost|127\.0\.0\.1)(:[0-9]+)?$#i', $origin)) {
        $isAllowedOrigin = true;
    }
}

if ($isAllowedOrigin) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
} elseif (empty($origin)) {
    // Request tanpa Origin (native mobile apps, same-origin, curl)
    header('Access-Control-Allow-Origin: *');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-Requested-With, X-App-Platform');
header('Access-Control-Expose-Headers: X-CSRF-Token');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if (!empty($origin) && !$isAllowedOrigin) {
        http_response_code(403);
        exit;
    }
    http_response_code(204);
    exit;
}

// Bootstrap
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/response.php';
require_once __DIR__ . '/includes/helpers.php';

startSecureSession();

set_exception_handler(function (Throwable $e) {
    error_log("[API Exception] " . $e->__toString());
    if (ob_get_level() > 0) ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    $message = defined('APP_ENV') && APP_ENV === 'development'
        ? $e->getMessage()
        : 'Terjadi kesalahan server';
    echo json_encode(['success' => false, 'error' => $message, 'message' => $message, 'code' => 500], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log(sprintf("[API Shutdown] %s in %s on line %s", $err['message'], $err['file'], $err['line']));
        if (ob_get_level() > 0) ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        $message = defined('APP_ENV') && APP_ENV === 'development'
            ? $err['message']
            : 'Terjadi kesalahan server';
        echo json_encode(['success' => false, 'error' => $message, 'message' => $message, 'code' => 500], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
});

// Generate CSRF token on first call (GET requests)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    generateCsrfToken();
}

// Validate CSRF for state-changing requests
$method = $_SERVER['REQUEST_METHOD'];
if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
    $action = $_GET['action'] ?? '';

    // Check if request is genuinely from Android native HTTP client (no browser Origin/Referer)
    // and without an explicit CSRF token header (if CSRF token is sent, always validate it).
    $hasBrowserContext = !empty($_SERVER['HTTP_ORIGIN']) || !empty($_SERVER['HTTP_REFERER']);
    $hasCsrfHeader     = !empty($_SERVER['HTTP_X_CSRF_TOKEN']) || !empty($_POST['_csrf']);
    $isMobileApp = !$hasBrowserContext && !$hasCsrfHeader && (
        (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'id.my.quizb.android')
        || (!empty($_SERVER['HTTP_X_APP_PLATFORM']) && strtolower($_SERVER['HTTP_X_APP_PLATFORM']) === 'android')
        || (isset($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'QuizB-Android') !== false)
    );

    // Endpoints exempted from CSRF (Pre-auth / Auth session endpoints only)
    $csrfExemptActions = [
        'auth.login', 'auth.register', 'auth.logout', 'auth.google', 'auth.google_native',
        'auth.resend_verification',
    ];

    if (!$isMobileApp && !in_array($action, $csrfExemptActions, true)) {
        validateCsrfToken();
    }
}

// Route dispatcher
$action = $_GET['action'] ?? '';

// Split action into [namespace].[method]
[$ns, $fn] = array_pad(explode('.', $action, 2), 2, 'index');

$routes = [
    'auth'        => __DIR__ . '/api/auth.php',
    'quiz'        => __DIR__ . '/api/quiz.php',
    'category'       => __DIR__ . '/api/category.php',
    'category_group' => __DIR__ . '/api/category_group.php',
    'question'    => __DIR__ . '/api/question.php',
    'attempt'     => __DIR__ . '/api/attempt.php',
    'leaderboard' => __DIR__ . '/api/leaderboard.php',
    'search'      => __DIR__ . '/api/search.php',
    'admin'       => __DIR__ . '/api/admin.php',
    'class'       => __DIR__ . '/api/class.php',
    'assignment'  => __DIR__ . '/api/assignment.php',
    'challenge'   => __DIR__ . '/api/challenge.php',
    'tournament'  => __DIR__ . '/api/tournament.php',
    'notification' => __DIR__ . '/api/notification.php',
    'message'     => __DIR__ . '/api/message.php',
    'activity'    => __DIR__ . '/api/activity.php',
    'proctoring'  => __DIR__ . '/api/proctoring.php',
];

if (!isset($routes[$ns])) {
    jsonError("Unknown action: $action", 404);
}

// Load the handler file
$handlerFile = $routes[$ns];
if (!file_exists($handlerFile)) {
    jsonError("Handler not found", 500);
}

require_once $handlerFile;

// Call function like auth_login(), quiz_list(), etc.
$funcName = $ns . '_' . ($fn ?: 'index');
if (function_exists($funcName)) {
    call_user_func($funcName);
} else {
    jsonError("Action '$action' not found", 404);
}

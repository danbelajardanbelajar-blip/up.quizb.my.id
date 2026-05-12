<?php
// ============================================
// api.php — REST API Router (single entry)
// ============================================

// Buffer semua output agar PHP warning/notice tidak merusak JSON response
ob_start();

// CORS & Headers
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Expose-Headers: X-CSRF-Token');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
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
    // Allow action=auth.login and auth.register without CSRF (first-time)
    $action = $_GET['action'] ?? '';
    if (!in_array($action, ['auth.login', 'auth.register', 'auth.logout', 'auth.google'])) {
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
    'notification' => __DIR__ . '/api/notification.php',
    'message'     => __DIR__ . '/api/message.php',
    'activity'    => __DIR__ . '/api/activity.php',
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

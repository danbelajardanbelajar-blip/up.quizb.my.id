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

startSecureSession();

// Generate CSRF token on first call (GET requests)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    generateCsrfToken();
}

// Validate CSRF for state-changing requests
$method = $_SERVER['REQUEST_METHOD'];
if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
    // Allow action=auth.login and auth.register without CSRF (first-time)
    $action = $_GET['action'] ?? '';
    if (!in_array($action, ['auth.login', 'auth.register', 'auth.logout'])) {
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

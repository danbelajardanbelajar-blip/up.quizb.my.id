<?php
// ============================================
// includes/csrf.php — CSRF Token Helpers
// ============================================

function generateCsrfToken(): string {
    startSecureSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken(): void {
    startSecureSession();
    $token = $_SERVER['HTTP_X_CSRF_TOKEN']
          ?? $_POST['_csrf'] 
          ?? '';

    if (empty($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die(json_encode(['error' => 'Invalid CSRF token', 'code' => 403]));
    }
}

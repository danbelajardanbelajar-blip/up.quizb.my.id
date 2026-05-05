<?php
// ============================================
// includes/response.php — JSON Response Helper
// ============================================

function jsonResponse(mixed $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    
    // Send CSRF token in response header for client to pick up
    $token = generateCsrfToken();
    header('X-CSRF-Token: ' . $token);
    
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonSuccess(mixed $data = null, string|int $message = 'OK'): never {
    // Allow callers to pass HTTP code as 2nd arg (e.g. jsonSuccess($data, 201))
    if (is_int($message)) {
        http_response_code($message);
        jsonResponse(['success' => true, 'message' => 'OK', 'data' => $data], $message);
    }
    jsonResponse(['success' => true, 'message' => $message, 'data' => $data]);
}

function jsonError(string $message, int $code = 400): never {
    // Include both 'error' and 'message' keys for JS compatibility
    jsonResponse(['success' => false, 'error' => $message, 'message' => $message, 'code' => $code], $code);
}

function jsonPaginated(array $data, int $total, int $page, int $limit): never {
    jsonResponse([
        'success' => true,
        'data'    => $data,
        'meta'    => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int) ceil($total / $limit),
        ]
    ]);
}

function sanitizeString(string $value): string {
    return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
}

function getBody(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

// Alias — beberapa handler menggunakan nama ini
function getJsonBody(): array {
    return getBody();
}

function getPaginationParams(): array {
    $page  = max(1, (int)($_GET['page']  ?? 1));
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 12)));
    return [$page, $limit, ($page - 1) * $limit];
}

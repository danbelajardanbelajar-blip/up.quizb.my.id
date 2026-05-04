<?php
// ============================================
// includes/response.php — JSON Response Helper
// ============================================

function jsonResponse(mixed $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonSuccess(mixed $data = null, string $message = 'OK'): never {
    jsonResponse(['success' => true, 'message' => $message, 'data' => $data]);
}

function jsonError(string $message, int $code = 400): never {
    jsonResponse(['success' => false, 'error' => $message, 'code' => $code], $code);
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

function getPaginationParams(): array {
    $page  = max(1, (int)($_GET['page']  ?? 1));
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 12)));
    return [$page, $limit, ($page - 1) * $limit];
}

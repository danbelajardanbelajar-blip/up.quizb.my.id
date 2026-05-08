<?php
// ============================================================
// api/notification.php — Endpoint Notifikasi
// ============================================================

// GET — daftar notifikasi (paginated)
function notification_list(): void {
    $user = requireAuth();
    [$page, $limit, $offset] = getPaginationParams();
    $limit = min(30, max(1, $limit));

    $total = (int)(DB::one(
        "SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ?",
        [$user['id']]
    )['cnt'] ?? 0);

    $rows = DB::all(
        "SELECT id, type, title, body, link, is_read, created_at
         FROM notifications WHERE user_id = ?
         ORDER BY created_at DESC LIMIT ? OFFSET ?",
        [$user['id'], $limit, $offset]
    );
    foreach ($rows as &$r) {
        $r['id']      = (int)$r['id'];
        $r['is_read'] = (bool)(int)$r['is_read'];
    }
    jsonPaginated($rows, $total, $page, $limit);
}

// GET — jumlah notif + pesan belum dibaca (untuk polling)
function notification_counts(): void {
    $user = requireAuth();
    $uid  = (int)$user['id'];

    $notifCount = (int)(DB::one(
        "SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0",
        [$uid]
    )['cnt'] ?? 0);

    $msgCount = (int)(DB::one(
        "SELECT COUNT(*) AS cnt
         FROM messages m
         JOIN message_threads t ON m.thread_id = t.id
         WHERE (t.user1_id = ? OR t.user2_id = ?) AND m.sender_id != ? AND m.is_read = 0",
        [$uid, $uid, $uid]
    )['cnt'] ?? 0);

    jsonSuccess(['notifications' => $notifCount, 'messages' => $msgCount]);
}

// POST — tandai satu notif (id) atau semua sebagai sudah dibaca
function notification_mark_read(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    $user = requireAuth();
    $body = getBody();
    $id   = isset($body['id']) ? (int)$body['id'] : null;

    if ($id) {
        DB::execute(
            "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?",
            [$id, $user['id']]
        );
    } else {
        DB::execute("UPDATE notifications SET is_read = 1 WHERE user_id = ?", [$user['id']]);
    }
    jsonSuccess([], 'Ditandai sudah dibaca');
}

// DELETE — hapus satu notif (id) atau semua yang sudah dibaca
function notification_delete(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') jsonError('Method not allowed', 405);
    $user = requireAuth();
    $id   = (int)($_GET['id'] ?? 0);

    if ($id) {
        DB::execute(
            "DELETE FROM notifications WHERE id = ? AND user_id = ?",
            [$id, $user['id']]
        );
    } else {
        DB::execute(
            "DELETE FROM notifications WHERE user_id = ? AND is_read = 1",
            [$user['id']]
        );
    }
    jsonSuccess([], 'Notifikasi dihapus');
}

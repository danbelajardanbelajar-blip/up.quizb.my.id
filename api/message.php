<?php
// ============================================================
// api/message.php — Endpoint Pesan Langsung (DM)
// ============================================================

// Helper: ambil atau buat thread untuk pasangan user
function _getOrCreateThread(int $uid, int $otherId): int {
    $u1 = min($uid, $otherId);
    $u2 = max($uid, $otherId);
    $t  = DB::one("SELECT id FROM message_threads WHERE user1_id = ? AND user2_id = ?", [$u1, $u2]);
    if ($t) return (int)$t['id'];
    DB::execute("INSERT INTO message_threads (user1_id, user2_id) VALUES (?, ?)", [$u1, $u2]);
    return (int)DB::conn()->lastInsertId();
}

// GET — daftar thread dengan preview pesan terakhir + jumlah belum dibaca
function message_threads(): void {
    $user = requireAuth();
    $uid  = (int)$user['id'];

    $threads = DB::all(
        "SELECT
            t.id,
            t.last_message_at,
            CASE WHEN t.user1_id = ? THEN t.user2_id ELSE t.user1_id END AS other_id,
            CASE WHEN t.user1_id = ? THEN u2.name   ELSE u1.name   END AS other_name,
            (SELECT body      FROM messages WHERE thread_id = t.id ORDER BY created_at DESC LIMIT 1) AS last_body,
            (SELECT sender_id FROM messages WHERE thread_id = t.id ORDER BY created_at DESC LIMIT 1) AS last_sender_id,
            (SELECT COUNT(*)  FROM messages WHERE thread_id = t.id AND sender_id != ? AND is_read = 0) AS unread_count
         FROM message_threads t
         JOIN users u1 ON u1.id = t.user1_id
         JOIN users u2 ON u2.id = t.user2_id
         WHERE t.user1_id = ? OR t.user2_id = ?
         ORDER BY t.last_message_at DESC
         LIMIT 60",
        [$uid, $uid, $uid, $uid, $uid]
    );

    foreach ($threads as &$th) {
        $th['id']           = (int)$th['id'];
        $th['other_id']     = (int)$th['other_id'];
        $th['unread_count'] = (int)$th['unread_count'];
        $th['last_sender_id'] = $th['last_sender_id'] ? (int)$th['last_sender_id'] : null;
        $th['is_last_mine'] = $th['last_sender_id'] === $uid;
    }
    jsonSuccess($threads);
}

// GET — pesan di dalam thread (paginated, urutan chronologis)
function message_thread_messages(): void {
    $user     = requireAuth();
    $uid      = (int)$user['id'];
    $threadId = (int)($_GET['thread_id'] ?? 0);
    if (!$threadId) jsonError('thread_id diperlukan');

    $t = DB::one(
        "SELECT id FROM message_threads WHERE id = ? AND (user1_id = ? OR user2_id = ?)",
        [$threadId, $uid, $uid]
    );
    if (!$t) jsonError('Thread tidak ditemukan', 404);

    [$page, $limit, $offset] = getPaginationParams();
    $limit = min(50, max(1, $limit));

    $total = (int)(DB::one(
        "SELECT COUNT(*) AS cnt FROM messages WHERE thread_id = ?",
        [$threadId]
    )['cnt'] ?? 0);

    // Ambil dari belakang (DESC) lalu balik supaya chronologis
    $rows = DB::all(
        "SELECT m.id, m.sender_id, m.body, m.is_read, m.created_at,
                u.name AS sender_name
         FROM messages m
         JOIN users u ON u.id = m.sender_id
         WHERE m.thread_id = ?
         ORDER BY m.created_at DESC
         LIMIT ? OFFSET ?",
        [$threadId, $limit, $offset]
    );

    foreach ($rows as &$r) {
        $r['id']        = (int)$r['id'];
        $r['sender_id'] = (int)$r['sender_id'];
        $r['is_read']   = (bool)(int)$r['is_read'];
        $r['is_mine']   = $r['sender_id'] === $uid;
    }

    // Tandai pesan dari lawan sebagai sudah dibaca
    DB::execute(
        "UPDATE messages SET is_read = 1 WHERE thread_id = ? AND sender_id != ? AND is_read = 0",
        [$threadId, $uid]
    );

    jsonPaginated(array_reverse($rows), $total, $page, $limit);
}

// POST — kirim pesan
function message_send(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    $user = requireAuth();
    $uid  = (int)$user['id'];
    $body = getBody();

    $threadId = (int)($body['thread_id'] ?? 0);
    $otherId  = (int)($body['user_id']   ?? 0);
    $text     = trim($body['body'] ?? '');

    if (!$text)            jsonError('Pesan tidak boleh kosong');
    if (mb_strlen($text) > 5000) jsonError('Pesan terlalu panjang (maks 5000 karakter)');

    // Resolve thread
    if ($threadId) {
        $t = DB::one(
            "SELECT id, user1_id, user2_id FROM message_threads WHERE id = ? AND (user1_id = ? OR user2_id = ?)",
            [$threadId, $uid, $uid]
        );
        if (!$t) jsonError('Thread tidak ditemukan', 404);
        $otherId = $t['user1_id'] == $uid ? (int)$t['user2_id'] : (int)$t['user1_id'];
    } else {
        if (!$otherId)          jsonError('Sertakan thread_id atau user_id');
        if ($otherId === $uid)  jsonError('Tidak bisa mengirim pesan ke diri sendiri');
        $other = DB::one("SELECT id, name FROM users WHERE id = ? AND is_active = 1", [$otherId]);
        if (!$other) jsonError('User tidak ditemukan', 404);
        $threadId = _getOrCreateThread($uid, $otherId);
    }

    DB::execute(
        "INSERT INTO messages (thread_id, sender_id, body) VALUES (?, ?, ?)",
        [$threadId, $uid, $text]
    );
    $msgId = (int)DB::conn()->lastInsertId();

    DB::execute(
        "UPDATE message_threads SET last_message_at = NOW() WHERE id = ?",
        [$threadId]
    );

    // Notifikasi ke penerima
    $preview = mb_strlen($text) > 80 ? mb_substr($text, 0, 80) . '…' : $text;
    pushNotification($otherId, 'message', $user['name'] . ' mengirimmu pesan', $preview, '/messages?thread=' . $threadId);

    jsonSuccess([
        'id'         => $msgId,
        'thread_id'  => $threadId,
        'sender_id'  => $uid,
        'sender_name'=> $user['name'],
        'body'       => $text,
        'is_mine'    => true,
        'is_read'    => false,
        'created_at' => date('Y-m-d H:i:s'),
    ], 'Pesan terkirim');
}

// GET — buka atau buat thread dengan user tertentu
function message_open_thread(): void {
    $user    = requireAuth();
    $uid     = (int)$user['id'];
    $otherId = (int)($_GET['user_id'] ?? 0);
    if (!$otherId || $otherId === $uid) jsonError('user_id tidak valid');

    $other = DB::one("SELECT id, name FROM users WHERE id = ? AND is_active = 1", [$otherId]);
    if (!$other) jsonError('User tidak ditemukan', 404);

    $threadId = _getOrCreateThread($uid, $otherId);
    jsonSuccess([
        'thread_id'  => $threadId,
        'other_id'   => (int)$other['id'],
        'other_name' => $other['name'],
    ]);
}

// GET — cari user untuk mulai percakapan
function message_search_users(): void {
    $user = requireAuth();
    $q    = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) jsonError('Ketikkan minimal 2 karakter');

    $users = DB::all(
        "SELECT id, name, email FROM users
         WHERE (name LIKE ? OR email LIKE ?) AND id != ? AND is_active = 1
         LIMIT 10",
        ["%$q%", "%$q%", $user['id']]
    );
    jsonSuccess($users);
}

// DELETE — hapus pesan milik sendiri
function message_delete(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') jsonError('Method not allowed', 405);
    $user  = requireAuth();
    $uid   = (int)$user['id'];
    $msgId = (int)($_GET['id'] ?? 0);
    if (!$msgId) jsonError('id diperlukan');

    $msg = DB::one("SELECT sender_id FROM messages WHERE id = ?", [$msgId]);
    if (!$msg || (int)$msg['sender_id'] !== $uid) jsonError('Pesan tidak ditemukan atau bukan milikmu', 403);

    DB::execute("DELETE FROM messages WHERE id = ?", [$msgId]);
    jsonSuccess([], 'Pesan dihapus');
}

<?php
// ============================================================
// includes/helpers.php — Fungsi pembantu lintas modul
// ============================================================

/**
 * Push notifikasi ke user tertentu.
 * Dipanggil dari challenge.php, message.php, dsb.
 */
function pushNotification(int $userId, string $type, string $title, string $body = '', string $link = ''): void {
    try {
        DB::execute(
            "INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, ?, ?, ?, ?)",
            [$userId, $type, $title, $body ?: null, $link ?: null]
        );
    } catch (Throwable $e) {
        // Jangan sampai gagal notif mengganggu operasi utama
        error_log('[pushNotification] ' . $e->getMessage());
    }
}

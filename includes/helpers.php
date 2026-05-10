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

/**
 * Broadcast notifikasi soal baru ke semua user aktif, kecuali user tertentu (misal: admin yg nambah).
 * Dipanggil dari question_create, question_import_save, question_import_quizb.
 */
function broadcastNewQuestion(int $quizId, int $excludeUserId = 0): void {
    try {
        $quiz = DB::one('SELECT title FROM quizzes WHERE id = ?', [$quizId]);
        $quizTitle = $quiz['title'] ?? 'Quiz';

        $recipients = DB::all(
            "SELECT id FROM users WHERE is_active = 1" . ($excludeUserId > 0 ? " AND id != $excludeUserId" : "")
        );
        foreach ($recipients as $rec) {
            pushNotification(
                (int)$rec['id'],
                'new_question',
                '📝 Soal baru ditambahkan',
                'Soal baru tersedia di kuis "' . $quizTitle . '".',
                '/quiz/' . $quizId
            );
        }
    } catch (Throwable $e) {
        error_log('[broadcastNewQuestion] ' . $e->getMessage());
    }
}

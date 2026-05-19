<?php
// ============================================
// includes/session_handler.php
// Custom PHP session handler — menyimpan data
// session ke tabel MySQL `db_sessions` agar
// session tidak hilang karena GC server/file-system
// dan bertahan selama user tidak logout atau
// menghapus cookies browser.
// ============================================

class DatabaseSessionHandler implements SessionHandlerInterface {

    private PDO $pdo;

    // GC hanya hapus session yang sudah sangat lama tidak aktif (1 tahun).
    // Dalam praktiknya session dihapus saat user logout, bukan oleh GC.
    private int $gcMaxLifetime = 86400 * 365;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // ── Dipanggil saat session_start() ────────────────────────
    public function open(string $savePath, string $sessionName): bool {
        return true; // Koneksi PDO sudah siap via constructor
    }

    // ── Dipanggil saat script selesai ─────────────────────────
    public function close(): bool {
        return true;
    }

    // ── Baca data session berdasarkan ID ──────────────────────
    public function read(string $id): string|false {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT data FROM db_sessions WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? (string)$row['data'] : '';
        } catch (\Throwable $e) {
            error_log('[Session] read() error: ' . $e->getMessage());
            return '';
        }
    }

    // ── Tulis / update data session ───────────────────────────
    public function write(string $id, string $data): bool {
        try {
            $now    = time();
            $userId = $this->extractUserId($data);

            $stmt = $this->pdo->prepare(
                'INSERT INTO db_sessions (id, user_id, data, last_activity, created_at)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   user_id       = VALUES(user_id),
                   data          = VALUES(data),
                   last_activity = VALUES(last_activity)'
            );
            $stmt->execute([$id, $userId, $data, $now, $now]);
            return true;
        } catch (\Throwable $e) {
            error_log('[Session] write() error: ' . $e->getMessage());
            return false;
        }
    }

    // ── Hapus session tertentu (dipanggil saat session_destroy) ─
    public function destroy(string $id): bool {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM db_sessions WHERE id = ?');
            $stmt->execute([$id]);
            return true;
        } catch (\Throwable $e) {
            error_log('[Session] destroy() error: ' . $e->getMessage());
            return false;
        }
    }

    // ── Garbage collection: hapus session yang sangat lama ────
    public function gc(int $maxLifetime): int|false {
        try {
            $threshold = time() - $this->gcMaxLifetime;
            $stmt = $this->pdo->prepare(
                'DELETE FROM db_sessions WHERE last_activity < ?'
            );
            $stmt->execute([$threshold]);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            error_log('[Session] gc() error: ' . $e->getMessage());
            return false;
        }
    }

    // ── Hapus semua session milik user tertentu (opsional) ────
    // Berguna untuk "logout semua perangkat"
    public function destroyAllForUser(int $userId): void {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM db_sessions WHERE user_id = ?');
            $stmt->execute([$userId]);
        } catch (\Throwable $e) {
            error_log('[Session] destroyAllForUser() error: ' . $e->getMessage());
        }
    }

    // ── Helper: ekstrak user_id dari data session serial PHP ──
    private function extractUserId(string $data): ?int {
        // Format default PHP: key|serialize(value);key2|...
        if (preg_match('/user_id\|i:(\d+);/', $data, $m)) {
            return (int)$m[1];
        }
        return null;
    }
}

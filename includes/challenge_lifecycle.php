<?php

function ensureChallengeDismissals(): void {
    static $ready = false;
    if ($ready) return;
    DB::execute("CREATE TABLE IF NOT EXISTS challenge_dismissals (
        user_id INT NOT NULL, challenge_id INT NOT NULL,
        PRIMARY KEY (user_id, challenge_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ready = true;
}

function expirePendingChallenges(): void {
    DB::execute("UPDATE challenges SET status = 'expired'
                 WHERE status = 'pending' AND expires_at IS NOT NULL AND expires_at <= NOW()");
}

function finalizeChallengeResponses(int $id): void {
    $pending = (int)(DB::one("SELECT COUNT(*) AS c FROM challenge_participants WHERE challenge_id = ? AND status = 'pending'", [$id])['c'] ?? 0);
    if ($pending !== 0) return;
    $accepted = (int)(DB::one("SELECT COUNT(*) AS c FROM challenge_participants WHERE challenge_id = ? AND status = 'accepted'", [$id])['c'] ?? 0);
    if ($accepted > 1) {
        DB::execute("UPDATE challenges SET status = 'playing', start_time = DATE_ADD(NOW(), INTERVAL 5 SECOND) WHERE id = ? AND status = 'pending'", [$id]);
    } else {
        DB::execute("UPDATE challenges SET status = 'completed' WHERE id = ? AND status = 'pending'", [$id]);
    }
}

function respondToChallenge(int $id, int $userId, bool $accept): array {
    expirePendingChallenges();
    $pdo = DB::conn();
    $pdo->beginTransaction();
    try {
        // Serialize responses in one room so simultaneous accepts cannot leave it pending.
        $room = DB::one('SELECT *, (expires_at IS NOT NULL AND expires_at <= NOW()) AS expired_now FROM challenges WHERE id = ? FOR UPDATE', [$id]);
        $participant = DB::one('SELECT * FROM challenge_participants WHERE challenge_id = ? AND user_id = ?', [$id, $userId]);
        if ($room && $room['status'] === 'pending' && (int)$room['expired_now'] === 1) {
            DB::execute("UPDATE challenges SET status = 'expired' WHERE id = ?", [$id]);
            $pdo->commit();
            jsonError('Tantangan sudah kedaluwarsa.', 409);
        }
        if (!$room || $room['status'] !== 'pending' || !$participant || $participant['status'] !== 'pending') {
            $pdo->rollBack();
            jsonError('Tantangan sudah direspons, kedaluwarsa, atau tidak tersedia.', 409);
        }
        DB::execute('UPDATE challenge_participants SET status = ? WHERE id = ?', [$accept ? 'accepted' : 'declined', $participant['id']]);
        finalizeChallengeResponses($id);
        $pdo->commit();
        return $room;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

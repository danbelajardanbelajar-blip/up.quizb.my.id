
// POST — sinkronisasi real-time
function challenge_sync(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    $user = requireAuth();
    $body = getBody();
    
    $challengeId = (int)($body['challenge_id'] ?? 0);
    $score = (int)($body['score'] ?? 0);
    $qIndex = (int)($body['q_index'] ?? 0);
    if (!$challengeId) jsonError('Challenge ID diperlukan');

    $c = DB::one("SELECT * FROM challenges WHERE id = ? AND (challenger_id = ? OR challenged_id = ?)", [$challengeId, $user['id'], $user['id']]);
    if (!$c) jsonError('Tantangan tidak ditemukan', 404);

    $isChallenger = (int)$c['challenger_id'] === (int)$user['id'];
    
    // Update progress kita
    $myProgress = json_encode(['s' => $score, 'q' => $qIndex, 'last_ping' => time()]);
    if ($isChallenger) {
        DB::execute("UPDATE challenges SET challenger_progress = ? WHERE id = ?", [$myProgress, $challengeId]);
        $c['challenger_progress'] = $myProgress;
    } else {
        DB::execute("UPDATE challenges SET challenged_progress = ? WHERE id = ?", [$myProgress, $challengeId]);
        $c['challenged_progress'] = $myProgress;
    }

    // Ambil data lawan
    $enemyProgressRaw = $isChallenger ? $c['challenged_progress'] : $c['challenger_progress'];
    $enemyP = $enemyProgressRaw ? json_decode($enemyProgressRaw, true) : null;
    
    // Cek putus koneksi lawan (timeout 15 detik) jika status masih playing
    if ($c['status'] === 'playing' && $enemyP && isset($enemyP['last_ping'])) {
        if (time() - $enemyP['last_ping'] > 15) {
            // Lawan putus koneksi, kita menang otomatis
            $winnerId = $user['id'];
            DB::execute(
                "UPDATE challenges SET status = 'completed', winner_id = ? WHERE id = ?",
                [$winnerId, $challengeId]
            );
            jsonSuccess(['status' => 'disconnected', 'enemy' => $enemyP]);
        }
    }

    jsonSuccess([
        'status' => $c['status'],
        'start_time' => $c['start_time'] ?? null,
        'enemy' => $enemyP
    ]);
}


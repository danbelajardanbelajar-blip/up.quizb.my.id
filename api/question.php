<?php
// ============================================
// api/question.php — Question CRUD (Admin)
// ============================================

function question_list(): void {
    requireAdmin();
    $quizId = (int)($_GET['quiz_id'] ?? 0);
    if (!$quizId) jsonError('Quiz ID diperlukan');

    $questions = DB::all(
        'SELECT id, question_text, type, points, order_num FROM questions WHERE quiz_id = ? ORDER BY order_num',
        [$quizId]
    );

    foreach ($questions as &$q) {
        $q['options'] = DB::all(
            'SELECT id, option_text, is_correct, order_num FROM options WHERE question_id = ? ORDER BY order_num',
            [$q['id']]
        );
    }
    unset($q);

    jsonSuccess($questions);
}

function question_create(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    requireAdmin();
    $body = getBody();

    $quizId = (int)($body['quiz_id'] ?? 0);
    $text   = sanitizeString($body['question_text'] ?? '');
    $type   = in_array($body['type'] ?? '', ['multiple','true_false']) ? $body['type'] : 'multiple';
    $points = max(1, min(100, (int)($body['points'] ?? 10)));
    $order  = (int)($body['order_num'] ?? 0);
    $expl   = sanitizeString($body['explanation'] ?? '');
    $options = $body['options'] ?? [];

    if (!$quizId || !$text) jsonError('Quiz ID dan teks soal wajib diisi');
    if (empty($options)) jsonError('Minimal satu pilihan jawaban diperlukan');

    DB::execute(
        'INSERT INTO questions (quiz_id, question_text, type, points, order_num, explanation) VALUES (?,?,?,?,?,?)',
        [$quizId, $text, $type, $points, $order, $expl]
    );
    $qId = (int)DB::lastId();

    foreach ($options as $i => $opt) {
        $optText   = sanitizeString($opt['option_text'] ?? '');
        $isCorrect = (int)(bool)($opt['is_correct'] ?? false);
        if ($optText) {
            DB::execute(
                'INSERT INTO options (question_id, option_text, is_correct, order_num) VALUES (?,?,?,?)',
                [$qId, $optText, $isCorrect, $i + 1]
            );
        }
    }

    // Update quiz question count
    DB::execute(
        'UPDATE quizzes SET total_questions = (SELECT COUNT(*) FROM questions WHERE quiz_id = ?) WHERE id = ?',
        [$quizId, $quizId]
    );

    jsonSuccess(['id' => $qId], 'Soal berhasil ditambahkan');
}

function question_update(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    requireAdmin();
    $body = getBody();

    $id     = (int)($body['id'] ?? 0);
    $text   = sanitizeString($body['question_text'] ?? '');
    $type   = in_array($body['type'] ?? '', ['multiple','true_false']) ? $body['type'] : 'multiple';
    $points = max(1, min(100, (int)($body['points'] ?? 10)));
    $order  = (int)($body['order_num'] ?? 0);
    $expl   = sanitizeString($body['explanation'] ?? '');
    $options = $body['options'] ?? [];

    if (!$id || !$text) jsonError('ID dan teks soal wajib diisi');

    DB::execute(
        'UPDATE questions SET question_text=?, type=?, points=?, order_num=?, explanation=? WHERE id=?',
        [$text, $type, $points, $order, $expl, $id]
    );

    // Delete & re-insert options
    DB::execute('DELETE FROM options WHERE question_id = ?', [$id]);
    foreach ($options as $i => $opt) {
        $optText   = sanitizeString($opt['option_text'] ?? '');
        $isCorrect = (int)(bool)($opt['is_correct'] ?? false);
        if ($optText) {
            DB::execute(
                'INSERT INTO options (question_id, option_text, is_correct, order_num) VALUES (?,?,?,?)',
                [$id, $optText, $isCorrect, $i + 1]
            );
        }
    }

    jsonSuccess(null, 'Soal berhasil diperbarui');
}

function question_delete(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    requireAdmin();
    $body = getBody();
    $id   = (int)($body['id'] ?? 0);
    if (!$id) jsonError('ID diperlukan');

    $q = DB::one('SELECT quiz_id FROM questions WHERE id = ?', [$id]);
    if (!$q) jsonError('Soal tidak ditemukan', 404);

    DB::execute('DELETE FROM questions WHERE id = ?', [$id]);
    DB::execute(
        'UPDATE quizzes SET total_questions = (SELECT COUNT(*) FROM questions WHERE quiz_id = ?) WHERE id = ?',
        [$q['quiz_id'], $q['quiz_id']]
    );

    jsonSuccess(null, 'Soal berhasil dihapus');
}

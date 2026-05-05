<?php
// ============================================
// api/fix.php — Database Fix Endpoints
// ============================================

function fix_correct_answers(): void {
    // Only allow admin access
    $user = requireAuth();
    if ($user['role'] !== 'admin') {
        jsonError('Access denied', 403);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonError('Method not allowed', 405);
    }

    $config = include __DIR__ . '/../config/db.php';
    try {
        $pdo = new PDO('mysql:host='.$config['host'].';dbname='.$config['database'], $config['username'], $config['password']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Cari questions yang tidak punya correct option
        $stmt = $pdo->prepare("
            SELECT q.id as question_id, q.quiz_id, COUNT(o.id) as option_count
            FROM questions q
            LEFT JOIN options o ON q.id = o.question_id
            WHERE NOT EXISTS (
                SELECT 1 FROM options o2 WHERE o2.question_id = q.id AND o2.is_correct = 1
            )
            GROUP BY q.id, q.quiz_id
            HAVING option_count > 0
        ");
        $stmt->execute();
        $questionsWithoutCorrect = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $fixed = 0;
        foreach ($questionsWithoutCorrect as $q) {
            // Ambil semua opsi untuk question ini
            $stmt = $pdo->prepare("SELECT id, option_text FROM options WHERE question_id = ? ORDER BY order_num");
            $stmt->execute([$q['question_id']]);
            $options = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($options) > 0) {
                // Pilih opsi pertama sebagai correct
                $correctOptionId = $options[0]['id'];

                // Set sebagai correct
                $stmt = $pdo->prepare("UPDATE options SET is_correct = 1 WHERE id = ?");
                $stmt->execute([$correctOptionId]);

                $fixed++;
            }
        }

        // Verifikasi perbaikan
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT q.id) as total_questions,
                   COUNT(DISTINCT CASE WHEN EXISTS(SELECT 1 FROM options o WHERE o.question_id = q.id AND o.is_correct = 1) THEN q.id END) as questions_with_correct
            FROM questions q
        ");
        $stmt->execute();
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        jsonSuccess([
            'message' => 'Correct answers fixed successfully',
            'fixed_questions' => $fixed,
            'total_questions' => (int)$stats['total_questions'],
            'questions_with_correct' => (int)$stats['questions_with_correct'],
            'percentage' => $stats['total_questions'] > 0 ? round(($stats['questions_with_correct'] / $stats['total_questions']) * 100, 1) : 0
        ]);

    } catch (Exception $e) {
        jsonError('Database error: ' . $e->getMessage(), 500);
    }
}
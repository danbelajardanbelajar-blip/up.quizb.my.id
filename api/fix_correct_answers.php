<?php
// Script untuk memperbaiki correct answers di database production
// Mengatur salah satu opsi sebagai correct untuk setiap question yang belum punya
// Akses: https://up.quizb.my.id/fix_correct_answers.php

echo "<h1>Fixing Correct Answers in Database</h1>\n";
echo "<pre>\n";

$config = include 'config/db.php';
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

    echo "Found " . count($questionsWithoutCorrect) . " questions without correct answers\n\n";

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

            echo "Fixed question {$q['question_id']} (quiz {$q['quiz_id']}): set option {$correctOptionId} as correct\n";
            $fixed++;
        }
    }

    echo "\nFixed $fixed questions\n\n";

    // Verifikasi perbaikan
    $stmt = $pdo->prepare("
        SELECT q.quiz_id, COUNT(DISTINCT q.id) as total_questions,
               COUNT(DISTINCT CASE WHEN EXISTS(SELECT 1 FROM options o WHERE o.question_id = q.id AND o.is_correct = 1) THEN q.id END) as questions_with_correct
        FROM questions q
        GROUP BY q.quiz_id
        ORDER BY q.quiz_id
    ");
    $stmt->execute();
    $quizStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Quiz Statistics:\n";
    foreach ($quizStats as $stat) {
        $percentage = $stat['total_questions'] > 0 ? round(($stat['questions_with_correct'] / $stat['total_questions']) * 100, 1) : 0;
        echo "Quiz {$stat['quiz_id']}: {$stat['questions_with_correct']}/{$stat['total_questions']} questions have correct answers ({$percentage}%)\n";
    }

    echo "\n✅ Fix completed! Delete this file immediately.\n";

} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}

echo "</pre>\n";
?>
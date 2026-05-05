<?php
// Script untuk debug masalah skor quiz Fiqh Zakat
require_once 'config/db.php';

try {
    $pdo = DB::getInstance();

    // Cari quiz dengan judul mengandung 'Fiqh Zakat'
    $stmt = $pdo->query("SELECT id, title FROM quizzes WHERE title LIKE '%Fiqh Zakat%' LIMIT 5");
    $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($quizzes)) {
        echo "Quiz 'Fiqh Zakat' tidak ditemukan.\n";
        exit;
    }

    foreach ($quizzes as $quiz) {
        echo "Quiz ID: {$quiz['id']}, Title: {$quiz['title']}\n";

        // Cek questions untuk quiz ini
        $stmt = $pdo->prepare("SELECT id, question_text FROM questions WHERE quiz_id = ? ORDER BY id LIMIT 10");
        $stmt->execute([$quiz['id']]);
        $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo "  Questions: " . count($questions) . "\n";

        foreach ($questions as $q) {
            // Cek options untuk question ini
            $stmt2 = $pdo->prepare("SELECT COUNT(*) as total, SUM(is_correct) as correct_count FROM options WHERE question_id = ?");
            $stmt2->execute([$q['id']]);
            $opt = $stmt2->fetch(PDO::FETCH_ASSOC);

            echo "    Q{$q['id']}: total_options={$opt['total']}, correct_options={$opt['correct_count']}\n";
        }
    }

    // Cari semua questions yang tidak punya correct option
    echo "\n=== QUESTIONS TANPA JAWABAN BENAR ===\n";
    $stmt = $pdo->query("
        SELECT q.id, q.question_text, qu.title as quiz_title
        FROM questions q
        JOIN quizzes qu ON q.quiz_id = qu.id
        WHERE q.id NOT IN (
            SELECT DISTINCT question_id FROM options WHERE is_correct = 1
        )
        ORDER BY q.id
        LIMIT 20
    ");
    $badQuestions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($badQuestions)) {
        echo "Tidak ada questions tanpa jawaban benar.\n";
    } else {
        echo "Ditemukan " . count($badQuestions) . " questions tanpa jawaban benar:\n";
        foreach ($badQuestions as $q) {
            echo "  Q{$q['id']}: {$q['question_text']} (Quiz: {$q['quiz_title']})\n";
        }
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
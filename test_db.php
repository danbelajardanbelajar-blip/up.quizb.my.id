<?php
require_once __DIR__ . '/config/db.php';

try {
    $stats = DB::all(
        "SELECT 
            q.id AS question_id,
            q.text AS question_text,
            qz.title AS quiz_title,
            q.type AS question_type,
            SUM(CASE WHEN aa.option_id IS NOT NULL THEN 1 ELSE 0 END) AS total_answered,
            SUM(CASE WHEN aa.option_id IS NOT NULL AND aa.is_correct = 0 THEN 1 ELSE 0 END) AS wrong_count,
            SUM(CASE WHEN aa.option_id IS NOT NULL AND aa.is_correct = 1 THEN 1 ELSE 0 END) AS correct_count
         FROM questions q
         JOIN quizzes qz ON q.quiz_id = qz.id
         LEFT JOIN attempt_answers aa ON aa.question_id = q.id
         WHERE qz.is_published = 1
         GROUP BY q.id, q.text, qz.title, q.type
         HAVING total_answered > 0
         ORDER BY wrong_count DESC, total_answered DESC
         LIMIT 100"
    );
    print_r($stats);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}

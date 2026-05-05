<?php
$config = include 'config/db.php';
try {
    $pdo = new PDO('mysql:host='.$config['host'].';dbname='.$config['database'], $config['username'], $config['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Check correct options for quiz 626
    $stmt = $pdo->prepare('SELECT o.id, o.question_id, o.option_text, o.is_correct FROM options o INNER JOIN questions q ON q.id = o.question_id WHERE q.quiz_id = 626 AND o.is_correct = 1');
    $stmt->execute();
    $correct = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo 'Correct options for quiz 626: ' . count($correct) . PHP_EOL;
    foreach ($correct as $opt) {
        echo 'Question ' . $opt['question_id'] . ': ' . substr($opt['option_text'], 0, 50) . '...' . PHP_EOL;
    }

    // Check total questions
    $stmt = $pdo->prepare('SELECT COUNT(*) as total FROM questions WHERE quiz_id = 626');
    $stmt->execute();
    $total = $stmt->fetch(PDO::FETCH_ASSOC);
    echo 'Total questions: ' . $total['total'] . PHP_EOL;

    // Check all options for first question
    $stmt = $pdo->prepare('SELECT q.id as qid, o.id as oid, o.option_text, o.is_correct FROM questions q LEFT JOIN options o ON q.id = o.question_id WHERE q.quiz_id = 626 ORDER BY q.id, o.order_num LIMIT 10');
    $stmt->execute();
    $allOptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo PHP_EOL . 'First few options:' . PHP_EOL;
    foreach ($allOptions as $opt) {
        echo 'Q' . $opt['qid'] . ' O' . $opt['oid'] . ' [' . $opt['is_correct'] . ']: ' . substr($opt['option_text'], 0, 30) . '...' . PHP_EOL;
    }

} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
?>
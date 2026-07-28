<?php require "config/db.php"; $pdo = DB::conn(); print_r($pdo->query("SELECT id, title, category_id FROM quizzes ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC));

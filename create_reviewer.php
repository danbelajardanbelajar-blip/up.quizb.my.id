<?php
require_once __DIR__ . '/config/db.php';

$email = 'reviewer@quizb.my.id';
$password = 'QuizBReviewer2026!';
$name = 'Google Play Reviewer';
$hash = password_hash($password, PASSWORD_BCRYPT);

$existing = DB::one('SELECT id FROM users WHERE email = ?', [$email]);
if ($existing) {
    DB::execute(
        'UPDATE users SET name = ?, password_hash = ?, is_active = 1, email_verified_at = NOW(), has_onboarded = 1, role = "pengajar" WHERE email = ?',
        [$name, $hash, $email]
    );
    echo "Reviewer account updated successfully.\n";
} else {
    DB::execute(
        'INSERT INTO users (name, email, password_hash, role, is_active, email_verified_at, has_onboarded) VALUES (?, ?, ?, "pengajar", 1, NOW(), 1)',
        [$name, $email, $hash]
    );
    echo "Reviewer account created successfully.\n";
}

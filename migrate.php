<?php
/**
 * migrate.php — Skrip Migrasi Server-Side
 * quic1934_quiz_lama → quic1934_upgrade
 *
 * CARA PAKAI:
 * 1. Upload file ini ke root server (up.quizb.my.id/)
 * 2. Buka di browser: https://up.quizb.my.id/migrate.php?key=RAHASIA123
 * 3. Tunggu selesai (bisa 1-5 menit tergantung ukuran data)
 * 4. Hapus file ini setelah selesai!
 *
 * KEAMANAN: Ganti SECRET_KEY di bawah sebelum upload!
 */

define('SECRET_KEY', 'RAHASIA123');   // ← GANTI INI sebelum upload!
define('DB_OLD',  'quic1934_quiz_lama');
define('DB_NEW',  'quic1934_upgrade');
define('DB_HOST', 'localhost');
define('DB_USER', 'quic1934_q');      // ← Ganti dengan user MySQL kamu
define('DB_PASS', '');                 // ← Ganti dengan password MySQL kamu

// -------------------------------------------------------
// Security check
// -------------------------------------------------------
if (($_GET['key'] ?? '') !== SECRET_KEY) {
    http_response_code(403);
    die('<h1>403 Forbidden</h1><p>Akses ditolak. Tambahkan ?key=SECRET_KEY di URL.</p>');
}

// -------------------------------------------------------
// Setup
// -------------------------------------------------------
set_time_limit(0);
ini_set('memory_limit', '512M');
header('Content-Type: text/html; charset=utf-8');
ob_implicit_flush(true);
ob_end_flush();

function out(string $msg, string $type = 'info'): void {
    $colors = ['info' => '#333', 'ok' => '#16a34a', 'error' => '#dc2626', 'warn' => '#d97706', 'head' => '#4f46e5'];
    $color  = $colors[$type] ?? '#333';
    echo "<p style='margin:2px 0;color:{$color};font-family:monospace'>{$msg}</p>";
    flush();
}

function hr(): void {
    echo "<hr style='border-color:#e5e7eb;margin:8px 0'>";
    flush();
}

echo "<!DOCTYPE html><html><head><meta charset='utf-8'>
<title>QuizB Migration</title>
<style>body{max-width:900px;margin:20px auto;padding:20px;font-family:sans-serif;background:#f9fafb}
h2{color:#4f46e5} .box{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px;margin:12px 0}
.stat{display:inline-block;background:#ede9fe;color:#4f46e5;padding:4px 12px;border-radius:999px;margin:3px;font-size:14px}
</style></head><body>";

echo "<h2>🚀 Migrasi QuizB: {$_ENV['HOSTNAME'] ?? 'server'}</h2>";
echo "<div class='box'><b>DB Lama:</b> " . DB_OLD . " &nbsp;→&nbsp; <b>DB Baru:</b> " . DB_NEW . "</div>";

// -------------------------------------------------------
// Koneksi ke DB baru (aktif)
// -------------------------------------------------------
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NEW . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, FOREIGN_KEY_CHECKS=0, SQL_MODE=''",
        ]
    );
    out("✅ Koneksi ke <b>" . DB_NEW . "</b> berhasil", 'ok');
} catch (Exception $e) {
    out("❌ Koneksi gagal: " . $e->getMessage(), 'error');
    die("</body></html>");
}

// -------------------------------------------------------
// Helper: count
// -------------------------------------------------------
function count_table(PDO $pdo, string $db, string $table): int {
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM `{$db}`.`{$table}`")->fetchColumn();
    } catch (Exception $e) {
        return -1;
    }
}

// -------------------------------------------------------
// STEP 0: Tampilkan jumlah data di kedua DB
// -------------------------------------------------------
hr();
out("<b>📊 Data di DB lama (" . DB_OLD . "):</b>", 'head');

$oldCounts = [
    'themes'      => count_table($pdo, DB_OLD, 'themes'),
    'subthemes'   => count_table($pdo, DB_OLD, 'subthemes'),
    'quiz_titles' => count_table($pdo, DB_OLD, 'quiz_titles'),
    'questions'   => count_table($pdo, DB_OLD, 'questions'),
    'choices'     => count_table($pdo, DB_OLD, 'choices'),
];

foreach ($oldCounts as $t => $c) {
    if ($c < 0) out("  ⚠️  {$t}: tidak bisa diakses (cek privilege)", 'warn');
    else        echo "<span class='stat'>{$t}: <b>{$c}</b></span>";
}
echo "<br>";

if ($oldCounts['questions'] < 0) {
    out("❌ User MySQL tidak punya akses ke DB lama. Tambahkan privilege dulu:", 'error');
    out("GRANT ALL ON `" . DB_OLD . "`.* TO '" . DB_USER . "'@'localhost'; FLUSH PRIVILEGES;", 'error');
    die("</body></html>");
}

// -------------------------------------------------------
// STEP 1: Bersihkan data lama di DB baru
// -------------------------------------------------------
hr();
out("<b>🗑️  STEP 1: Bersihkan data lama di DB baru</b>", 'head');

$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$tables = ['assignment_submissions','attempt_answers','attempts','options','questions','quizzes','categories'];
foreach ($tables as $t) {
    $pdo->exec("DELETE FROM `" . DB_NEW . "`.`{$t}`");
    $pdo->exec("ALTER TABLE `" . DB_NEW . "`.`{$t}` AUTO_INCREMENT = 1");
    out("  Cleared: {$t}");
}
out("✅ Data lama dibersihkan", 'ok');

// -------------------------------------------------------
// STEP 2: Import categories dari subthemes
// -------------------------------------------------------
hr();
out("<b>📁 STEP 2: Import Categories dari subthemes</b>", 'head');

$inserted = $pdo->exec("
    INSERT INTO `" . DB_NEW . "`.`categories`
        (`id`, `name`, `slug`, `description`, `icon`, `color`, `quiz_count`, `created_at`)
    SELECT
        s.id,
        s.name,
        LOWER(REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(s.name,'[^a-zA-Z0-9 ]',''),' +','-'),'-+','-')),
        CONCAT('Kategori: ', s.name, ' (', t.name, ')'),
        CASE t.id
            WHEN 4  THEN '🕌' WHEN 2  THEN '🔬' WHEN 3  THEN '📜'
            WHEN 5  THEN '🌍' WHEN 45 THEN '🗣️' ELSE '📚'
        END,
        CASE t.id
            WHEN 4  THEN '#10b981' WHEN 2  THEN '#06b6d4' WHEN 3  THEN '#f59e0b'
            WHEN 5  THEN '#6366f1' WHEN 45 THEN '#8b5cf6' ELSE '#6366f1'
        END,
        0,
        s.created_at
    FROM `" . DB_OLD . "`.`subthemes` s
    JOIN `" . DB_OLD . "`.`themes` t ON s.theme_id = t.id
    WHERE s.deleted_at IS NULL
    ORDER BY s.id
");
out("✅ Categories diimport: <b>{$inserted}</b> kategori", 'ok');

// -------------------------------------------------------
// STEP 3: Import quizzes dari quiz_titles
// -------------------------------------------------------
hr();
out("<b>📋 STEP 3: Import Quizzes dari quiz_titles</b>", 'head');

$inserted = $pdo->exec("
    INSERT INTO `" . DB_NEW . "`.`quizzes`
        (`id`, `category_id`, `title`, `slug`, `description`,
         `duration`, `time_limit`, `difficulty`,
         `total_questions`, `total_attempts`, `passing_score`,
         `max_attempts`, `is_published`, `created_by`, `created_at`)
    SELECT
        qt.id,
        qt.subtheme_id,
        qt.title,
        CONCAT(
            LOWER(REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(qt.title,'[^a-zA-Z0-9 ]',''),' +','-'),'-+','-')),
            '-', qt.id
        ),
        CONCAT('Paket soal: ', qt.title),
        600, 600, 'medium', 0, 0, 60, 0, 1, 1,
        qt.created_at
    FROM `" . DB_OLD . "`.`quiz_titles` qt
    WHERE qt.deleted_at IS NULL
      AND qt.subtheme_id IN (SELECT id FROM `" . DB_NEW . "`.`categories`)
    ORDER BY qt.id
");
out("✅ Quizzes diimport: <b>{$inserted}</b> paket soal", 'ok');

// -------------------------------------------------------
// STEP 4: Import questions — batch per quiz_id
// -------------------------------------------------------
hr();
out("<b>❓ STEP 4: Import Questions (batch per quiz)</b>", 'head');

// Ambil semua quiz_id yang ada di DB baru
$quizIds = $pdo->query("SELECT id FROM `" . DB_NEW . "`.`quizzes` ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
out("  Total quizzes: " . count($quizIds));

$totalQ    = 0;
$batchSize = 50; // proses 50 quiz sekaligus

$chunks = array_chunk($quizIds, $batchSize);
foreach ($chunks as $i => $chunk) {
    $placeholders = implode(',', array_fill(0, count($chunk), '?'));
    $n = $pdo->prepare("
        INSERT INTO `" . DB_NEW . "`.`questions`
            (`id`, `quiz_id`, `question_text`, `type`, `points`, `order_num`, `explanation`)
        SELECT
            q.id,
            q.title_id,
            q.text,
            'multiple',
            10,
            q.id,
            q.explanation
        FROM `" . DB_OLD . "`.`questions` q
        WHERE q.title_id IN ({$placeholders})
        ORDER BY q.title_id, q.id
    ")->execute($chunk);
    // hitung rows inserted
    $count = (int)$pdo->query("SELECT ROW_COUNT()")->fetchColumn();
    $totalQ += $count;
    out("  Batch " . ($i+1) . "/" . count($chunks) . ": +{$count} soal (total: {$totalQ})");
}
out("✅ Questions diimport: <b>{$totalQ}</b> soal", 'ok');

// -------------------------------------------------------
// STEP 5: Import options (choices) — batch per quiz
// -------------------------------------------------------
hr();
out("<b>🔘 STEP 5: Import Options (batch per quiz)</b>", 'head');

$totalO    = 0;
$quizIds2  = $pdo->query("SELECT id FROM `" . DB_NEW . "`.`quizzes` ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$chunks2   = array_chunk($quizIds2, $batchSize);

foreach ($chunks2 as $i => $chunk) {
    $placeholders = implode(',', array_fill(0, count($chunk), '?'));
    
    // Ambil question_ids dari quiz batch ini
    $stmt = $pdo->prepare("SELECT id FROM `" . DB_NEW . "`.`questions` WHERE quiz_id IN ({$placeholders}) ORDER BY id");
    $stmt->execute($chunk);
    $questionIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($questionIds)) continue;
    
    $qPlaceholders = implode(',', array_fill(0, count($questionIds), '?'));
    
    // Insert options dengan ROW_NUMBER untuk order_num
    $pdo->prepare("
        INSERT INTO `" . DB_NEW . "`.`options`
            (`id`, `question_id`, `option_text`, `is_correct`, `order_num`)
        SELECT
            c.id,
            c.question_id,
            c.text,
            c.is_correct,
            ROW_NUMBER() OVER (PARTITION BY c.question_id ORDER BY c.id) AS order_num
        FROM `" . DB_OLD . "`.`choices` c
        WHERE c.question_id IN ({$qPlaceholders})
        ORDER BY c.question_id, c.id
    ")->execute($questionIds);
    
    $count = (int)$pdo->query("SELECT ROW_COUNT()")->fetchColumn();
    $totalO += $count;
    out("  Batch " . ($i+1) . "/" . count($chunks2) . ": +{$count} opsi (total: {$totalO})");
}
out("✅ Options diimport: <b>{$totalO}</b> pilihan", 'ok');

// -------------------------------------------------------
// STEP 6: Update total_questions per quiz
// -------------------------------------------------------
hr();
out("<b>🔢 STEP 6: Update total_questions</b>", 'head');
$pdo->exec("
    UPDATE `" . DB_NEW . "`.`quizzes` q
    SET q.total_questions = (
        SELECT COUNT(*) FROM `" . DB_NEW . "`.`questions` WHERE quiz_id = q.id
    )
");
out("✅ total_questions diupdate", 'ok');

// -------------------------------------------------------
// STEP 7: Update quiz_count per category
// -------------------------------------------------------
$pdo->exec("
    UPDATE `" . DB_NEW . "`.`categories` c
    SET c.quiz_count = (
        SELECT COUNT(*) FROM `" . DB_NEW . "`.`quizzes` WHERE category_id = c.id AND total_questions > 0
    )
");
out("✅ quiz_count diupdate", 'ok');

// -------------------------------------------------------
// STEP 8: Rapikan order_num questions
// -------------------------------------------------------
hr();
out("<b>🔢 STEP 8: Rapikan order_num questions</b>", 'head');
$pdo->exec("
    UPDATE `" . DB_NEW . "`.`questions` q
    JOIN (
        SELECT id,
               ROW_NUMBER() OVER (PARTITION BY quiz_id ORDER BY id) AS rn
        FROM `" . DB_NEW . "`.`questions`
    ) ranked ON q.id = ranked.id
    SET q.order_num = ranked.rn
");
out("✅ order_num dirapikan", 'ok');

// -------------------------------------------------------
// STEP 9: Hapus quizzes tanpa soal
// -------------------------------------------------------
hr();
out("<b>🧹 STEP 9: Hapus quizzes kosong</b>", 'head');
$deleted = $pdo->exec("DELETE FROM `" . DB_NEW . "`.`quizzes` WHERE total_questions = 0");
out("  Dihapus: {$deleted} quiz kosong", $deleted > 0 ? 'warn' : 'ok');

// -------------------------------------------------------
// STEP 10: Update quiz_count setelah hapus quiz kosong
// -------------------------------------------------------
$pdo->exec("
    UPDATE `" . DB_NEW . "`.`categories` c
    SET c.quiz_count = (
        SELECT COUNT(*) FROM `" . DB_NEW . "`.`quizzes` WHERE category_id = c.id
    )
");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

// -------------------------------------------------------
// Summary
// -------------------------------------------------------
hr();
echo "<div class='box'>";
out("<b>📊 RINGKASAN HASIL MIGRASI</b>", 'head');

$newCounts = [
    'categories' => count_table($pdo, DB_NEW, 'categories'),
    'quizzes'    => count_table($pdo, DB_NEW, 'quizzes'),
    'questions'  => count_table($pdo, DB_NEW, 'questions'),
    'options'    => count_table($pdo, DB_NEW, 'options'),
];

foreach ($newCounts as $t => $c) {
    echo "<span class='stat'>{$t}: <b>{$c}</b></span>";
}
echo "<br><br>";

// Distribusi pilihan per soal
$dist = $pdo->query("
    SELECT pilihan_count, COUNT(*) AS jumlah_soal
    FROM (SELECT question_id, COUNT(*) AS pilihan_count FROM `" . DB_NEW . "`.`options` GROUP BY question_id) sub
    GROUP BY pilihan_count ORDER BY pilihan_count
")->fetchAll();

out("Distribusi pilihan per soal:", 'head');
foreach ($dist as $d) {
    out("  {$d['pilihan_count']} pilihan → {$d['jumlah_soal']} soal");
}

echo "</div>";

echo "<div class='box' style='background:#fef2f2;border-color:#fecaca'>";
echo "<b style='color:#dc2626'>⚠️ PENTING: Hapus file ini setelah selesai!</b><br>";
echo "File <code>migrate.php</code> adalah celah keamanan jika dibiarkan. Hapus via cPanel File Manager setelah migrasi selesai.";
echo "</div>";

echo "<div class='box' style='background:#f0fdf4;border-color:#bbf7d0'>";
echo "<b style='color:#16a34a'>✅ Migrasi selesai!</b><br>";
echo "Buka <a href='https://up.quizb.my.id' target='_blank'>https://up.quizb.my.id</a> dan cek apakah soal sudah muncul.";
echo "</div>";

echo "</body></html>";

<?php
/**
 * migrate.php — Migrasi Server-Side
 * quic1934_quiz_lama → quic1934_upgrade
 *
 * CARA PAKAI:
 * 1. Edit SECRET_KEY dan DB_OLD di bawah
 * 2. Upload/pull ke server
 * 3. Buka: https://up.quizb.my.id/migrate.php?key=ISI_SECRET_KEY_MU
 * 4. Tunggu sampai selesai, lalu HAPUS file ini!
 */

// -------------------------------------------------------
// KONFIGURASI — Edit bagian ini sebelum dijalankan
// -------------------------------------------------------
define('SECRET_KEY', 'QuizB2025Migrate');   // ← Ganti, gunakan saat buka URL
define('DB_OLD', 'quic1934_quiz_lama');      // ← nama DB lama (sudah ada di server)

// Kredensial diambil otomatis dari config/db.php yang sudah ada di server
// Pastikan file config/db.php sudah ada dan sudah dikonfigurasi!
// -------------------------------------------------------

// Security check pertama kali — sebelum apapun
if (($_GET['key'] ?? '') !== SECRET_KEY) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><title>403</title></head><body>';
    echo '<h1>403 Forbidden</h1>';
    echo '<p>Tambahkan <code>?key=SECRET_KEY</code> di URL.</p>';
    echo '</body></html>';
    exit;
}

// Load config dari file yang sudah ada
$configFile = __DIR__ . '/config/db.php';
if (!file_exists($configFile)) {
    die('<h1>Error</h1><p>File <code>config/db.php</code> tidak ditemukan. Pastikan sudah dikonfigurasi.</p>');
}
require_once $configFile;

// Ambil kredensial dari konstanta yang sudah didefinisikan di db.php
$dbHost = DB_HOST;
$dbUser = DB_USER;
$dbPass = DB_PASS;
$dbNew  = DB_NAME; // ini seharusnya quic1934_upgrade
$dbOld  = DB_OLD;

// Setup PHP
set_time_limit(300);
ini_set('memory_limit', '256M');
error_reporting(E_ALL);
ini_set('display_errors', '0'); // matikan error display, kita tangani sendiri

// Start output
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>QuizB Migration Tool</title>
<style>
  * { box-sizing: border-box; }
  body { max-width: 860px; margin: 30px auto; padding: 20px; font-family: 'Segoe UI', sans-serif; background: #f8fafc; color: #1e293b; }
  h2 { color: #4f46e5; margin-bottom: 4px; }
  .sub { color: #64748b; margin-bottom: 20px; font-size: 14px; }
  .box { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px 20px; margin: 12px 0; }
  .box.ok   { border-color: #86efac; background: #f0fdf4; }
  .box.err  { border-color: #fca5a5; background: #fef2f2; }
  .box.warn { border-color: #fcd34d; background: #fffbeb; }
  .log { font-family: monospace; font-size: 13px; line-height: 1.8; }
  .ok-t  { color: #16a34a; } .err-t  { color: #dc2626; }
  .warn-t { color: #d97706; } .head-t { color: #4f46e5; font-weight: bold; }
  .stat { display: inline-block; background: #ede9fe; color: #4f46e5; padding: 3px 10px; border-radius: 999px; margin: 2px; font-size: 13px; font-family: monospace; }
  hr { border: none; border-top: 1px solid #e2e8f0; margin: 10px 0; }
  pre { background: #1e293b; color: #e2e8f0; padding: 12px; border-radius: 8px; font-size: 12px; overflow-x: auto; }
</style>
</head>
<body>
<h2>🚀 QuizB Migration Tool</h2>
<p class="sub">Migrasi data dari <code><?= htmlspecialchars($dbOld) ?></code> → <code><?= htmlspecialchars($dbNew) ?></code></p>
<div class="box log">
<?php
flush();

function out(string $msg, string $type = ''): void {
    $cls = $type ? " class='{$type}-t'" : '';
    echo "<div{$cls}>{$msg}</div>\n";
    flush();
}

function outhr(): void { echo "<hr>"; flush(); }

// -------------------------------------------------------
// Koneksi PDO — pakai user yang sama, akses 2 DB via prefix
// -------------------------------------------------------
try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbNew};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => true,
        ]
    );
    $pdo->exec("SET NAMES utf8mb4");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $pdo->exec("SET SQL_MODE = ''");
    out("✅ Koneksi ke <b>{$dbNew}</b> berhasil", 'ok');
} catch (Exception $e) {
    out("❌ Koneksi GAGAL: " . htmlspecialchars($e->getMessage()), 'err');
    echo "</div></body></html>"; exit;
}

// -------------------------------------------------------
// Cek akses ke DB lama
// -------------------------------------------------------
outhr();
out("<b>📊 Cek akses ke DB lama ({$dbOld})</b>", 'head');

function countTable(PDO $pdo, string $db, string $table): int {
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM `{$db}`.`{$table}`")->fetchColumn();
    } catch (Exception $e) {
        return -1;
    }
}

$checks = ['themes', 'subthemes', 'quiz_titles', 'questions', 'choices'];
$canAccess = true;
foreach ($checks as $t) {
    $n = countTable($pdo, $dbOld, $t);
    if ($n < 0) {
        out("  ❌ Tidak bisa akses `{$dbOld}`.`{$t}` — user MySQL perlu privilege", 'err');
        $canAccess = false;
    } else {
        out("  ✅ {$t}: <b>{$n}</b> rows");
    }
}

if (!$canAccess) {
    out("", '');
    out("🔧 Solusi: Di cPanel → MySQL Databases, tambahkan user <b>{$dbUser}</b> ke database <b>{$dbOld}</b> dengan ALL PRIVILEGES.", 'warn');
    echo "</div></body></html>"; exit;
}

// -------------------------------------------------------
// STEP 1: Bersihkan data lama
// -------------------------------------------------------
outhr();
out("<b>🗑️  STEP 1: Bersihkan data lama di {$dbNew}</b>", 'head');

$order = ['assignment_submissions','attempt_answers','attempts','options','questions','quizzes','categories'];
foreach ($order as $t) {
    try {
        $pdo->exec("DELETE FROM `{$dbNew}`.`{$t}`");
        $pdo->exec("ALTER TABLE `{$dbNew}`.`{$t}` AUTO_INCREMENT = 1");
        out("  Cleared: {$t}");
    } catch (Exception $e) {
        out("  ⚠️ {$t}: " . htmlspecialchars($e->getMessage()), 'warn');
    }
}
out("✅ Data lama dibersihkan", 'ok');
flush();

// -------------------------------------------------------
// STEP 2: Categories
// -------------------------------------------------------
outhr();
out("<b>📁 STEP 2: Import Categories dari subthemes</b>", 'head');

try {
    $n = $pdo->exec("
        INSERT INTO `{$dbNew}`.`categories`
            (`id`,`name`,`slug`,`description`,`icon`,`color`,`quiz_count`,`created_at`)
        SELECT
            s.id, s.name,
            LOWER(REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(s.name,'[^a-zA-Z0-9 ]',''),' +','-'),'-+','-')),
            CONCAT('Kategori: ',s.name,' (',t.name,')'),
            CASE t.id WHEN 4 THEN '🕌' WHEN 2 THEN '🔬' WHEN 3 THEN '📜' WHEN 5 THEN '🌍' WHEN 45 THEN '🗣️' ELSE '📚' END,
            CASE t.id WHEN 4 THEN '#10b981' WHEN 2 THEN '#06b6d4' WHEN 3 THEN '#f59e0b' WHEN 5 THEN '#6366f1' WHEN 45 THEN '#8b5cf6' ELSE '#6366f1' END,
            0, s.created_at
        FROM `{$dbOld}`.`subthemes` s
        JOIN `{$dbOld}`.`themes` t ON s.theme_id = t.id
        WHERE s.deleted_at IS NULL
        ORDER BY s.id
    ");
    out("✅ Categories: <b>{$n}</b> kategori diimport", 'ok');
} catch (Exception $e) {
    out("❌ Categories gagal: " . htmlspecialchars($e->getMessage()), 'err');
}
flush();

// -------------------------------------------------------
// STEP 3: Quizzes
// -------------------------------------------------------
outhr();
out("<b>📋 STEP 3: Import Quizzes dari quiz_titles</b>", 'head');

try {
    $n = $pdo->exec("
        INSERT INTO `{$dbNew}`.`quizzes`
            (`id`,`category_id`,`title`,`slug`,`description`,
             `duration`,`time_limit`,`difficulty`,
             `total_questions`,`total_attempts`,`passing_score`,
             `max_attempts`,`is_published`,`created_by`,`created_at`)
        SELECT
            qt.id, qt.subtheme_id, qt.title,
            CONCAT(LOWER(REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(qt.title,'[^a-zA-Z0-9 ]',''),' +','-'),'-+','-')),'-',qt.id),
            CONCAT('Paket soal: ',qt.title),
            600, 600, 'medium', 0, 0, 60, 0, 1, 1, qt.created_at
        FROM `{$dbOld}`.`quiz_titles` qt
        WHERE qt.deleted_at IS NULL
          AND qt.subtheme_id IN (SELECT id FROM `{$dbNew}`.`categories`)
        ORDER BY qt.id
    ");
    out("✅ Quizzes: <b>{$n}</b> paket soal diimport", 'ok');
} catch (Exception $e) {
    out("❌ Quizzes gagal: " . htmlspecialchars($e->getMessage()), 'err');
}
flush();

// -------------------------------------------------------
// STEP 4: Questions — batch per 100 quiz sekaligus
// -------------------------------------------------------
outhr();
out("<b>❓ STEP 4: Import Questions (batch per 100 quiz)</b>", 'head');

$quizIds = $pdo->query("SELECT id FROM `{$dbNew}`.`quizzes` ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
out("  Total quizzes: " . count($quizIds));

$totalQ  = 0;
$chunks  = array_chunk($quizIds, 100);

foreach ($chunks as $i => $chunk) {
    $ph = implode(',', $chunk); // aman karena sudah int dari DB
    try {
        $n = $pdo->exec("
            INSERT INTO `{$dbNew}`.`questions`
                (`id`,`quiz_id`,`question_text`,`type`,`points`,`order_num`,`explanation`)
            SELECT q.id, q.title_id, q.text, 'multiple', 10, q.id, q.explanation
            FROM `{$dbOld}`.`questions` q
            WHERE q.title_id IN ({$ph})
            ORDER BY q.title_id, q.id
        ");
        $totalQ += $n;
        out("  Batch " . ($i+1) . "/" . count($chunks) . ": +{$n} soal (akumulasi: {$totalQ})");
    } catch (Exception $e) {
        out("  ❌ Batch " . ($i+1) . " gagal: " . htmlspecialchars($e->getMessage()), 'err');
    }
    flush();
}
out("✅ Questions total: <b>{$totalQ}</b> soal", 'ok');
flush();

// -------------------------------------------------------
// STEP 5: Options — batch per 100 quiz
// -------------------------------------------------------
outhr();
out("<b>🔘 STEP 5: Import Options (batch per 100 quiz)</b>", 'head');

$totalO = 0;
$quizIds2 = $pdo->query("SELECT id FROM `{$dbNew}`.`quizzes` ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$chunks2  = array_chunk($quizIds2, 100);

foreach ($chunks2 as $i => $chunk) {
    $ph = implode(',', $chunk);

    // Ambil question_ids dari chunk ini
    $qids = $pdo->query("SELECT id FROM `{$dbNew}`.`questions` WHERE quiz_id IN ({$ph}) ORDER BY id")
                ->fetchAll(PDO::FETCH_COLUMN);
    if (empty($qids)) continue;

    $qph = implode(',', $qids);

    try {
        // Gunakan variabel user-level untuk kompatibilitas MariaDB 10.x
        $pdo->exec("SET @rn=0, @prev=0");
        $n = $pdo->exec("
            INSERT INTO `{$dbNew}`.`options`
                (`id`,`question_id`,`option_text`,`is_correct`,`order_num`)
            SELECT c.id, c.question_id, c.text, c.is_correct,
                   @rn := IF(@prev = c.question_id, @rn+1, 1),
                   @prev := c.question_id
            FROM `{$dbOld}`.`choices` c
            WHERE c.question_id IN ({$qph})
            ORDER BY c.question_id, c.id
        ");
        $totalO += $n;
        out("  Batch " . ($i+1) . "/" . count($chunks2) . ": +{$n} opsi (akumulasi: {$totalO})");
    } catch (Exception $e) {
        out("  ❌ Batch " . ($i+1) . " gagal: " . htmlspecialchars($e->getMessage()), 'err');
    }
    flush();
}
out("✅ Options total: <b>{$totalO}</b> pilihan", 'ok');
flush();

// -------------------------------------------------------
// STEP 6 & 7: Update counts
// -------------------------------------------------------
outhr();
out("<b>🔢 STEP 6-7: Update total_questions &amp; quiz_count</b>", 'head');
$pdo->exec("UPDATE `{$dbNew}`.`quizzes` q SET q.total_questions=(SELECT COUNT(*) FROM `{$dbNew}`.`questions` WHERE quiz_id=q.id)");
out("  ✅ total_questions diupdate");
$pdo->exec("UPDATE `{$dbNew}`.`categories` c SET c.quiz_count=(SELECT COUNT(*) FROM `{$dbNew}`.`quizzes` WHERE category_id=c.id AND total_questions>0)");
out("  ✅ quiz_count diupdate");
flush();

// -------------------------------------------------------
// STEP 8: Rapikan order_num
// -------------------------------------------------------
outhr();
out("<b>🔢 STEP 8: Rapikan order_num questions</b>", 'head');
try {
    $pdo->exec("SET @pq=0, @o=0");
    $pdo->exec("
        UPDATE `{$dbNew}`.`questions` q
        JOIN (
            SELECT id,
                   @o := IF(@pq=quiz_id,@o+1,1) AS rn,
                   @pq := quiz_id AS _d
            FROM `{$dbNew}`.`questions` ORDER BY quiz_id, id
        ) ranked ON q.id=ranked.id
        SET q.order_num=ranked.rn
    ");
    out("  ✅ order_num dirapikan");
} catch (Exception $e) {
    out("  ⚠️ order_num: " . htmlspecialchars($e->getMessage()), 'warn');
}
flush();

// -------------------------------------------------------
// STEP 9: Hapus quiz kosong
// -------------------------------------------------------
$deleted = $pdo->exec("DELETE FROM `{$dbNew}`.`quizzes` WHERE total_questions=0");
out("  🧹 Quiz kosong dihapus: {$deleted}");
$pdo->exec("UPDATE `{$dbNew}`.`categories` c SET c.quiz_count=(SELECT COUNT(*) FROM `{$dbNew}`.`quizzes` WHERE category_id=c.id)");
$pdo->exec("SET FOREIGN_KEY_CHECKS=1");
flush();

// -------------------------------------------------------
// Ringkasan akhir
// -------------------------------------------------------
outhr();
out("<b>📊 RINGKASAN MIGRASI</b>", 'head');
$finals = ['categories','quizzes','questions','options'];
foreach ($finals as $t) {
    $c = countTable($pdo, $dbNew, $t);
    echo "<span class='stat'>{$t}: <b>{$c}</b></span>";
}
echo "<br><br>";

// Distribusi pilihan per soal
$dist = $pdo->query("
    SELECT pilihan_count, COUNT(*) AS soal
    FROM (SELECT question_id, COUNT(*) AS pilihan_count FROM `{$dbNew}`.`options` GROUP BY question_id) s
    GROUP BY pilihan_count ORDER BY pilihan_count
")->fetchAll();

out("Distribusi pilihan per soal:");
foreach ($dist as $d) {
    out("&nbsp;&nbsp;{$d['pilihan_count']} pilihan → <b>{$d['soal']}</b> soal");
}
?>
</div>

<div class="box warn">
    <b>⚠️ PENTING — Hapus file ini sekarang!</b><br>
    File <code>migrate.php</code> adalah celah keamanan. Hapus via <b>cPanel → File Manager</b> setelah migrasi selesai.
</div>
<div class="box ok">
    <b>✅ Migrasi selesai!</b><br>
    Buka <a href="https://up.quizb.my.id" target="_blank">https://up.quizb.my.id</a> dan coba buka salah satu quiz — soal seharusnya sudah muncul.
</div>
</body>
</html>

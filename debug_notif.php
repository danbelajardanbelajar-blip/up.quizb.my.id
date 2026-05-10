<?php
// debug_notif.php — Hapus setelah selesai debug!
// Akses via: https://up.quizb.my.id/debug_notif.php?key=quizb2024

if (($_GET['key'] ?? '') !== 'quizb2024') { http_response_code(403); die('Forbidden'); }

require_once __DIR__ . '/config/db.php';

header('Content-Type: text/plain; charset=utf-8');
$out = [];

// 1. Cek tabel notifications
try {
    $r = DB::one("SELECT COUNT(*) AS cnt FROM notifications");
    $out[] = "✅ Tabel notifications ADA — total rows: " . $r['cnt'];
} catch (Throwable $e) {
    $out[] = "❌ Tabel notifications TIDAK ADA atau error: " . $e->getMessage();
    echo implode("\n", $out);
    exit;
}

// 2. Cek user aktif
$users = DB::all("SELECT id, name, email, role, is_active FROM users WHERE is_active = 1 ORDER BY id");
$out[] = "\n— User aktif (is_active=1): " . count($users);
foreach ($users as $u) {
    $out[] = "   id={$u['id']} | {$u['name']} | {$u['role']}";
}

// 3. Cek user tidak aktif (anon, dll)
$inactive = DB::one("SELECT COUNT(*) AS cnt FROM users WHERE is_active = 0");
$out[] = "— User tidak aktif (is_active=0): " . $inactive['cnt'];

// 4. Coba insert notifikasi test ke user aktif pertama
$firstUser = DB::one("SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 1");
if ($firstUser) {
    try {
        DB::execute(
            "INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, ?, ?, ?, ?)",
            [(int)$firstUser['id'], 'system', '🧪 Test Debug', 'Notifikasi test dari debug_notif.php', '/dashboard']
        );
        $out[] = "\n✅ INSERT notifikasi test BERHASIL ke user_id=" . $firstUser['id'];
    } catch (Throwable $e) {
        $out[] = "\n❌ INSERT notifikasi GAGAL: " . $e->getMessage();
    }
} else {
    $out[] = "\n⚠️  Tidak ada user aktif untuk test insert.";
}

// 5. Cek 5 notif terbaru
$recent = DB::all("SELECT id, user_id, type, title, is_read, created_at FROM notifications ORDER BY id DESC LIMIT 5");
$out[] = "\n— 5 Notifikasi terbaru:";
foreach ($recent as $n) {
    $out[] = "   id={$n['id']} | user_id={$n['user_id']} | type={$n['type']} | title={$n['title']} | read={$n['is_read']} | {$n['created_at']}";
}

// 6. Cek apakah ada notif type=new_user atau new_question
$newTypes = DB::all("SELECT id, user_id, type, title, created_at FROM notifications WHERE type IN ('new_user','new_question') ORDER BY id DESC LIMIT 10");
$out[] = "\n— Notif type new_user / new_question (max 10):";
if (empty($newTypes)) {
    $out[] = "   ❌ Kosong — belum ada notif tipe ini di DB!";
} else {
    foreach ($newTypes as $n) {
        $out[] = "   id={$n['id']} | user_id={$n['user_id']} | type={$n['type']} | {$n['created_at']}";
    }
}

echo implode("\n", $out) . "\n";

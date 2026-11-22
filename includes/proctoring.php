<?php
require_once __DIR__ . '/proctoring-verification.php';
// ============================================
// includes/proctoring.php — AI Proctoring Helpers & Database Management
// ============================================

if (!defined('APP_INIT') && !defined('APP_ENV') && !class_exists('DB', false)) {
    // Memastikan bootstrap database tersedia
    require_once __DIR__ . '/../config/db.php';
}

/**
 * Memastikan tabel dan kolom sistem pengawasan AI (Proctoring) tersedia di database.
 */
function ensureProctoringTables(): void {
    static $ready = false;
    if ($ready) return;

    try {
        // 1. Pastikan kolom exam_reference_photo & exam_reference_updated_at tersedia di tabel users
        $colPhoto = DB::one("SHOW COLUMNS FROM users LIKE 'exam_reference_photo'");
        if (!$colPhoto) {
            DB::execute("ALTER TABLE users ADD COLUMN exam_reference_photo VARCHAR(255) NULL DEFAULT NULL AFTER role");
        }

        $colDate = DB::one("SHOW COLUMNS FROM users LIKE 'exam_reference_updated_at'");
        if (!$colDate) {
            DB::execute("ALTER TABLE users ADD COLUMN exam_reference_updated_at DATETIME NULL DEFAULT NULL AFTER exam_reference_photo");
        }

        // 2. Buat tabel assignment_proctoring_logs jika belum ada
        DB::execute("CREATE TABLE IF NOT EXISTS assignment_proctoring_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            assignment_id INT NOT NULL,
            attempt_id INT NULL DEFAULT NULL,
            user_id INT NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            similarity_score FLOAT NULL DEFAULT NULL,
            status VARCHAR(50) NOT NULL,
            notes TEXT NULL DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_assign_user (assignment_id, user_id),
            INDEX idx_attempt (attempt_id),
            INDEX idx_event (event_type),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 3. Pastikan folder penyimpanan dilindungi ketat (.htaccess)
        ensureProctoringProtectedDirectories();

        $ready = true;
    } catch (Throwable $e) {
        error_log('[Proctoring Table Setup Error] ' . $e->getMessage());
    }
}

/**
 * Memastikan direktori penyimpanan acuan aman dari unduhan publik langsung
 */
function ensureProctoringProtectedDirectories(): void {
    $dirs = [
        __DIR__ . '/../uploads/references' => "Require all denied\nOptions -Indexes -ExecCGI\n"
    ];

    foreach ($dirs as $dir => $htaccessContent) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $htFile = $dir . '/.htaccess';
        if (!is_file($htFile)) {
            @file_put_contents($htFile, $htaccessContent, LOCK_EX);
        }
    }
}

/**
 * Mendapatkan status foto acuan ujian milik user
 */
function getUserExamReference(int $userId): array {
    if ($userId <= 0) {
        return ['has_reference' => false, 'photo_path' => null, 'updated_at' => null];
    }

    ensureProctoringTables();

    $row = DB::one(
        "SELECT id, exam_reference_photo, exam_reference_updated_at FROM users WHERE id = ?",
        [$userId]
    );

    if (!$row || empty($row['exam_reference_photo'])) {
        return ['has_reference' => false, 'photo_path' => null, 'updated_at' => null];
    }

    $photoPath = trim($row['exam_reference_photo']);
    $fullPath  = __DIR__ . '/../' . ltrim(str_replace('\\', '/', $photoPath), '/');

    if (!is_file($fullPath)) {
        return ['has_reference' => false, 'photo_path' => null, 'updated_at' => null];
    }

    return [
        'has_reference' => true,
        'photo_path'    => $photoPath,
        'updated_at'    => $row['exam_reference_updated_at'] ?? null
    ];
}

/**
 * Menyimpan foto acuan awal ujian (Hands-Free Zero-Click Reference Capture)
 * Hanya dapat dipanggil sekali oleh mahasiswa, atau direset oleh Admin/Pengajar.
 */
function saveExamReferencePhoto(int $userId, string $photoData, bool $isResetOrAdmin = false): array {
    ensureProctoringTables();

    if ($userId <= 0) {
        return ['success' => false, 'message' => 'Identitas pengguna tidak valid.'];
    }

    // Cek apakah user sudah memiliki foto acuan sebelumnya
    $existing = getUserExamReference($userId);
    if ($existing['has_reference'] && !$isResetOrAdmin) {
        return [
            'success'       => true,
            'already_saved' => true,
            'message'       => 'Foto acuan ujian sudah tersimpan sebelumnya dan tidak dapat diubah sendiri.',
            'path'          => $existing['photo_path']
        ];
    }

    // Ekstrak base64 gambar
    $cleanData = trim($photoData);
    if (strpos($cleanData, ',') !== false) {
        @list(, $cleanData) = explode(',', $cleanData, 2);
    }

    $binary = base64_decode($cleanData, true);
    if (!$binary || strlen($binary) < 500) {
        return ['success' => false, 'message' => 'Data gambar tidak valid atau terlalu kecil.'];
    }

    if (strlen($binary) > 7 * 1024 * 1024) {
        return ['success' => false, 'message' => 'Ukuran file foto maksimal 7 MB.'];
    }

    if (!function_exists('imagecreatefromstring')) {
        return ['success' => false, 'message' => 'Ekstensi GD image server belum tersedia.'];
    }

    $info = @getimagesizefromstring($binary);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        return ['success' => false, 'message' => 'Format gambar wajib JPG, PNG, atau WEBP.'];
    }

    $origW = $info[0];
    $origH = $info[1];
    if ($origW < 200 || $origH < 200) {
        return ['success' => false, 'message' => 'Resolusi foto terlalu rendah.'];
    }

    $src = @imagecreatefromstring($binary);
    if (!$src) {
        return ['success' => false, 'message' => 'Gagal memproses gambar foto.'];
    }

    // Preserve the full frame. YuNet detects and SFace aligns both photos identically.
    $scale=min(1.0,640/max($origW,$origH));
    $targetW=(int)round($origW*$scale);$targetH=(int)round($origH*$scale);
    $canvas=imagecreatetruecolor($targetW,$targetH);
    imagecopyresampled($canvas,$src,0,0,0,0,$targetW,$targetH,$origW,$origH);

    $refDir = __DIR__ . '/../uploads/references';
    ensureProctoringProtectedDirectories();

    $fileName = 'ref_' . $userId . '_' . bin2hex(random_bytes(12)) . '.jpg';
    $targetFile = $refDir . '/' . $fileName;

    $saved = @imagejpeg($canvas, $targetFile, 85);
    imagedestroy($canvas);
    imagedestroy($src);

    if (!$saved || !is_file($targetFile)) {
        return ['success' => false, 'message' => 'Gagal menyimpan file foto ke penyimpanan server.'];
    }

    $relativePath = 'uploads/references/' . $fileName;

    // Hapus file lama jika ada
    if (!empty($existing['photo_path'])) {
        $oldFile = __DIR__ . '/../' . ltrim(str_replace('\\', '/', $existing['photo_path']), '/');
        if (is_file($oldFile) && $oldFile !== $targetFile) {
            @unlink($oldFile);
        }
    }

    // Update record user di database
    DB::execute(
        "UPDATE users SET exam_reference_photo = ?, exam_reference_updated_at = NOW() WHERE id = ?",
        [$relativePath, $userId]
    );

    return [
        'success' => true,
        'message' => 'Foto acuan ujian berhasil disimpan.',
        'path'    => $relativePath
    ];
}

/**
 * Reset foto acuan ujian mahasiswa oleh Admin atau Pengajar
 */
function resetExamReferencePhoto(int $userId): bool {
    ensureProctoringTables();

    $user = DB::one("SELECT exam_reference_photo FROM users WHERE id = ?", [$userId]);
    if (!$user) return false;

    if (!empty($user['exam_reference_photo'])) {
        $oldFile = __DIR__ . '/../' . ltrim(str_replace('\\', '/', $user['exam_reference_photo']), '/');
        if (is_file($oldFile)) {
            @unlink($oldFile);
        }
    }

    DB::execute(
        "UPDATE users SET exam_reference_photo = NULL, exam_reference_updated_at = NOW() WHERE id = ?",
        [$userId]
    );

    return true;
}

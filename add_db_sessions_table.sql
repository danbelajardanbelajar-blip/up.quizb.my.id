-- ============================================
-- PATCH: Tambah tabel db_sessions
-- Jalankan sekali di database production.
-- Aman dijalankan berulang (IF NOT EXISTS).
-- ============================================

CREATE TABLE IF NOT EXISTS `db_sessions` (
  `id`            VARCHAR(128)   NOT NULL          COMMENT 'PHP session ID',
  `user_id`       INT UNSIGNED       NULL DEFAULT NULL COMMENT 'NULL = belum login / tamu',
  `data`          MEDIUMBLOB     NOT NULL          COMMENT 'Data session ter-serialisasi',
  `last_activity` INT UNSIGNED   NOT NULL          COMMENT 'Unix timestamp aktivitas terakhir',
  `created_at`    INT UNSIGNED   NOT NULL          COMMENT 'Unix timestamp saat session dibuat',
  PRIMARY KEY (`id`),
  KEY `idx_user_id`       (`user_id`),
  KEY `idx_last_activity` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Migration: Rumpun Kategori (Category Groups)
-- Jalankan script ini di MySQL server kamu
-- ============================================

-- 1. Buat tabel rumpun
CREATE TABLE IF NOT EXISTS `category_groups` (
  `id`          INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(100)   NOT NULL,
  `icon`        VARCHAR(10)    NOT NULL DEFAULT '📚',
  `color`       VARCHAR(20)    NOT NULL DEFAULT '#6366f1',
  `description` TEXT           DEFAULT NULL,
  `order_num`   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tambah kolom group_id ke tabel categories
ALTER TABLE `categories`
  ADD COLUMN IF NOT EXISTS `group_id` INT UNSIGNED DEFAULT NULL AFTER `id`,
  ADD CONSTRAINT `fk_category_group`
    FOREIGN KEY (`group_id`) REFERENCES `category_groups` (`id`)
    ON DELETE SET NULL;

-- 3. Insert 5 rumpun default
INSERT INTO `category_groups` (`name`, `icon`, `color`, `order_num`) VALUES
  ('Pengetahuan Alam',   '🌿', '#10b981', 1),
  ('Pengetahuan Sosial', '🌍', '#3b82f6', 2),
  ('Pengetahuan Agama',  '🕌', '#f59e0b', 3),
  ('Pengetahuan Umum',   '💡', '#8b5cf6', 4),
  ('Pengetahuan Bahasa', '📖', '#ec4899', 5);

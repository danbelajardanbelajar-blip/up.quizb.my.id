-- ============================================================
-- QuizB — Notifikasi & Pesan
-- Jalankan sekali di server MySQL kamu
-- ============================================================

-- 1. Notifikasi per-user
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`         INT          NOT NULL AUTO_INCREMENT,
  `user_id`    INT          NOT NULL,
  `type`       VARCHAR(50)  NOT NULL DEFAULT 'system',
  `title`      VARCHAR(255) NOT NULL,
  `body`       TEXT         DEFAULT NULL,
  `link`       VARCHAR(500) DEFAULT NULL,
  `is_read`    TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_user_read` (`user_id`, `is_read`, `created_at`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Thread percakapan (satu per pasang user, user1_id selalu < user2_id)
CREATE TABLE IF NOT EXISTS `message_threads` (
  `id`              INT       NOT NULL AUTO_INCREMENT,
  `user1_id`        INT       NOT NULL,
  `user2_id`        INT       NOT NULL,
  `last_message_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pair` (`user1_id`, `user2_id`),
  INDEX `idx_u1` (`user1_id`),
  INDEX `idx_u2` (`user2_id`),
  CONSTRAINT `fk_mt_u1` FOREIGN KEY (`user1_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mt_u2` FOREIGN KEY (`user2_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Pesan individual
CREATE TABLE IF NOT EXISTS `messages` (
  `id`         INT       NOT NULL AUTO_INCREMENT,
  `thread_id`  INT       NOT NULL,
  `sender_id`  INT       NOT NULL,
  `body`       TEXT      NOT NULL,
  `is_read`    TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_thread_time` (`thread_id`, `created_at`),
  INDEX `idx_unread`      (`thread_id`, `sender_id`, `is_read`),
  CONSTRAINT `fk_msg_thread` FOREIGN KEY (`thread_id`) REFERENCES `message_threads`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_msg_sender` FOREIGN KEY (`sender_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

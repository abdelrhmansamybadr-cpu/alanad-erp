-- ─────────────────────────────────────────────────────────────
-- Manzuma ERP — Notifications & Device Tokens Migration
-- Run once on your Hostinger MySQL database
-- ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `notifications` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED NULL COMMENT 'NULL = broadcast to all users',
  `type`       VARCHAR(50) NOT NULL DEFAULT 'info'
               COMMENT 'invoice|payment|leave|stock|task|employee|system',
  `title`      VARCHAR(255) NOT NULL,
  `body`       TEXT NOT NULL,
  `data`       JSON NULL COMMENT 'Extra payload: resource_id, url, etc.',
  `is_read`    TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user` (`user_id`, `is_read`),
  KEY `idx_notifications_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `device_tokens` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `token`      VARCHAR(512) NOT NULL,
  `platform`   ENUM('ios','android','web') NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_device_tokens_token` (`token`(255)),
  KEY `idx_device_tokens_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

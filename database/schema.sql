-- =====================================================================
-- SMM PANEL DATABASE SCHEMA (MySQL 8.0+ / MariaDB 10.5+)
-- UTF8mb4 Full Unicode Support, Strict Foreign Keys, Performance Indexes
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `ticket_messages`;
DROP TABLE IF EXISTS `tickets`;
DROP TABLE IF EXISTS `transactions`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `services`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `providers`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `settings`;

SET FOREIGN_KEY_CHECKS = 1;

-- 1. SETTINGS TABLE
CREATE TABLE `settings` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(100) NOT NULL UNIQUE,
  `value` TEXT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. USERS TABLE
CREATE TABLE `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(64) NOT NULL UNIQUE,
  `email` VARCHAR(191) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(32) NULL,
  `balance` DECIMAL(12, 4) NOT NULL DEFAULT 0.0000,
  `spent` DECIMAL(12, 4) NOT NULL DEFAULT 0.0000,
  `role` ENUM('user', 'admin', 'support') NOT NULL DEFAULT 'user',
  `status` ENUM('active', 'suspended', 'banned') NOT NULL DEFAULT 'active',
  `api_key` VARCHAR(64) NOT NULL UNIQUE,
  `email_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_users_role` (`role`),
  INDEX `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. PROVIDERS TABLE (External API providers)
CREATE TABLE `providers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `api_url` VARCHAR(255) NOT NULL,
  `api_key` VARCHAR(255) NOT NULL,
  `balance` DECIMAL(12, 4) NOT NULL DEFAULT 0.0000,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. CATEGORIES TABLE
CREATE TABLE `categories` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `platform` ENUM('instagram', 'youtube', 'telegram', 'facebook', 'tiktok', 'twitter', 'other') NOT NULL DEFAULT 'other',
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_category_platform` (`platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. SERVICES TABLE
CREATE TABLE `services` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `category_id` INT UNSIGNED NOT NULL,
  `provider_id` INT UNSIGNED NULL,
  `provider_service_id` VARCHAR(64) NULL,
  `name` VARCHAR(255) NOT NULL,
  `rate_per_1000` DECIMAL(10, 4) NOT NULL,
  `original_rate` DECIMAL(10, 4) NULL,
  `min_quantity` INT UNSIGNED NOT NULL DEFAULT 10,
  `max_quantity` INT UNSIGNED NOT NULL DEFAULT 100000,
  `service_type` ENUM('default', 'custom_comments', 'package', 'poll') NOT NULL DEFAULT 'default',
  `speed` VARCHAR(100) DEFAULT 'Fast Delivery',
  `description` TEXT NULL,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`provider_id`) REFERENCES `providers`(`id`) ON DELETE SET NULL,
  INDEX `idx_service_status` (`status`),
  INDEX `idx_service_provider_mapping` (`provider_id`, `provider_service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. ORDERS TABLE
CREATE TABLE `orders` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `service_id` INT UNSIGNED NOT NULL,
  `provider_id` INT UNSIGNED NULL,
  `provider_order_id` VARCHAR(64) NULL,
  `link` VARCHAR(512) NOT NULL,
  `quantity` INT UNSIGNED NOT NULL,
  `charge` DECIMAL(10, 4) NOT NULL,
  `start_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `remains` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` ENUM('pending', 'processing', 'in_progress', 'completed', 'partial', 'cancelled') NOT NULL DEFAULT 'pending',
  `mode` ENUM('manual', 'auto') NOT NULL DEFAULT 'auto',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`service_id`) REFERENCES `services`(`id`) ON DELETE RESTRICT,
  INDEX `idx_orders_status` (`status`),
  INDEX `idx_orders_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. TRANSACTIONS TABLE
CREATE TABLE `transactions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `order_id` INT UNSIGNED NULL,
  `type` ENUM('deposit', 'order', 'refund', 'bonus', 'admin_adjustment') NOT NULL,
  `amount` DECIMAL(12, 4) NOT NULL,
  `gateway` VARCHAR(50) NOT NULL DEFAULT 'system',
  `gateway_txn_id` VARCHAR(120) NULL,
  `status` ENUM('pending', 'completed', 'failed', 'refunded') NOT NULL DEFAULT 'completed',
  `note` VARCHAR(255) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE SET NULL,
  INDEX `idx_txn_type` (`type`),
  INDEX `idx_txn_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. TICKETS TABLE
CREATE TABLE `tickets` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `order_id` INT UNSIGNED NULL,
  `subject` VARCHAR(191) NOT NULL,
  `status` ENUM('open', 'in_progress', 'closed') NOT NULL DEFAULT 'open',
  `priority` ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_tickets_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. TICKET_MESSAGES TABLE
CREATE TABLE `ticket_messages` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `ticket_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `is_admin` TINYINT(1) NOT NULL DEFAULT 0,
  `message` TEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`ticket_id`) REFERENCES `tickets`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SEED INITIAL CONFIGURATION & ADMIN/DEMO DATA
-- =====================================================================
INSERT INTO `settings` (`key`, `value`) VALUES
('site_name', 'SMM Panel'),
('site_tagline', 'Grow Your Social Media'),
('currency', '₹'),
('currency_code', 'INR'),
('razorpay_key_id', 'rzp_test_YourKeyHere'),
('razorpay_key_secret', 'YourSecretKeyHere'),
('min_deposit', '100'),
('max_deposit', '50000'),
('maintenance_mode', '0');

-- Initial Admin Account: username: admin / password: password123
-- Initial User Account: username: aaris / password: password123
INSERT INTO `users` (`id`, `username`, `email`, `password`, `phone`, `balance`, `spent`, `role`, `status`, `api_key`, `email_verified`) VALUES
(1001, 'admin', 'admin@smmpanel.local', '$2y$12$R.O44hC46gE8i1a0MvV9EeeYvA5f9V7Z3d.gO8Z3D/a6F5e9qLhQW', '+91 98765 00000', 50000.0000, 0.0000, 'admin', 'active', 'adm_839f284c17b44d28e71c9902', 1),
(1024, 'Aaris Ali', 'aarisali@gmail.com', '$2y$12$R.O44hC46gE8i1a0MvV9EeeYvA5f9V7Z3d.gO8Z3D/a6F5e9qLhQW', '+91 98765 43210', 850.5000, 155.0000, 'user', 'active', 'usr_749e192a63d91b87d21c4301', 1);

-- Default Categories
INSERT INTO `categories` (`id`, `name`, `platform`, `sort_order`, `status`) VALUES
(1, 'Instagram Followers [Real & Active]', 'instagram', 1, 'active'),
(2, 'Instagram Likes & Engagements', 'instagram', 2, 'active'),
(3, 'Instagram Views & Reels', 'instagram', 3, 'active'),
(4, 'YouTube Views [High Retention]', 'youtube', 4, 'active'),
(5, 'Telegram Members [Real & Instant]', 'telegram', 5, 'active'),
(6, 'TikTok Followers & Views', 'tiktok', 6, 'active'),
(7, 'Facebook Page Likes & Followers', 'facebook', 7, 'active'),
(8, 'Twitter (X) Followers & Retweets', 'twitter', 8, 'active');

-- Default Services
INSERT INTO `services` (`id`, `category_id`, `name`, `rate_per_1000`, `min_quantity`, `max_quantity`, `speed`, `description`, `status`) VALUES
(101, 1, 'Instagram Followers [Real & Active Followers - High Quality]', 35.0000, 1000, 1000000, 'Starts in 1-2 Hours', 'High quality, non-drop real active followers. Instant start with 30-day auto-refill.', 'active'),
(102, 2, 'Instagram Likes [High Quality - Instant Start]', 20.0000, 100, 500000, 'Instant Start', 'Ultra-fast delivery of premium likes from active accounts worldwide.', 'active'),
(103, 3, 'Instagram Views & Reels [High Retention]', 15.0000, 1000, 10000000, 'Instant Start', 'Viral video engagement booster for Instagram Reels and videos.', 'active'),
(104, 2, 'Instagram Custom Comments [Positive Real Text]', 50.0000, 10, 10000, 'Fast Delivery', 'Custom written comments in English or your language.', 'active'),
(201, 4, 'YouTube Views [Real Views - High Retention]', 12.0000, 1000, 5000000, 'Fast Delivery', 'Monetization-safe high watch time views from search and suggested videos.', 'active'),
(301, 5, 'Telegram Members [Real & Active Members - Instant Start]', 45.0000, 500, 200000, 'Instant Start', 'Telegram channel and group members. Real accounts with 0% drop guarantee.', 'active');

-- Default Demo Orders for Aaris Ali (#1024)
INSERT INTO `orders` (`id`, `user_id`, `service_id`, `link`, `quantity`, `charge`, `start_count`, `remains`, `status`, `created_at`) VALUES
(10254, 1024, 101, 'https://instagram.com/aarisali', 1000, 35.0000, 4200, 200, 'processing', '2025-05-12 16:32:00'),
(10253, 1024, 201, 'https://youtube.com/watch?v=smmDemo123', 5000, 120.0000, 1240, 0, 'completed', '2025-05-11 18:10:00'),
(10252, 1024, 301, 'https://t.me/techgrowthindia', 2000, 90.0000, 1500, 120, 'processing', '2025-05-10 13:45:00'),
(10251, 1024, 102, 'https://instagram.com/p/C67890123', 1000, 20.0000, 850, 0, 'completed', '2025-05-09 19:20:00');

-- Default Demo Transactions for Aaris Ali (#1024)
INSERT INTO `transactions` (`user_id`, `type`, `amount`, `gateway`, `gateway_txn_id`, `status`, `note`, `created_at`) VALUES
(1024, 'deposit', 500.0000, 'Razorpay', 'pay_rzp_8941720', 'completed', 'Added funds via Razorpay UPI', '2025-05-12 16:12:00'),
(1024, 'order', -35.0000, 'system', 'ord_10254', 'completed', 'Order #10254: Instagram Followers', '2025-05-12 16:32:00'),
(1024, 'deposit', 200.0000, 'Razorpay', 'pay_rzp_6291054', 'completed', 'Added funds via Razorpay Cards', '2025-05-10 11:20:00'),
(1024, 'order', -120.0000, 'system', 'ord_10253', 'completed', 'Order #10253: YouTube Views', '2025-05-10 18:15:00');

-- Default Demo Support Tickets
INSERT INTO `tickets` (`id`, `user_id`, `order_id`, `subject`, `status`, `priority`, `created_at`) VALUES
(1024, 1024, 10254, 'Order not started yet', 'open', 'high', '2025-05-12 11:20:00'),
(1023, 1024, NULL, 'Payment issue with QR code', 'in_progress', 'medium', '2025-05-10 18:15:00'),
(1022, 1024, 10253, 'Service delivery speed query', 'closed', 'low', '2025-05-08 15:40:00'),
(1021, 1024, 10251, 'Wrong quantity query', 'closed', 'low', '2025-05-06 13:10:00');

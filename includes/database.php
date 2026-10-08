<?php
/**
 * Database Connection via PDO
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

class Database {
    private static ?PDO $instance = null;

    private function __construct() {}
    private function __clone() {}

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false
            ];

            // 1. Primary: MySQL 8+ / MariaDB via PDO
            try {
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    DB_HOST,
                    DB_PORT,
                    DB_NAME
                );
                $myOptions = $options;
                $myOptions[PDO::ATTR_TIMEOUT] = 1;
                $myOptions[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci";
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $myOptions);
                return self::$instance;
            } catch (PDOException $e) {
                // MySQL not running or not yet installed, fall back to SQLite
            }

            // 2. Secondary local portable SQLite PDO database
            try {
                $dbDir = dirname(__DIR__) . '/database';
                if (!is_dir($dbDir)) {
                    @mkdir($dbDir, 0755, true);
                }
                $sqlitePath = $dbDir . '/smm_panel.sqlite';
                $isNew = !file_exists($sqlitePath) || filesize($sqlitePath) === 0;
                self::$instance = new PDO('sqlite:' . $sqlitePath, null, null, $options);
                if ($isNew) {
                    self::bootstrapSQLite(self::$instance);
                }
                return self::$instance;
            } catch (PDOException $e) {
                error_log("Database connection failure: " . $e->getMessage());
                // If installer not completed, redirect to installer
                if (!file_exists(dirname(__DIR__) . '/install/installed.lock')) {
                    header("Location: /install/index.php");
                    exit;
                }
                die("Database connection failed. Please check your configuration.");
            }
        }
        return self::$instance;
    }

    private static function bootstrapSQLite(PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                key TEXT UNIQUE,
                value TEXT,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE,
                email TEXT UNIQUE,
                password TEXT,
                phone TEXT,
                balance REAL DEFAULT 0,
                spent REAL DEFAULT 0,
                role TEXT DEFAULT 'user',
                status TEXT DEFAULT 'active',
                api_key TEXT UNIQUE,
                email_verified INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                platform TEXT,
                sort_order INTEGER DEFAULT 0,
                status TEXT DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS services (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                category_id INTEGER,
                name TEXT,
                provider_id INTEGER DEFAULT NULL,
                provider_service_id TEXT DEFAULT NULL,
                rate_per_1000 REAL,
                original_rate REAL DEFAULT NULL,
                min_quantity INTEGER DEFAULT 100,
                max_quantity INTEGER DEFAULT 1000000,
                service_type TEXT DEFAULT 'default',
                speed TEXT DEFAULT 'Fast Delivery',
                description TEXT DEFAULT NULL,
                status TEXT DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_services_provider ON services(provider_id, provider_service_id);
            CREATE TABLE IF NOT EXISTS orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                service_id INTEGER,
                provider_id INTEGER DEFAULT NULL,
                provider_order_id TEXT DEFAULT NULL,
                link TEXT,
                quantity INTEGER,
                charge REAL,
                start_count INTEGER DEFAULT 0,
                remains INTEGER DEFAULT 0,
                status TEXT DEFAULT 'processing',
                mode TEXT DEFAULT 'auto',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                order_id INTEGER DEFAULT NULL,
                type TEXT,
                amount REAL,
                gateway TEXT,
                gateway_txn_id TEXT,
                status TEXT DEFAULT 'completed',
                note TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS tickets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                order_id INTEGER DEFAULT NULL,
                subject TEXT,
                status TEXT DEFAULT 'open',
                priority TEXT DEFAULT 'medium',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS ticket_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ticket_id INTEGER,
                user_id INTEGER,
                is_admin INTEGER DEFAULT 0,
                message TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS providers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                api_url TEXT,
                api_key TEXT,
                balance REAL DEFAULT 0,
                currency TEXT DEFAULT 'USD',
                status TEXT DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");

        // Seed default system settings
        $pdo->exec("
            INSERT OR IGNORE INTO settings (key, value) VALUES
            ('site_name', 'SMM Panel'),
            ('site_tagline', 'Grow Your Social Media'),
            ('currency', '₹'),
            ('currency_code', 'INR'),
            ('razorpay_key_id', 'rzp_test_YourKeyHere'),
            ('razorpay_key_secret', 'YourSecretKeyHere'),
            ('min_deposit', '100'),
            ('max_deposit', '50000'),
            ('maintenance_mode', '0'),
            ('allow_registration', '1'),
            ('support_email', 'support@smmpanel.local');
        ");

        // Seed demo accounts
        $adminPass = password_hash('password123', PASSWORD_DEFAULT);
        $userPass = password_hash('password123', PASSWORD_DEFAULT);

        $pdo->exec("
            INSERT OR IGNORE INTO users (id, username, email, password, phone, balance, spent, role, status, api_key, email_verified)
            VALUES 
            (1001, 'admin', 'admin@smmpanel.local', '{$adminPass}', '+91 98765 00000', 50000.0, 0.0, 'admin', 'active', 'adm_demo839218204921', 1),
            (1024, 'Aaris Ali', 'aarisali@gmail.com', '{$userPass}', '+91 98765 43210', 850.50, 155.0, 'user', 'active', 'usr_749e192a63d91b87d21c4301', 1);

            INSERT OR IGNORE INTO categories (id, name, platform, sort_order) VALUES
            (1, 'Instagram Followers [Real & Active]', 'instagram', 1),
            (2, 'Instagram Likes & Engagements', 'instagram', 2),
            (3, 'Instagram Views & Reels', 'instagram', 3),
            (4, 'YouTube Views [High Retention]', 'youtube', 4),
            (5, 'Telegram Members [Real & Instant]', 'telegram', 5),
            (6, 'TikTok Followers & Views', 'tiktok', 6),
            (7, 'Facebook Page Likes & Followers', 'facebook', 7),
            (8, 'Twitter (X) Followers & Retweets', 'twitter', 8);

            INSERT OR IGNORE INTO services (id, category_id, name, rate_per_1000, min_quantity, max_quantity, speed) VALUES
            (101, 1, 'Instagram Followers [Real & Active Followers - High Quality]', 35.0, 1000, 1000000, 'Starts in 1-2 Hours'),
            (102, 2, 'Instagram Likes [High Quality - Instant Start]', 20.0, 100, 500000, 'Instant Start'),
            (103, 3, 'Instagram Views & Reels [High Retention]', 15.0, 1000, 10000000, 'Instant Start'),
            (104, 2, 'Instagram Custom Comments [Positive Real Text]', 50.0, 10, 10000, 'Fast Delivery'),
            (201, 4, 'YouTube Views [Real Views - High Retention]', 12.0, 1000, 5000000, 'Fast Delivery'),
            (301, 5, 'Telegram Members [Real & Active Members - Instant Start]', 45.0, 500, 200000, 'Instant Start');

            INSERT OR IGNORE INTO orders (id, user_id, service_id, link, quantity, charge, start_count, remains, status) VALUES
            (10254, 1024, 101, 'https://instagram.com/aarisali', 1000, 35.0, 4200, 200, 'processing'),
            (10253, 1024, 201, 'https://youtube.com/watch?v=smmDemo123', 5000, 120.0, 1240, 0, 'completed'),
            (10252, 1024, 301, 'https://t.me/techgrowthindia', 2000, 90.0, 1500, 120, 'processing'),
            (10251, 1024, 102, 'https://instagram.com/p/C67890123', 1000, 20.0, 850, 0, 'completed');

            INSERT OR IGNORE INTO transactions (user_id, type, amount, gateway, gateway_txn_id, status, note) VALUES
            (1024, 'deposit', 500.0, 'Razorpay', 'pay_rzp_8941720', 'completed', 'Added funds via Razorpay UPI'),
            (1024, 'order', -35.0, 'system', 'ord_10254', 'completed', 'Order #10254: Instagram Followers'),
            (1024, 'deposit', 200.0, 'Razorpay', 'pay_rzp_6291054', 'completed', 'Added funds via Razorpay Cards'),
            (1024, 'order', -120.0, 'system', 'ord_10253', 'completed', 'Order #10253: YouTube Views');

            INSERT OR IGNORE INTO tickets (id, user_id, order_id, subject, status, priority) VALUES
            (1024, 1024, 10254, 'Order not started yet', 'open', 'high'),
            (1023, 1024, NULL, 'Payment issue with QR code', 'in_progress', 'medium'),
            (1022, 1024, 10253, 'Service delivery speed query', 'closed', 'low'),
            (1021, 1024, 10251, 'Wrong quantity query', 'closed', 'low');
        ");
    }
}

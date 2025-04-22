<?php
/**
 * MailHebrew Installation Script
 * 
 * This script creates the necessary database tables for MailHebrew mail service
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

// טען הגדרות סביבה
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// פונקציה להדפסת הודעות
function output(string $message): void
{
    echo $message . PHP_EOL;
}

output('MailHebrew Installation Script');
output('-----------------------------');
output('');

// יצירת תיקיות נדרשות
$dirsToCreate = [
    'logs',
    'public/assets',
    'public/uploads',
];

output('Creating required directories...');
foreach ($dirsToCreate as $dir) {
    $path = __DIR__ . '/' . $dir;
    if (!file_exists($path)) {
        if (mkdir($path, 0755, true)) {
            output(" - Created directory: {$dir}");
        } else {
            output(" ! Failed to create directory: {$dir}");
            exit(1);
        }
    } else {
        output(" - Directory already exists: {$dir}");
    }
}

// חיבור למסד הנתונים
output('');
output('Connecting to database...');

try {
    $db = new PDO(
        sprintf(
            'mysql:host=%s;port=%s;charset=utf8mb4',
            $_ENV['DB_HOST'],
            $_ENV['DB_PORT']
        ),
        $_ENV['DB_USERNAME'],
        $_ENV['DB_PASSWORD'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    
    output(' - Database connection successful');
} catch (PDOException $e) {
    output(' ! Failed to connect to database: ' . $e->getMessage());
    exit(1);
}

// יצירת מסד הנתונים אם לא קיים
output('');
output('Creating database if not exists...');

try {
    $db->exec('CREATE DATABASE IF NOT EXISTS ' . $_ENV['DB_DATABASE']);
    $db->exec('USE ' . $_ENV['DB_DATABASE']);
    output(' - Database "' . $_ENV['DB_DATABASE'] . '" selected/created');
} catch (PDOException $e) {
    output(' ! Failed to create database: ' . $e->getMessage());
    exit(1);
}

// הגדרת טבלאות וסכמה
$tables = [
    'campaigns' => "
        CREATE TABLE IF NOT EXISTS campaigns (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id INT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            from_email VARCHAR(255) NOT NULL,
            from_name VARCHAR(255) NOT NULL,
            reply_to VARCHAR(255) NULL,
            content_html MEDIUMTEXT NOT NULL,
            content_text MEDIUMTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            scheduled_at DATETIME NULL,
            sent_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_account_status (account_id, status),
            INDEX idx_scheduled (scheduled_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'email_lists' => "
        CREATE TABLE IF NOT EXISTS email_lists (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id INT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_account (account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'recipients' => "
        CREATE TABLE IF NOT EXISTS recipients (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id INT UNSIGNED NOT NULL,
            email VARCHAR(255) NOT NULL,
            name VARCHAR(255) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            unsubscribed TINYINT(1) NOT NULL DEFAULT 0,
            bounced TINYINT(1) NOT NULL DEFAULT 0,
            complaint TINYINT(1) NOT NULL DEFAULT 0,
            metadata JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY idx_account_email (account_id, email),
            INDEX idx_status (is_active, unsubscribed, bounced)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'list_recipients' => "
        CREATE TABLE IF NOT EXISTS list_recipients (
            list_id INT UNSIGNED NOT NULL,
            recipient_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (list_id, recipient_id),
            CONSTRAINT fk_list_recipients_list 
                FOREIGN KEY (list_id) REFERENCES email_lists (id) 
                ON DELETE CASCADE,
            CONSTRAINT fk_list_recipients_recipient 
                FOREIGN KEY (recipient_id) REFERENCES recipients (id) 
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'campaign_lists' => "
        CREATE TABLE IF NOT EXISTS campaign_lists (
            campaign_id INT UNSIGNED NOT NULL,
            list_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (campaign_id, list_id),
            CONSTRAINT fk_campaign_lists_campaign 
                FOREIGN KEY (campaign_id) REFERENCES campaigns (id) 
                ON DELETE CASCADE,
            CONSTRAINT fk_campaign_lists_list 
                FOREIGN KEY (list_id) REFERENCES email_lists (id) 
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'templates' => "
        CREATE TABLE IF NOT EXISTS templates (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id INT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            content_html MEDIUMTEXT NOT NULL,
            content_text MEDIUMTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_account (account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'emails' => "
        CREATE TABLE IF NOT EXISTS emails (
            id CHAR(36) PRIMARY KEY,
            campaign_id INT UNSIGNED NULL,
            account_id INT UNSIGNED NOT NULL,
            recipient_id INT UNSIGNED NULL,
            from_email VARCHAR(255) NOT NULL,
            from_name VARCHAR(255) NOT NULL,
            to_email VARCHAR(255) NOT NULL,
            to_name VARCHAR(255) NULL,
            subject VARCHAR(255) NOT NULL,
            content_html MEDIUMTEXT NULL,
            content_text MEDIUMTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            sent_at DATETIME NULL,
            opened_at DATETIME NULL,
            clicked_at DATETIME NULL,
            metadata JSON NULL,
            tracking_enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_campaign (campaign_id),
            INDEX idx_recipient (recipient_id),
            INDEX idx_account (account_id),
            INDEX idx_status (status),
            CONSTRAINT fk_emails_campaign
                FOREIGN KEY (campaign_id) REFERENCES campaigns (id)
                ON DELETE SET NULL,
            CONSTRAINT fk_emails_recipient
                FOREIGN KEY (recipient_id) REFERENCES recipients (id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'email_events' => "
        CREATE TABLE IF NOT EXISTS email_events (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email_id CHAR(36) NOT NULL,
            event_type VARCHAR(20) NOT NULL,
            event_data JSON NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_email (email_id),
            INDEX idx_event_type (event_type),
            INDEX idx_created_at (created_at),
            CONSTRAINT fk_events_email
                FOREIGN KEY (email_id) REFERENCES emails (id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'accounts' => "
        CREATE TABLE IF NOT EXISTS accounts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            domain VARCHAR(255) NULL,
            api_key VARCHAR(64) NOT NULL,
            max_emails_per_day INT UNSIGNED NULL,
            max_emails_per_month INT UNSIGNED NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY idx_api_key (api_key),
            INDEX idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'domain_settings' => "
        CREATE TABLE IF NOT EXISTS domain_settings (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id INT UNSIGNED NOT NULL,
            domain VARCHAR(255) NOT NULL,
            is_verified TINYINT(1) NOT NULL DEFAULT 0,
            verification_token VARCHAR(64) NULL,
            dkim_selector VARCHAR(63) NULL,
            dkim_private_key TEXT NULL,
            dkim_public_key TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY idx_account_domain (account_id, domain),
            CONSTRAINT fk_domain_account
                FOREIGN KEY (account_id) REFERENCES accounts (id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'webhooks' => "
        CREATE TABLE IF NOT EXISTS webhooks (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id INT UNSIGNED NOT NULL,
            url VARCHAR(255) NOT NULL,
            events JSON NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            secret VARCHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_account (account_id),
            CONSTRAINT fk_webhook_account
                FOREIGN KEY (account_id) REFERENCES accounts (id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    "
];

// יצירת הטבלאות
output('');
output('Creating database tables...');

foreach ($tables as $tableName => $query) {
    try {
        $db->exec($query);
        output(" - Table '{$tableName}' created/updated successfully");
    } catch (PDOException $e) {
        output(" ! Failed to create table '{$tableName}': " . $e->getMessage());
        exit(1);
    }
}

output('');
output('Creating default account...');

// יצירת חשבון ברירת מחדל אם לא קיים
try {
    $apiKey = bin2hex(random_bytes(32));
    
    $stmt = $db->prepare("SELECT COUNT(*) FROM accounts WHERE name = 'Default Account'");
    $stmt->execute();
    $accountExists = (int)$stmt->fetchColumn() > 0;
    
    if (!$accountExists) {
        $stmt = $db->prepare("
            INSERT INTO accounts (name, api_key, domain, is_active)
            VALUES ('Default Account', :api_key, :domain, 1)
        ");
        
        $stmt->execute([
            'api_key' => $apiKey,
            'domain' => parse_url($_ENV['APP_URL'], PHP_URL_HOST),
        ]);
        
        output(" - Default account created with API key: {$apiKey}");
        output(" - IMPORTANT: Save this API key for future use!");
    } else {
        output(" - Default account already exists");
    }
} catch (PDOException $e) {
    output(" ! Failed to create default account: " . $e->getMessage());
}

// בדיקת חיבור ל-Redis
output('');
output('Testing Redis connection...');

try {
    $redis = new Predis\Client([
        'scheme' => 'tcp',
        'host' => $_ENV['REDIS_HOST'],
        'port' => (int)$_ENV['REDIS_PORT'],
        'password' => $_ENV['REDIS_PASSWORD'] ?: null,
        'database' => (int)$_ENV['REDIS_DB'],
    ]);
    
    $redis->set('mailhebrew:test', 'Installation Test: ' . date('Y-m-d H:i:s'));
    $testValue = $redis->get('mailhebrew:test');
    
    if ($testValue) {
        output(" - Redis connection successful");
    } else {
        output(" ! Redis connection failed");
    }
} catch (Exception $e) {
    output(" ! Failed to connect to Redis: " . $e->getMessage());
}

// סיום
output('');
output('Installation completed!');
output('');
output('Next steps:');
output('1. Set up a cron job to run the queue worker: * * * * * php ' . __DIR__ . '/queue_worker.php');
output('2. Configure web server to point to the public/ directory');
output('3. Set up DNS records for tracking domain (' . $_ENV['APP_TRACKING_DOMAIN'] . ')');
output(''); 
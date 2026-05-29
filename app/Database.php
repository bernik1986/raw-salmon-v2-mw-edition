<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connect(?array $config = null): PDO
    {
        if ($config === null && self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $config ??= app_config();
        $dbPath = $config['db_path'];
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        try {
            $pdo = new PDO('sqlite:' . $dbPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA foreign_keys = ON');
            self::migrate($pdo);
        } catch (PDOException $exception) {
            throw new \RuntimeException('Unable to open SQLite database: ' . $exception->getMessage(), 0, $exception);
        }

        if ($config === app_config()) {
            self::$pdo = $pdo;
        }

        return $pdo;
    }

    public static function resetConnection(): void
    {
        self::$pdo = null;
    }

    public static function migrate(PDO $pdo): void
    {
        $pdo->exec(
            <<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL CHECK (role IN ('admin', 'user')),
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'inactive')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    last_login_at TEXT
);

CREATE TABLE IF NOT EXISTS mailgun_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL UNIQUE,
    api_key_encrypted TEXT,
    domain TEXT NOT NULL DEFAULT '',
    region TEXT NOT NULL DEFAULT 'US' CHECK (region IN ('US', 'EU')),
    default_from_name TEXT NOT NULL DEFAULT '',
    default_reply_to TEXT NOT NULL DEFAULT '',
    test_mode INTEGER NOT NULL DEFAULT 1,
    daily_limit INTEGER NOT NULL DEFAULT 100,
    hourly_limit INTEGER NOT NULL DEFAULT 25,
    webhook_signing_key_encrypted TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS mailgun_domains (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    domain TEXT NOT NULL,
    region TEXT NOT NULL DEFAULT 'US' CHECK (region IN ('US', 'EU')),
    default_from_name TEXT NOT NULL DEFAULT '',
    default_reply_to TEXT NOT NULL DEFAULT '',
    test_mode INTEGER NOT NULL DEFAULT 1,
    daily_limit INTEGER NOT NULL DEFAULT 100,
    hourly_limit INTEGER NOT NULL DEFAULT 25,
    is_active INTEGER NOT NULL DEFAULT 1,
    is_default INTEGER NOT NULL DEFAULT 0,
    last_test_status TEXT,
    last_test_message TEXT,
    last_test_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (user_id, domain),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS recipients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    email TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    UNIQUE (user_id, email),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS presets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    mailgun_domain_id INTEGER,
    name TEXT NOT NULL,
    mailgun_domain TEXT NOT NULL DEFAULT '',
    language TEXT NOT NULL DEFAULT '',
    topic TEXT NOT NULL DEFAULT '',
    from_pattern TEXT NOT NULL,
    delay_mode TEXT NOT NULL DEFAULT 'fixed' CHECK (delay_mode IN ('fixed', 'random')),
    delay_min_seconds INTEGER NOT NULL DEFAULT 30,
    delay_max_seconds INTEGER NOT NULL DEFAULT 30,
    batch_size INTEGER NOT NULL DEFAULT 1,
    json_payload TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'inactive')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (mailgun_domain_id) REFERENCES mailgun_domains(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS preset_attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    preset_id INTEGER NOT NULL,
    original_name TEXT NOT NULL,
    stored_name TEXT NOT NULL,
    mime_type TEXT NOT NULL DEFAULT 'application/octet-stream',
    size_bytes INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (preset_id) REFERENCES presets(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS preset_recipients (
    preset_id INTEGER NOT NULL,
    recipient_id INTEGER NOT NULL,
    PRIMARY KEY (preset_id, recipient_id),
    FOREIGN KEY (preset_id) REFERENCES presets(id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_id) REFERENCES recipients(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS sending_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    preset_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'running', 'completed', 'failed', 'cancelled')),
    total_emails INTEGER NOT NULL DEFAULT 0,
    sent_count INTEGER NOT NULL DEFAULT 0,
    failed_count INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    started_at TEXT,
    paused_at TEXT,
    finished_at TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (preset_id) REFERENCES presets(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS job_attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    original_name TEXT NOT NULL,
    stored_name TEXT NOT NULL,
    mime_type TEXT NOT NULL DEFAULT 'application/octet-stream',
    size_bytes INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    FOREIGN KEY (job_id) REFERENCES sending_jobs(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS email_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    recipient_email TEXT NOT NULL,
    from_email TEXT NOT NULL,
    from_name TEXT NOT NULL DEFAULT '',
    subject TEXT,
    body_text TEXT,
    body_html TEXT,
    scheduled_at TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'sending', 'sent', 'failed', 'cancelled')),
    attempts INTEGER NOT NULL DEFAULT 0,
    mailgun_message_id TEXT,
    delivery_status TEXT,
    last_error TEXT,
    created_at TEXT NOT NULL,
    delivered_at TEXT,
    sent_at TEXT,
    FOREIGN KEY (job_id) REFERENCES sending_jobs(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS email_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    job_id INTEGER,
    queue_id INTEGER,
    recipient_email TEXT NOT NULL,
    from_email TEXT NOT NULL,
    subject TEXT,
    status TEXT NOT NULL,
    mailgun_response TEXT,
    error_message TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (job_id) REFERENCES sending_jobs(id) ON DELETE SET NULL,
    FOREIGN KEY (queue_id) REFERENCES email_queue(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    action TEXT NOT NULL,
    details TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS mailgun_webhook_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    queue_id INTEGER,
    job_id INTEGER,
    mailgun_domain TEXT NOT NULL DEFAULT '',
    event_id TEXT,
    event_type TEXT NOT NULL,
    severity TEXT,
    recipient_email TEXT,
    message_id TEXT,
    signature_valid INTEGER NOT NULL DEFAULT 0,
    payload TEXT NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE (event_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (queue_id) REFERENCES email_queue(id) ON DELETE SET NULL,
    FOREIGN KEY (job_id) REFERENCES sending_jobs(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_queue_due ON email_queue(status, scheduled_at);
CREATE INDEX IF NOT EXISTS idx_queue_user_status ON email_queue(user_id, status);
CREATE INDEX IF NOT EXISTS idx_logs_user_created ON email_logs(user_id, created_at);
CREATE INDEX IF NOT EXISTS idx_domains_user_active ON mailgun_domains(user_id, is_active);
CREATE INDEX IF NOT EXISTS idx_webhook_domain_created ON mailgun_webhook_events(mailgun_domain, created_at);
SQL
        );

        self::addColumnIfMissing($pdo, 'mailgun_settings', 'webhook_signing_key_encrypted', 'TEXT');
        self::addColumnIfMissing($pdo, 'presets', 'mailgun_domain_id', 'INTEGER');
        self::addColumnIfMissing($pdo, 'sending_jobs', 'paused_at', 'TEXT');
        self::addColumnIfMissing($pdo, 'email_queue', 'delivery_status', 'TEXT');
        self::addColumnIfMissing($pdo, 'email_queue', 'delivered_at', 'TEXT');
        self::seedDomainsFromLegacySettings($pdo);
    }

    private static function addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void
    {
        $statement = $pdo->query("PRAGMA table_info($table)");
        $columns = array_column($statement->fetchAll(), 'name');
        if (!in_array($column, $columns, true)) {
            $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
        }
    }

    private static function seedDomainsFromLegacySettings(PDO $pdo): void
    {
        $rows = $pdo->query(
            "SELECT * FROM mailgun_settings WHERE TRIM(domain) != ''"
        )->fetchAll();

        $insert = $pdo->prepare(
            'INSERT OR IGNORE INTO mailgun_domains
             (user_id, domain, region, default_from_name, default_reply_to, test_mode, daily_limit, hourly_limit,
              is_active, is_default, created_at, updated_at)
             VALUES
             (:user_id, :domain, :region, :default_from_name, :default_reply_to, :test_mode, :daily_limit, :hourly_limit,
              1, 1, :created_at, :updated_at)'
        );
        foreach ($rows as $row) {
            $insert->execute([
                'user_id' => $row['user_id'],
                'domain' => strtolower((string) $row['domain']),
                'region' => $row['region'],
                'default_from_name' => $row['default_from_name'],
                'default_reply_to' => $row['default_reply_to'],
                'test_mode' => $row['test_mode'],
                'daily_limit' => $row['daily_limit'],
                'hourly_limit' => $row['hourly_limit'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ]);
        }
    }
}

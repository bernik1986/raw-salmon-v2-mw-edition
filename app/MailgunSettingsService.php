<?php

declare(strict_types=1);

namespace App;

use PDO;

final class MailgunSettingsService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function get(int $userId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM mailgun_settings WHERE user_id = :user_id');
        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch();

        if ($row) {
            $row['api_key_plain'] = Security::decrypt($row['api_key_encrypted'] ?? null);
            $row['api_key_masked'] = Security::maskSecret($row['api_key_plain']);
            $row['webhook_signing_key_plain'] = Security::decrypt($row['webhook_signing_key_encrypted'] ?? null);
            $row['webhook_signing_key_masked'] = Security::maskSecret($row['webhook_signing_key_plain']);
            return $row;
        }

        return [
            'user_id' => $userId,
            'api_key_encrypted' => null,
            'api_key_plain' => null,
            'api_key_masked' => '',
            'webhook_signing_key_encrypted' => null,
            'webhook_signing_key_plain' => null,
            'webhook_signing_key_masked' => '',
            'domain' => '',
            'region' => 'US',
            'default_from_name' => '',
            'default_reply_to' => '',
            'test_mode' => 1,
            'daily_limit' => 100,
            'hourly_limit' => 25,
        ];
    }

    public function save(int $userId, array $input): void
    {
        $current = $this->get($userId);
        $apiKey = trim((string) ($input['api_key'] ?? ''));
        $encrypted = $apiKey !== '' ? Security::encrypt($apiKey) : ($current['api_key_encrypted'] ?? null);
        $webhookKey = trim((string) ($input['webhook_signing_key'] ?? ''));
        $webhookEncrypted = $webhookKey !== '' ? Security::encrypt($webhookKey) : ($current['webhook_signing_key_encrypted'] ?? null);
        $domain = strtolower(trim((string) ($input['domain'] ?? '')));
        $region = strtoupper(trim((string) ($input['region'] ?? 'US')));
        $fromName = trim((string) ($input['default_from_name'] ?? ''));
        $replyTo = trim((string) ($input['default_reply_to'] ?? ''));
        $dailyLimit = max(1, (int) ($input['daily_limit'] ?? 100));
        $hourlyLimit = max(1, (int) ($input['hourly_limit'] ?? 25));
        $testMode = !empty($input['test_mode']) ? 1 : 0;

        if (!in_array($region, ['US', 'EU'], true)) {
            throw new \InvalidArgumentException('Region must be US or EU');
        }
        if ($replyTo !== '' && !Security::safeEmail($replyTo)) {
            throw new \InvalidArgumentException('Default Reply-To must be a valid email');
        }
        if (!$encrypted) {
            throw new \InvalidArgumentException('Mailgun API key is required');
        }

        $now = AuditLog::now();
        $exists = isset($current['id']);
        if ($exists) {
            $statement = $this->pdo->prepare(
                'UPDATE mailgun_settings
                 SET api_key_encrypted = :api_key_encrypted, webhook_signing_key_encrypted = :webhook_signing_key_encrypted,
                     domain = :domain, region = :region,
                     default_from_name = :default_from_name, default_reply_to = :default_reply_to,
                     test_mode = :test_mode, daily_limit = :daily_limit, hourly_limit = :hourly_limit,
                     updated_at = :updated_at
                 WHERE user_id = :user_id'
            );
        } else {
            $statement = $this->pdo->prepare(
                'INSERT INTO mailgun_settings
                 (user_id, api_key_encrypted, webhook_signing_key_encrypted, domain, region, default_from_name, default_reply_to,
                  test_mode, daily_limit, hourly_limit, created_at, updated_at)
                 VALUES
                 (:user_id, :api_key_encrypted, :webhook_signing_key_encrypted, :domain, :region, :default_from_name, :default_reply_to,
                  :test_mode, :daily_limit, :hourly_limit, :created_at, :updated_at)'
            );
        }

        $params = [
            'user_id' => $userId,
            'api_key_encrypted' => $encrypted,
            'webhook_signing_key_encrypted' => $webhookEncrypted,
            'domain' => $domain,
            'region' => $region,
            'default_from_name' => $fromName,
            'default_reply_to' => $replyTo,
            'test_mode' => $testMode,
            'daily_limit' => $dailyLimit,
            'hourly_limit' => $hourlyLimit,
            'updated_at' => $now,
        ];
        if (!$exists) {
            $params['created_at'] = $now;
        }
        $statement->execute($params);

        if ($domain !== '') {
            (new DomainService($this->pdo))->save($userId, [
                'domain' => $domain,
                'region' => $region,
                'default_from_name' => $fromName,
                'default_reply_to' => $replyTo,
                'test_mode' => $testMode,
                'daily_limit' => $dailyLimit,
                'hourly_limit' => $hourlyLimit,
                'is_active' => '1',
                'is_default' => '1',
            ]);
        }
    }
}

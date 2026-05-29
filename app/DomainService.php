<?php

declare(strict_types=1);

namespace App;

use PDO;

final class DomainService
{
    public function __construct(
        private PDO $pdo,
        private ?MailgunClient $mailgunClient = null
    ) {
        $this->mailgunClient ??= new MailgunClient();
    }

    public function all(int $userId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM mailgun_domains WHERE user_id = :user_id ORDER BY is_default DESC, domain ASC');
        $statement->execute(['user_id' => $userId]);
        return $statement->fetchAll();
    }

    public function active(int $userId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM mailgun_domains WHERE user_id = :user_id AND is_active = 1 ORDER BY is_default DESC, domain ASC');
        $statement->execute(['user_id' => $userId]);
        return $statement->fetchAll();
    }

    public function find(int $userId, int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM mailgun_domains WHERE id = :id AND user_id = :user_id LIMIT 1');
        $statement->execute(['id' => $id, 'user_id' => $userId]);
        return $statement->fetch() ?: null;
    }

    public function findByDomain(string $domain): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM mailgun_domains WHERE domain = :domain AND is_active = 1 LIMIT 1');
        $statement->execute(['domain' => strtolower(trim($domain))]);
        return $statement->fetch() ?: null;
    }

    public function findByDomainForUser(int $userId, string $domain): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM mailgun_domains WHERE user_id = :user_id AND domain = :domain LIMIT 1');
        $statement->execute(['user_id' => $userId, 'domain' => strtolower(trim($domain))]);
        return $statement->fetch() ?: null;
    }

    public function default(int $userId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM mailgun_domains WHERE user_id = :user_id AND is_active = 1 ORDER BY is_default DESC, id ASC LIMIT 1');
        $statement->execute(['user_id' => $userId]);
        return $statement->fetch() ?: null;
    }

    public function save(int $userId, array $input): int
    {
        $id = isset($input['id']) && $input['id'] !== '' ? (int) $input['id'] : null;
        $domain = strtolower(trim((string) ($input['domain'] ?? '')));
        $region = strtoupper(trim((string) ($input['region'] ?? 'US')));
        $fromName = trim((string) ($input['default_from_name'] ?? ''));
        $replyTo = trim((string) ($input['default_reply_to'] ?? ''));
        $dailyLimit = max(1, (int) ($input['daily_limit'] ?? 100));
        $hourlyLimit = max(1, (int) ($input['hourly_limit'] ?? 25));
        $testMode = !empty($input['test_mode']) ? 1 : 0;
        $isActive = !empty($input['is_active']) ? 1 : 0;
        $isDefault = !empty($input['is_default']) ? 1 : 0;

        if ($domain === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
            throw new \InvalidArgumentException('Valid Mailgun domain is required');
        }
        if (!in_array($region, ['US', 'EU'], true)) {
            throw new \InvalidArgumentException('Region must be US or EU');
        }
        if ($replyTo !== '' && !Security::safeEmail($replyTo)) {
            throw new \InvalidArgumentException('Default Reply-To must be a valid email');
        }
        if (!$id) {
            $existing = $this->findByDomainForUser($userId, $domain);
            if ($existing) {
                $id = (int) $existing['id'];
            }
        }

        $now = AuditLog::now();
        $this->pdo->beginTransaction();
        try {
            if ($isDefault) {
                $this->pdo->prepare('UPDATE mailgun_domains SET is_default = 0 WHERE user_id = :user_id')
                    ->execute(['user_id' => $userId]);
            }

            if ($id) {
                if (!$this->find($userId, $id)) {
                    throw new \InvalidArgumentException('Domain not found');
                }
                $statement = $this->pdo->prepare(
                    'UPDATE mailgun_domains
                     SET domain = :domain, region = :region, default_from_name = :default_from_name,
                         default_reply_to = :default_reply_to, test_mode = :test_mode, daily_limit = :daily_limit,
                         hourly_limit = :hourly_limit, is_active = :is_active, is_default = :is_default,
                         updated_at = :updated_at
                     WHERE id = :id AND user_id = :user_id'
                );
                $statement->execute([
                    'domain' => $domain,
                    'region' => $region,
                    'default_from_name' => $fromName,
                    'default_reply_to' => $replyTo,
                    'test_mode' => $testMode,
                    'daily_limit' => $dailyLimit,
                    'hourly_limit' => $hourlyLimit,
                    'is_active' => $isActive,
                    'is_default' => $isDefault,
                    'updated_at' => $now,
                    'id' => $id,
                    'user_id' => $userId,
                ]);
            } else {
                $statement = $this->pdo->prepare(
                    'INSERT INTO mailgun_domains
                     (user_id, domain, region, default_from_name, default_reply_to, test_mode, daily_limit,
                      hourly_limit, is_active, is_default, created_at, updated_at)
                     VALUES
                     (:user_id, :domain, :region, :default_from_name, :default_reply_to, :test_mode, :daily_limit,
                      :hourly_limit, :is_active, :is_default, :created_at, :updated_at)'
                );
                $statement->execute([
                    'user_id' => $userId,
                    'domain' => $domain,
                    'region' => $region,
                    'default_from_name' => $fromName,
                    'default_reply_to' => $replyTo,
                    'test_mode' => $testMode,
                    'daily_limit' => $dailyLimit,
                    'hourly_limit' => $hourlyLimit,
                    'is_active' => $isActive,
                    'is_default' => $isDefault,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $id = (int) $this->pdo->lastInsertId();
            }

            if (!$this->default($userId)) {
                $this->pdo->prepare('UPDATE mailgun_domains SET is_default = 1 WHERE id = :id')
                    ->execute(['id' => $id]);
            }

            $this->pdo->commit();
        } catch (\Throwable $throwable) {
            $this->pdo->rollBack();
            throw $throwable;
        }

        return $id;
    }

    public function setActive(int $userId, int $domainId, bool $active): void
    {
        if (!$this->find($userId, $domainId)) {
            throw new \InvalidArgumentException('Domain not found');
        }
        $this->pdo->prepare('UPDATE mailgun_domains SET is_active = :is_active, updated_at = :updated_at WHERE id = :id AND user_id = :user_id')
            ->execute([
                'is_active' => $active ? 1 : 0,
                'updated_at' => AuditLog::now(),
                'id' => $domainId,
                'user_id' => $userId,
            ]);
    }

    public function test(int $userId, int $domainId): array
    {
        $domain = $this->find($userId, $domainId);
        if (!$domain) {
            throw new \InvalidArgumentException('Domain not found');
        }

        $settings = (new MailgunSettingsService($this->pdo))->get($userId);
        if (empty($settings['api_key_plain'])) {
            throw new \InvalidArgumentException('Mailgun API key is required');
        }

        $result = $this->mailgunClient->testConnection($settings['api_key_plain'], $domain['region'], $domain['domain']);
        $message = $result['ok'] ? 'Connection OK' : ($result['error'] ?: 'Connection failed');
        $this->pdo->prepare(
            'UPDATE mailgun_domains
             SET last_test_status = :last_test_status, last_test_message = :last_test_message, last_test_at = :last_test_at
             WHERE id = :id AND user_id = :user_id'
        )->execute([
            'last_test_status' => $result['ok'] ? 'ok' : 'failed',
            'last_test_message' => mb_substr($message, 0, 500),
            'last_test_at' => AuditLog::now(),
            'id' => $domainId,
            'user_id' => $userId,
        ]);

        return $result;
    }

    public function settingsForPreset(int $userId, array $preset): array
    {
        $base = (new MailgunSettingsService($this->pdo))->get($userId);
        $domain = null;
        if (!empty($preset['mailgun_domain_id'])) {
            $domain = $this->find($userId, (int) $preset['mailgun_domain_id']);
        }
        if (!$domain && !empty($preset['mailgun_domain'])) {
            $domain = $this->findByDomainForUser($userId, (string) $preset['mailgun_domain']);
        }
        if (!$domain) {
            $domain = $this->default($userId);
        }

        if (!$domain) {
            return $base;
        }

        return array_replace($base, [
            'domain_id' => $domain['id'],
            'domain' => $domain['domain'],
            'region' => $domain['region'],
            'default_from_name' => $domain['default_from_name'],
            'default_reply_to' => $domain['default_reply_to'],
            'test_mode' => (int) $domain['test_mode'],
            'daily_limit' => (int) $domain['daily_limit'],
            'hourly_limit' => (int) $domain['hourly_limit'],
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App;

use PDO;

final class PresetService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.*,
                    md.domain AS managed_domain,
                    COUNT(pr.recipient_id) AS recipient_count
             FROM presets p
             LEFT JOIN mailgun_domains md ON md.id = p.mailgun_domain_id
             LEFT JOIN preset_recipients pr ON pr.preset_id = p.id
             WHERE p.user_id = :user_id
             GROUP BY p.id
             ORDER BY p.updated_at DESC'
        );
        $statement->execute(['user_id' => $userId]);
        return $statement->fetchAll();
    }

    public function find(int $userId, int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM presets WHERE id = :id AND user_id = :user_id LIMIT 1');
        $statement->execute(['id' => $id, 'user_id' => $userId]);
        $preset = $statement->fetch();
        if (!$preset) {
            return null;
        }

        $recipients = $this->pdo->prepare(
            'SELECT r.*
             FROM recipients r
             INNER JOIN preset_recipients pr ON pr.recipient_id = r.id
             WHERE pr.preset_id = :preset_id
             ORDER BY r.name ASC'
        );
        $recipients->execute(['preset_id' => $id]);
        $preset['recipients'] = $recipients->fetchAll();
        $preset['attachments'] = (new AttachmentService($this->pdo))->forPreset($userId, $id);

        return $preset;
    }

    public function save(int $userId, array $input): int
    {
        $id = isset($input['id']) && $input['id'] !== '' ? (int) $input['id'] : null;
        $mailgunDomainId = isset($input['mailgun_domain_id']) && $input['mailgun_domain_id'] !== '' ? (int) $input['mailgun_domain_id'] : null;
        $name = trim((string) ($input['name'] ?? ''));
        $mailgunDomain = strtolower(trim((string) ($input['mailgun_domain'] ?? '')));
        $language = trim((string) ($input['language'] ?? ''));
        $topic = trim((string) ($input['topic'] ?? ''));
        $fromPattern = strtolower(trim((string) ($input['from_pattern'] ?? '')));
        $delayMode = (string) ($input['delay_mode'] ?? 'fixed');
        $delayMode = $delayMode === 'random' ? 'random' : 'fixed';
        $delayMin = max(0, (int) ($input['delay_min_seconds'] ?? 30));
        $delayMax = max(0, (int) ($input['delay_max_seconds'] ?? $delayMin));
        $batchSize = max(1, (int) ($input['batch_size'] ?? 1));
        $jsonPayload = trim((string) ($input['json_payload'] ?? ''));
        $recipientIds = $input['recipient_ids'] ?? [];
        if (!is_array($recipientIds)) {
            $recipientIds = [];
        }

        if ($name === '') {
            throw new \InvalidArgumentException('Preset name is required');
        }
        if ($fromPattern === '') {
            throw new \InvalidArgumentException('From pattern is required');
        }
        FromGenerator::preview($fromPattern);
        if (!Security::safeEmail(FromGenerator::preview($fromPattern))) {
            throw new \InvalidArgumentException('From pattern preview must be a valid email');
        }
        if ($delayMode === 'random' && $delayMax < $delayMin) {
            throw new \InvalidArgumentException('Delay max seconds must be greater than or equal to delay min seconds');
        }
        if ($mailgunDomainId) {
            $domain = (new DomainService($this->pdo))->find($userId, $mailgunDomainId);
            if (!$domain || (int) $domain['is_active'] !== 1) {
                throw new \InvalidArgumentException('Selected Mailgun domain is not active');
            }
            $mailgunDomain = $domain['domain'];
        }

        $validation = JsonValidator::validate($jsonPayload);
        if (!$validation['valid']) {
            throw new \InvalidArgumentException(implode('; ', $validation['errors']));
        }

        $recipients = (new RecipientService($this->pdo))->findActiveByIds($userId, $recipientIds);
        if (count($recipients) !== count(array_unique(array_map('intval', $recipientIds)))) {
            throw new \InvalidArgumentException('All preset recipients must be active whitelist recipients');
        }
        if (!$recipients) {
            throw new \InvalidArgumentException('At least one recipient is required');
        }

        $now = AuditLog::now();
        $this->pdo->beginTransaction();
        try {
            if ($id) {
                $existing = $this->find($userId, $id);
                if (!$existing) {
                    throw new \InvalidArgumentException('Preset not found');
                }

                $statement = $this->pdo->prepare(
                    'UPDATE presets
                     SET mailgun_domain_id = :mailgun_domain_id, name = :name, mailgun_domain = :mailgun_domain, language = :language, topic = :topic,
                         from_pattern = :from_pattern, delay_mode = :delay_mode,
                         delay_min_seconds = :delay_min_seconds, delay_max_seconds = :delay_max_seconds,
                         batch_size = :batch_size, json_payload = :json_payload, updated_at = :updated_at
                     WHERE id = :id AND user_id = :user_id'
                );
                $statement->execute([
                    'name' => $name,
                    'mailgun_domain_id' => $mailgunDomainId,
                    'mailgun_domain' => $mailgunDomain,
                    'language' => $language,
                    'topic' => $topic,
                    'from_pattern' => $fromPattern,
                    'delay_mode' => $delayMode,
                    'delay_min_seconds' => $delayMin,
                    'delay_max_seconds' => $delayMax,
                    'batch_size' => $batchSize,
                    'json_payload' => $jsonPayload,
                    'updated_at' => $now,
                    'id' => $id,
                    'user_id' => $userId,
                ]);
            } else {
                $statement = $this->pdo->prepare(
                    'INSERT INTO presets
                     (user_id, mailgun_domain_id, name, mailgun_domain, language, topic, from_pattern, delay_mode,
                      delay_min_seconds, delay_max_seconds, batch_size, json_payload, status, created_at, updated_at)
                     VALUES
                     (:user_id, :mailgun_domain_id, :name, :mailgun_domain, :language, :topic, :from_pattern, :delay_mode,
                      :delay_min_seconds, :delay_max_seconds, :batch_size, :json_payload, :status, :created_at, :updated_at)'
                );
                $statement->execute([
                    'user_id' => $userId,
                    'mailgun_domain_id' => $mailgunDomainId,
                    'name' => $name,
                    'mailgun_domain' => $mailgunDomain,
                    'language' => $language,
                    'topic' => $topic,
                    'from_pattern' => $fromPattern,
                    'delay_mode' => $delayMode,
                    'delay_min_seconds' => $delayMin,
                    'delay_max_seconds' => $delayMax,
                    'batch_size' => $batchSize,
                    'json_payload' => $jsonPayload,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $id = (int) $this->pdo->lastInsertId();
            }

            $delete = $this->pdo->prepare('DELETE FROM preset_recipients WHERE preset_id = :preset_id');
            $delete->execute(['preset_id' => $id]);

            $insert = $this->pdo->prepare('INSERT INTO preset_recipients (preset_id, recipient_id) VALUES (:preset_id, :recipient_id)');
            foreach ($recipients as $recipient) {
                $insert->execute(['preset_id' => $id, 'recipient_id' => $recipient['id']]);
            }

            $this->pdo->commit();
        } catch (\Throwable $throwable) {
            $this->pdo->rollBack();
            throw $throwable;
        }

        return $id;
    }

    public function clone(int $userId, int $presetId): int
    {
        $preset = $this->find($userId, $presetId);
        if (!$preset) {
            throw new \InvalidArgumentException('Preset not found');
        }

        $input = [
            'name' => $preset['name'] . ' Copy',
            'mailgun_domain_id' => $preset['mailgun_domain_id'],
            'mailgun_domain' => $preset['mailgun_domain'],
            'language' => $preset['language'],
            'topic' => $preset['topic'],
            'from_pattern' => $preset['from_pattern'],
            'delay_mode' => $preset['delay_mode'],
            'delay_min_seconds' => $preset['delay_min_seconds'],
            'delay_max_seconds' => $preset['delay_max_seconds'],
            'batch_size' => $preset['batch_size'],
            'json_payload' => $preset['json_payload'],
            'recipient_ids' => array_map(fn (array $row): int => (int) $row['id'], $preset['recipients']),
        ];

        $newId = $this->save($userId, $input);
        (new AttachmentService($this->pdo))->clonePresetAttachments($userId, $presetId, $newId);
        return $newId;
    }

    public function export(int $userId, int $presetId): array
    {
        $preset = $this->find($userId, $presetId);
        if (!$preset) {
            throw new \InvalidArgumentException('Preset not found');
        }

        return [
            'schema' => 'mail-test-sender-preset-v1',
            'name' => $preset['name'],
            'mailgun_domain' => $preset['mailgun_domain'],
            'language' => $preset['language'],
            'topic' => $preset['topic'],
            'from_pattern' => $preset['from_pattern'],
            'delay' => [
                'mode' => $preset['delay_mode'],
                'min_seconds' => (int) $preset['delay_min_seconds'],
                'max_seconds' => (int) $preset['delay_max_seconds'],
            ],
            'batch_size' => (int) $preset['batch_size'],
            'recipients' => array_map(fn (array $row): string => $row['email'], $preset['recipients']),
            'json_payload' => json_decode($preset['json_payload'], true),
            'attachments' => array_map(fn (array $row): array => [
                'original_name' => $row['original_name'],
                'mime_type' => $row['mime_type'],
                'size_bytes' => (int) $row['size_bytes'],
            ], $preset['attachments']),
        ];
    }

    public function import(int $userId, string $json): int
    {
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            throw new \InvalidArgumentException('Invalid preset import JSON');
        }
        if (($data['schema'] ?? '') !== 'mail-test-sender-preset-v1') {
            throw new \InvalidArgumentException('Unsupported preset schema');
        }

        $payload = json_encode($data['json_payload'] ?? null, JSON_UNESCAPED_SLASHES);
        $validation = JsonValidator::validate((string) $payload);
        if (!$validation['valid']) {
            throw new \InvalidArgumentException(implode('; ', $validation['errors']));
        }

        $recipientEmails = array_map('strtolower', array_map('trim', $data['recipients'] ?? []));
        if (!$recipientEmails) {
            throw new \InvalidArgumentException('Imported preset must contain recipients');
        }
        $activeRecipients = (new RecipientService($this->pdo))->active($userId);
        $byEmail = [];
        foreach ($activeRecipients as $recipient) {
            $byEmail[strtolower($recipient['email'])] = (int) $recipient['id'];
        }

        $recipientIds = [];
        foreach ($recipientEmails as $email) {
            if (!isset($byEmail[$email])) {
                throw new \InvalidArgumentException('Recipient must exist in whitelist before import: ' . $email);
            }
            $recipientIds[] = $byEmail[$email];
        }

        $domain = strtolower(trim((string) ($data['mailgun_domain'] ?? '')));
        $managedDomain = $domain !== '' ? (new DomainService($this->pdo))->findByDomainForUser($userId, $domain) : null;

        return $this->save($userId, [
            'name' => trim((string) ($data['name'] ?? 'Imported preset')),
            'mailgun_domain_id' => $managedDomain['id'] ?? null,
            'mailgun_domain' => $domain,
            'language' => (string) ($data['language'] ?? ''),
            'topic' => (string) ($data['topic'] ?? ''),
            'from_pattern' => (string) ($data['from_pattern'] ?? ''),
            'delay_mode' => (string) ($data['delay']['mode'] ?? 'fixed'),
            'delay_min_seconds' => (int) ($data['delay']['min_seconds'] ?? 30),
            'delay_max_seconds' => (int) ($data['delay']['max_seconds'] ?? 30),
            'batch_size' => (int) ($data['batch_size'] ?? 1),
            'json_payload' => $payload,
            'recipient_ids' => $recipientIds,
        ]);
    }

    public function summary(int $userId, int $presetId): array
    {
        $preset = $this->find($userId, $presetId);
        if (!$preset) {
            throw new \InvalidArgumentException('Preset not found');
        }

        $validation = JsonValidator::validate($preset['json_payload']);
        if (!$validation['valid']) {
            throw new \InvalidArgumentException(implode('; ', $validation['errors']));
        }

        $emailCount = count($validation['data']['emails']);
        $recipientCount = count(array_filter($preset['recipients'], fn (array $recipient): bool => (int) $recipient['is_active'] === 1));

        return [
            'preset' => $preset,
            'email_count' => $emailCount,
            'recipient_count' => $recipientCount,
            'total_outgoing' => $emailCount * $recipientCount,
            'json' => $validation['data'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App;

use PDO;

final class WebhookService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function handle(string $rawBody, array $post = []): array
    {
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            $payload = $post;
        }
        if (!is_array($payload) || !$payload) {
            throw new \InvalidArgumentException('Webhook payload is empty or invalid');
        }

        $eventData = is_array($payload['event-data'] ?? null) ? $payload['event-data'] : $payload;
        $signature = is_array($payload['signature'] ?? null) ? $payload['signature'] : [
            'timestamp' => $payload['timestamp'] ?? null,
            'token' => $payload['token'] ?? null,
            'signature' => is_string($payload['signature'] ?? null) ? $payload['signature'] : null,
        ];

        $domainName = strtolower((string) ($eventData['domain']['name'] ?? $eventData['domain'] ?? ''));
        if ($domainName === '') {
            throw new \InvalidArgumentException('Webhook domain is missing');
        }

        $domain = (new DomainService($this->pdo))->findByDomain($domainName);
        if (!$domain) {
            throw new \InvalidArgumentException('Webhook domain is not managed by this app');
        }

        $settings = (new MailgunSettingsService($this->pdo))->get((int) $domain['user_id']);
        $signingKey = (string) ($settings['webhook_signing_key_plain'] ?? '');
        if ($signingKey === '' || !self::verifySignature($signature, $signingKey)) {
            $this->storeEvent((int) $domain['user_id'], null, null, $domainName, $eventData, false);
            throw new \InvalidArgumentException('Webhook signature is invalid');
        }

        [$queueId, $jobId] = $this->findQueueAndJob($eventData);
        $eventId = (string) ($eventData['id'] ?? '');
        $eventType = self::eventType($eventData);
        $this->storeEvent((int) $domain['user_id'], $queueId, $jobId, $domainName, $eventData, true);

        if ($queueId) {
            $this->updateQueueFromEvent($queueId, $eventType, $eventData);
        }

        return [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'queue_id' => $queueId,
            'job_id' => $jobId,
        ];
    }

    public function recent(int $userId, bool $isAdmin = false): array
    {
        $sql = 'SELECT e.*, u.email AS user_email
                FROM mailgun_webhook_events e
                LEFT JOIN users u ON u.id = e.user_id';
        $params = [];
        if (!$isAdmin) {
            $sql .= ' WHERE e.user_id = :user_id';
            $params['user_id'] = $userId;
        }
        $sql .= ' ORDER BY e.created_at DESC LIMIT 200';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public static function verifySignature(array $signature, string $signingKey): bool
    {
        $timestamp = (string) ($signature['timestamp'] ?? '');
        $token = (string) ($signature['token'] ?? '');
        $known = (string) ($signature['signature'] ?? '');
        if ($timestamp === '' || $token === '' || $known === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . $token, $signingKey);
        return hash_equals($expected, $known);
    }

    public static function eventType(array $eventData): string
    {
        $event = (string) ($eventData['event'] ?? 'unknown');
        if ($event === 'failed') {
            $severity = (string) ($eventData['severity'] ?? '');
            return $severity === 'temporary' ? 'temporary_fail' : 'permanent_fail';
        }

        return $event;
    }

    private function findQueueAndJob(array $eventData): array
    {
        $variables = $eventData['user-variables'] ?? [];
        if (!is_array($variables)) {
            $variables = [];
        }
        $queueId = isset($variables['queue_id']) ? (int) $variables['queue_id'] : null;
        $jobId = isset($variables['job_id']) ? (int) $variables['job_id'] : null;

        if (!$queueId) {
            $mailgunMessageId = (string) ($eventData['message']['headers']['message-id'] ?? '');
            if ($mailgunMessageId !== '') {
                $statement = $this->pdo->prepare('SELECT id, job_id FROM email_queue WHERE mailgun_message_id = :id LIMIT 1');
                $statement->execute(['id' => $mailgunMessageId]);
                $row = $statement->fetch();
                if ($row) {
                    $queueId = (int) $row['id'];
                    $jobId = (int) $row['job_id'];
                }
            }
        }

        if ($queueId && !$jobId) {
            $statement = $this->pdo->prepare('SELECT job_id FROM email_queue WHERE id = :id LIMIT 1');
            $statement->execute(['id' => $queueId]);
            $jobId = (int) $statement->fetchColumn() ?: null;
        }

        return [$queueId, $jobId];
    }

    private function storeEvent(?int $userId, ?int $queueId, ?int $jobId, string $domainName, array $eventData, bool $signatureValid): void
    {
        $eventType = self::eventType($eventData);
        $statement = $this->pdo->prepare(
            'INSERT OR IGNORE INTO mailgun_webhook_events
             (user_id, queue_id, job_id, mailgun_domain, event_id, event_type, severity, recipient_email,
              message_id, signature_valid, payload, created_at)
             VALUES
             (:user_id, :queue_id, :job_id, :mailgun_domain, :event_id, :event_type, :severity, :recipient_email,
              :message_id, :signature_valid, :payload, :created_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'queue_id' => $queueId,
            'job_id' => $jobId,
            'mailgun_domain' => $domainName,
            'event_id' => $eventData['id'] ?? null,
            'event_type' => $eventType,
            'severity' => $eventData['severity'] ?? null,
            'recipient_email' => $eventData['recipient'] ?? null,
            'message_id' => $eventData['message']['headers']['message-id'] ?? null,
            'signature_valid' => $signatureValid ? 1 : 0,
            'payload' => json_encode($eventData, JSON_UNESCAPED_SLASHES),
            'created_at' => AuditLog::now(),
        ]);
    }

    private function updateQueueFromEvent(int $queueId, string $eventType, array $eventData): void
    {
        $fields = [
            'delivery_status' => $eventType,
            'id' => $queueId,
        ];
        $set = 'delivery_status = :delivery_status';
        if ($eventType === 'delivered') {
            $set .= ', delivered_at = :delivered_at';
            $fields['delivered_at'] = AuditLog::now();
        }
        if (in_array($eventType, ['permanent_fail', 'temporary_fail'], true)) {
            $set .= ', last_error = :last_error';
            $fields['last_error'] = (string) ($eventData['delivery-status']['message'] ?? $eventData['reason'] ?? $eventType);
        }

        $this->pdo->prepare("UPDATE email_queue SET $set WHERE id = :id")->execute($fields);
    }
}

<?php

declare(strict_types=1);

namespace App;

use PDO;

final class QueueService
{
    public function __construct(
        private PDO $pdo,
        private ?MailgunClient $mailgunClient = null
    ) {
        $this->mailgunClient ??= new MailgunClient();
    }

    public function createJob(int $userId, int $presetId): int
    {
        $presetService = new PresetService($this->pdo);
        $summary = $presetService->summary($userId, $presetId);
        $preset = $summary['preset'];
        $settings = (new DomainService($this->pdo))->settingsForPreset($userId, $preset);
        $domain = trim((string) $settings['domain']);

        if ($domain === '') {
            throw new \InvalidArgumentException('Mailgun domain is required in settings or preset');
        }
        if (empty($settings['api_key_plain'])) {
            throw new \InvalidArgumentException('Mailgun API key is required before creating a sending job');
        }
        if ($summary['total_outgoing'] < 1) {
            throw new \InvalidArgumentException('Preset must contain emails and active recipients');
        }

        $this->assertUserLimits($userId, (int) $summary['total_outgoing'], $settings);

        $now = AuditLog::now();
        $this->pdo->beginTransaction();
        try {
            $job = $this->pdo->prepare(
                'INSERT INTO sending_jobs (user_id, preset_id, status, total_emails, sent_count, failed_count, created_at)
                 VALUES (:user_id, :preset_id, :status, :total_emails, 0, 0, :created_at)'
            );
            $job->execute([
                'user_id' => $userId,
                'preset_id' => $presetId,
                'status' => 'pending',
                'total_emails' => $summary['total_outgoing'],
                'created_at' => $now,
            ]);
            $jobId = (int) $this->pdo->lastInsertId();
            (new AttachmentService($this->pdo))->snapshotPresetToJob($userId, $presetId, $jobId);

            $insert = $this->pdo->prepare(
                'INSERT INTO email_queue
                 (job_id, user_id, recipient_email, from_email, from_name, subject, body_text, body_html,
                  scheduled_at, status, attempts, created_at)
                 VALUES
                 (:job_id, :user_id, :recipient_email, :from_email, :from_name, :subject, :body_text, :body_html,
                  :scheduled_at, :status, 0, :created_at)'
            );

            $scheduled = new \DateTimeImmutable();
            $index = 0;
            foreach ($summary['json']['emails'] as $email) {
                foreach ($preset['recipients'] as $recipient) {
                    if ((int) $recipient['is_active'] !== 1) {
                        continue;
                    }

                    if ($index > 0) {
                        $scheduled = $scheduled->modify('+' . $this->nextDelaySeconds($preset) . ' seconds');
                    }

                    $insert->execute([
                        'job_id' => $jobId,
                        'user_id' => $userId,
                        'recipient_email' => $recipient['email'],
                        'from_email' => FromGenerator::generate($preset['from_pattern']),
                        'from_name' => (string) ($settings['default_from_name'] ?? ''),
                        'subject' => $email['subject'],
                        'body_text' => $email['body'],
                        'body_html' => null,
                        'scheduled_at' => $scheduled->format('Y-m-d H:i:s'),
                        'status' => 'pending',
                        'created_at' => $now,
                    ]);
                    $index++;
                }
            }

            (new AuditLog($this->pdo))->record($userId, 'job_created', ['job_id' => $jobId, 'preset_id' => $presetId]);
            $this->pdo->commit();
        } catch (\Throwable $throwable) {
            $this->pdo->rollBack();
            throw $throwable;
        }

        return $jobId;
    }

    public function processDue(int $limit): array
    {
        $limit = max(1, $limit);
        $now = AuditLog::now();
        $statement = $this->pdo->prepare(
            <<<'SQL'
SELECT q.*,
                    COALESCE(NULLIF(md.domain, ''), NULLIF(pd.domain, ''), NULLIF(p.mailgun_domain, ''), NULLIF(dd.domain, ''), NULLIF(ms.domain, '')) AS resolved_domain,
                    COALESCE(md.region, pd.region, dd.region, ms.region) AS resolved_region,
                    COALESCE(md.default_reply_to, pd.default_reply_to, dd.default_reply_to, ms.default_reply_to) AS resolved_reply_to,
                    COALESCE(md.test_mode, pd.test_mode, dd.test_mode, ms.test_mode) AS resolved_test_mode,
                    ms.api_key_encrypted,
                    j.paused_at
             FROM email_queue q
             INNER JOIN sending_jobs j ON j.id = q.job_id
             INNER JOIN presets p ON p.id = j.preset_id
             INNER JOIN mailgun_settings ms ON ms.user_id = q.user_id
             LEFT JOIN mailgun_domains md ON md.id = p.mailgun_domain_id AND md.user_id = q.user_id
             LEFT JOIN mailgun_domains pd ON pd.id = (
                 SELECT matched.id
                 FROM mailgun_domains matched
                 WHERE matched.user_id = q.user_id AND matched.domain = NULLIF(p.mailgun_domain, '')
                 ORDER BY matched.id ASC
                 LIMIT 1
             )
             LEFT JOIN mailgun_domains dd ON dd.id = (
                 SELECT fallback.id
                 FROM mailgun_domains fallback
                 WHERE fallback.user_id = q.user_id AND fallback.is_active = 1
                 ORDER BY fallback.is_default DESC, fallback.id ASC
                 LIMIT 1
             )
             WHERE q.status = :status AND q.scheduled_at <= :scheduled_at
               AND j.paused_at IS NULL
               AND j.status != :cancelled
             ORDER BY q.scheduled_at ASC, q.id ASC
             LIMIT :limit
SQL
        );
        $statement->bindValue(':status', 'pending');
        $statement->bindValue(':cancelled', 'cancelled');
        $statement->bindValue(':scheduled_at', $now);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();

        $result = ['processed' => 0, 'sent' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $result['processed']++;
            $this->markSending((int) $row['id'], (int) $row['job_id']);

            $settings = [
                'api_key' => Security::decrypt($row['api_key_encrypted']),
                'domain' => $row['resolved_domain'],
                'region' => $row['resolved_region'],
                'default_reply_to' => $row['resolved_reply_to'],
                'test_mode' => (int) $row['resolved_test_mode'] === 1,
            ];
            $row['attachments'] = (new AttachmentService($this->pdo))->forJob((int) $row['job_id']);

            try {
                $mailgunResult = $this->mailgunClient->send($settings, $row);
            } catch (\Throwable $throwable) {
                $mailgunResult = [
                    'ok' => false,
                    'status_code' => 0,
                    'message_id' => null,
                    'response' => null,
                    'error' => $throwable->getMessage(),
                ];
            }

            if ($mailgunResult['ok']) {
                $result['sent']++;
                $this->markSent($row, $mailgunResult);
            } else {
                $result['failed']++;
                $this->markFailed($row, $mailgunResult);
            }

            $this->recalculateJob((int) $row['job_id']);
        }

        return $result;
    }

    public function cancelJob(int $userId, int $jobId): void
    {
        $job = $this->jobForUser($userId, $jobId);
        if (!$job) {
            throw new \InvalidArgumentException('Job not found');
        }

        $now = AuditLog::now();
        $this->pdo->prepare("UPDATE email_queue SET status = :status WHERE job_id = :job_id AND status IN ('pending', 'failed')")
            ->execute(['status' => 'cancelled', 'job_id' => $jobId]);
        $this->pdo->prepare('UPDATE sending_jobs SET status = :status, finished_at = :finished_at WHERE id = :id')
            ->execute(['status' => 'cancelled', 'finished_at' => $now, 'id' => $jobId]);
        (new AuditLog($this->pdo))->record($userId, 'job_cancelled', ['job_id' => $jobId]);
    }

    public function retryFailed(int $userId, int $jobId): void
    {
        $job = $this->jobForUser($userId, $jobId);
        if (!$job) {
            throw new \InvalidArgumentException('Job not found');
        }

        $now = AuditLog::now();
        $this->pdo->prepare(
            'UPDATE email_queue
             SET status = :status, scheduled_at = :scheduled_at, last_error = NULL, sent_at = NULL, mailgun_message_id = NULL
             WHERE job_id = :job_id AND status = :failed'
        )->execute([
            'status' => 'pending',
            'scheduled_at' => $now,
            'job_id' => $jobId,
            'failed' => 'failed',
        ]);

        $this->pdo->prepare('UPDATE sending_jobs SET status = :status, finished_at = NULL WHERE id = :id')
            ->execute(['status' => 'pending', 'id' => $jobId]);
        $this->recalculateJob($jobId);
        (new AuditLog($this->pdo))->record($userId, 'job_retry_failed', ['job_id' => $jobId]);
    }

    public function pauseJob(int $userId, int $jobId): void
    {
        if (!$this->jobForUser($userId, $jobId)) {
            throw new \InvalidArgumentException('Job not found');
        }

        $this->pdo->prepare(
            "UPDATE sending_jobs SET paused_at = :paused_at WHERE id = :id AND user_id = :user_id AND status IN ('pending', 'running')"
        )->execute([
            'paused_at' => AuditLog::now(),
            'id' => $jobId,
            'user_id' => $userId,
        ]);
        (new AuditLog($this->pdo))->record($userId, 'job_paused', ['job_id' => $jobId]);
    }

    public function resumeJob(int $userId, int $jobId): void
    {
        if (!$this->jobForUser($userId, $jobId)) {
            throw new \InvalidArgumentException('Job not found');
        }

        $this->pdo->prepare(
            'UPDATE sending_jobs SET paused_at = NULL, finished_at = NULL WHERE id = :id AND user_id = :user_id AND paused_at IS NOT NULL'
        )->execute([
            'id' => $jobId,
            'user_id' => $userId,
        ]);
        (new AuditLog($this->pdo))->record($userId, 'job_resumed', ['job_id' => $jobId]);
    }

    public function jobs(int $userId, bool $isAdmin = false): array
    {
        $sql = "SELECT j.*, p.name AS preset_name, u.email AS user_email,
                       CASE WHEN j.status IN ('cancelled', 'completed', 'failed') THEN j.status
                            WHEN j.paused_at IS NOT NULL THEN 'paused'
                            ELSE j.status END AS display_status
                FROM sending_jobs j
                INNER JOIN presets p ON p.id = j.preset_id
                INNER JOIN users u ON u.id = j.user_id";
        $params = [];
        if (!$isAdmin) {
            $sql .= ' WHERE j.user_id = :user_id';
            $params['user_id'] = $userId;
        }
        $sql .= ' ORDER BY j.created_at DESC LIMIT 100';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public function queue(int $userId, bool $isAdmin = false): array
    {
        $sql = 'SELECT q.*, p.name AS preset_name, u.email AS user_email
                FROM email_queue q
                INNER JOIN sending_jobs j ON j.id = q.job_id
                INNER JOIN presets p ON p.id = j.preset_id
                INNER JOIN users u ON u.id = q.user_id';
        $params = [];
        if (!$isAdmin) {
            $sql .= ' WHERE q.user_id = :user_id';
            $params['user_id'] = $userId;
        }
        $sql .= ' ORDER BY q.scheduled_at DESC, q.id DESC LIMIT 200';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public function logs(int $userId, bool $isAdmin = false): array
    {
        $sql = 'SELECT l.*, u.email AS user_email
                FROM email_logs l
                INNER JOIN users u ON u.id = l.user_id';
        $params = [];
        if (!$isAdmin) {
            $sql .= ' WHERE l.user_id = :user_id';
            $params['user_id'] = $userId;
        }
        $sql .= ' ORDER BY l.created_at DESC LIMIT 200';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public function dashboardStats(int $userId, bool $isAdmin = false): array
    {
        $scope = $isAdmin ? '' : ' WHERE user_id = :user_id';
        $params = $isAdmin ? [] : ['user_id' => $userId];

        $presets = $this->countQuery('SELECT COUNT(*) FROM presets' . $scope, $params);
        $domains = $this->countQuery('SELECT COUNT(*) FROM mailgun_domains' . $scope, $params);
        $recipients = $this->countQuery('SELECT COUNT(*) FROM recipients' . $scope, $params);
        $attachments = $this->countQuery('SELECT COUNT(*) FROM preset_attachments' . $scope, $params);
        $pending = $this->countQuery("SELECT COUNT(*) FROM email_queue WHERE status = 'pending'" . ($isAdmin ? '' : ' AND user_id = :user_id'), $params);
        $sending = $this->countQuery("SELECT COUNT(*) FROM email_queue WHERE status = 'sending'" . ($isAdmin ? '' : ' AND user_id = :user_id'), $params);
        $pausedJobs = $this->countQuery('SELECT COUNT(*) FROM sending_jobs WHERE paused_at IS NOT NULL' . ($isAdmin ? '' : ' AND user_id = :user_id'), $params);
        $sentToday = $this->countQuery(
            "SELECT COUNT(*) FROM email_queue WHERE status = 'sent' AND sent_at >= :today" . ($isAdmin ? '' : ' AND user_id = :user_id'),
            array_merge($params, ['today' => date('Y-m-d 00:00:00')])
        );
        $failedToday = $this->countQuery(
            "SELECT COUNT(*) FROM email_queue WHERE status = 'failed' AND created_at >= :today" . ($isAdmin ? '' : ' AND user_id = :user_id'),
            array_merge($params, ['today' => date('Y-m-d 00:00:00')])
        );
        $sentAll = $this->countQuery("SELECT COUNT(*) FROM email_queue WHERE status = 'sent'" . ($isAdmin ? '' : ' AND user_id = :user_id'), $params);
        $failedAll = $this->countQuery("SELECT COUNT(*) FROM email_queue WHERE status = 'failed'" . ($isAdmin ? '' : ' AND user_id = :user_id'), $params);
        $deliveredToday = $this->countQuery(
            "SELECT COUNT(*) FROM email_queue WHERE delivery_status = 'delivered' AND delivered_at >= :today" . ($isAdmin ? '' : ' AND user_id = :user_id'),
            array_merge($params, ['today' => date('Y-m-d 00:00:00')])
        );
        $webhookEventsToday = $this->countQuery(
            'SELECT COUNT(*) FROM mailgun_webhook_events WHERE created_at >= :today' . ($isAdmin ? '' : ' AND user_id = :user_id'),
            array_merge($params, ['today' => date('Y-m-d 00:00:00')])
        );

        return [
            'presets' => $presets,
            'domains' => $domains,
            'recipients' => $recipients,
            'attachments' => $attachments,
            'pending' => $pending,
            'sending' => $sending,
            'paused_jobs' => $pausedJobs,
            'sent_today' => $sentToday,
            'failed_today' => $failedToday,
            'sent_all' => $sentAll,
            'failed_all' => $failedAll,
            'delivered_today' => $deliveredToday,
            'webhook_events_today' => $webhookEventsToday,
            'queue_by_status' => $this->groupCounts('email_queue', 'status', $userId, $isAdmin),
            'events_by_type' => $this->groupCounts('mailgun_webhook_events', 'event_type', $userId, $isAdmin),
        ];
    }

    private function assertUserLimits(int $userId, int $newMessages, array $settings): void
    {
        $today = date('Y-m-d 00:00:00');
        $hour = date('Y-m-d H:00:00');
        $todayCount = $this->countQuery(
            "SELECT COUNT(*) FROM email_queue WHERE user_id = :user_id AND created_at >= :created_at AND status != 'cancelled'",
            ['user_id' => $userId, 'created_at' => $today]
        );
        $hourCount = $this->countQuery(
            "SELECT COUNT(*) FROM email_queue WHERE user_id = :user_id AND created_at >= :created_at AND status != 'cancelled'",
            ['user_id' => $userId, 'created_at' => $hour]
        );

        if ($todayCount + $newMessages > (int) $settings['daily_limit']) {
            throw new \InvalidArgumentException('Daily limit would be exceeded');
        }
        if ($hourCount + $newMessages > (int) $settings['hourly_limit']) {
            throw new \InvalidArgumentException('Hourly limit would be exceeded');
        }
    }

    private function nextDelaySeconds(array $preset): int
    {
        $min = max(0, (int) $preset['delay_min_seconds']);
        $max = max($min, (int) $preset['delay_max_seconds']);
        if ($preset['delay_mode'] === 'random') {
            return random_int($min, $max);
        }

        return $min;
    }

    private function markSending(int $queueId, int $jobId): void
    {
        $now = AuditLog::now();
        $this->pdo->prepare('UPDATE email_queue SET status = :status, attempts = attempts + 1 WHERE id = :id')
            ->execute(['status' => 'sending', 'id' => $queueId]);
        $this->pdo->prepare(
            "UPDATE sending_jobs
             SET status = :status, started_at = COALESCE(started_at, :started_at)
             WHERE id = :id AND status IN ('pending', 'running')"
        )->execute(['status' => 'running', 'started_at' => $now, 'id' => $jobId]);
    }

    private function markSent(array $row, array $mailgunResult): void
    {
        $now = AuditLog::now();
        $this->pdo->prepare(
            'UPDATE email_queue
             SET status = :status, mailgun_message_id = :mailgun_message_id, last_error = NULL, sent_at = :sent_at
             WHERE id = :id'
        )->execute([
            'status' => 'sent',
            'mailgun_message_id' => $mailgunResult['message_id'],
            'sent_at' => $now,
            'id' => $row['id'],
        ]);
        $this->insertLog($row, 'sent', $mailgunResult['response'], null);
    }

    private function markFailed(array $row, array $mailgunResult): void
    {
        $error = (string) ($mailgunResult['error'] ?? 'Unknown Mailgun error');
        $this->pdo->prepare(
            'UPDATE email_queue SET status = :status, last_error = :last_error WHERE id = :id'
        )->execute([
            'status' => 'failed',
            'last_error' => $error,
            'id' => $row['id'],
        ]);
        $this->insertLog($row, 'failed', $mailgunResult['response'], $error);
        AppLog::write('error', 'mailgun.send_failed', [
            'user_id' => (int) $row['user_id'],
            'job_id' => (int) $row['job_id'],
            'queue_id' => (int) $row['id'],
            'domain' => $row['resolved_domain'] ?? null,
            'status_code' => (int) ($mailgunResult['status_code'] ?? 0),
            'error' => $error,
        ]);
    }

    private function insertLog(array $row, string $status, ?string $response, ?string $error): void
    {
        $this->pdo->prepare(
            'INSERT INTO email_logs
             (user_id, job_id, queue_id, recipient_email, from_email, subject, status, mailgun_response, error_message, created_at)
             VALUES
             (:user_id, :job_id, :queue_id, :recipient_email, :from_email, :subject, :status, :mailgun_response, :error_message, :created_at)'
        )->execute([
            'user_id' => $row['user_id'],
            'job_id' => $row['job_id'],
            'queue_id' => $row['id'],
            'recipient_email' => $row['recipient_email'],
            'from_email' => $row['from_email'],
            'subject' => $row['subject'],
            'status' => $status,
            'mailgun_response' => $response,
            'error_message' => $error,
            'created_at' => AuditLog::now(),
        ]);
    }

    private function recalculateJob(int $jobId): void
    {
        $counts = $this->pdo->prepare(
            "SELECT
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
                SUM(CASE WHEN status IN ('pending', 'sending') THEN 1 ELSE 0 END) AS open_count,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
                COUNT(*) AS total_count
             FROM email_queue WHERE job_id = :job_id"
        );
        $counts->execute(['job_id' => $jobId]);
        $row = $counts->fetch();
        $sent = (int) ($row['sent_count'] ?? 0);
        $failed = (int) ($row['failed_count'] ?? 0);
        $open = (int) ($row['open_count'] ?? 0);
        $cancelled = (int) ($row['cancelled_count'] ?? 0);
        $total = (int) ($row['total_count'] ?? 0);

        $job = $this->pdo->prepare('SELECT paused_at FROM sending_jobs WHERE id = :id');
        $job->execute(['id' => $jobId]);
        $pausedAt = $job->fetchColumn();
        $status = $open > 0 ? 'running' : ($failed > 0 ? 'failed' : 'completed');
        if ($open > 0 && $pausedAt) {
            $status = 'pending';
        }
        if ($cancelled === $total && $total > 0) {
            $status = 'cancelled';
        }
        $finished = $open > 0 ? null : AuditLog::now();

        $this->pdo->prepare(
            "UPDATE sending_jobs
             SET status = :status, sent_count = :sent_count, failed_count = :failed_count, finished_at = :finished_at
             WHERE id = :id AND status != 'cancelled'"
        )->execute([
            'status' => $status,
            'sent_count' => $sent,
            'failed_count' => $failed,
            'finished_at' => $finished,
            'id' => $jobId,
        ]);
    }

    private function jobForUser(int $userId, int $jobId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM sending_jobs WHERE id = :id AND user_id = :user_id LIMIT 1');
        $statement->execute(['id' => $jobId, 'user_id' => $userId]);
        return $statement->fetch() ?: null;
    }

    private function countQuery(string $sql, array $params): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return (int) $statement->fetchColumn();
    }

    private function groupCounts(string $table, string $column, int $userId, bool $isAdmin): array
    {
        $sql = "SELECT $column AS label, COUNT(*) AS total FROM $table";
        $params = [];
        if (!$isAdmin) {
            $sql .= ' WHERE user_id = :user_id';
            $params['user_id'] = $userId;
        }
        $sql .= " GROUP BY $column ORDER BY total DESC";

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }
}

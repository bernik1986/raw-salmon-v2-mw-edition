<?php

declare(strict_types=1);

namespace App;

use PDO;

final class AttachmentService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function forPreset(int $userId, int $presetId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM preset_attachments WHERE user_id = :user_id AND preset_id = :preset_id ORDER BY created_at DESC');
        $statement->execute(['user_id' => $userId, 'preset_id' => $presetId]);
        return $statement->fetchAll();
    }

    public function forJob(int $jobId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM job_attachments WHERE job_id = :job_id ORDER BY id ASC');
        $statement->execute(['job_id' => $jobId]);
        return array_map(fn (array $row): array => $row + ['path' => $this->path($row['stored_name'])], $statement->fetchAll());
    }

    public function addUploadedFiles(int $userId, int $presetId, array $files): void
    {
        if (empty($files['name']) || !is_array($files['name'])) {
            return;
        }

        $dir = (string) app_config('attachment_storage_path');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO preset_attachments
             (user_id, preset_id, original_name, stored_name, mime_type, size_bytes, created_at)
             VALUES (:user_id, :preset_id, :original_name, :stored_name, :mime_type, :size_bytes, :created_at)'
        );

        foreach ($files['name'] as $index => $name) {
            if (($files['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if (($files['error'][$index] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                throw new \InvalidArgumentException('Attachment upload failed: ' . (string) $name);
            }

            $size = (int) ($files['size'][$index] ?? 0);
            if ($size < 1) {
                throw new \InvalidArgumentException('Attachment is empty: ' . (string) $name);
            }
            if ($size > (int) app_config('max_attachment_bytes')) {
                throw new \InvalidArgumentException('Attachment is too large: ' . (string) $name);
            }

            $original = basename((string) $name);
            $stored = bin2hex(random_bytes(16)) . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $original);
            $target = $dir . '/' . $stored;
            if (!move_uploaded_file((string) $files['tmp_name'][$index], $target)) {
                throw new \RuntimeException('Unable to store attachment: ' . $original);
            }

            $insert->execute([
                'user_id' => $userId,
                'preset_id' => $presetId,
                'original_name' => $original,
                'stored_name' => $stored,
                'mime_type' => (string) ($files['type'][$index] ?? 'application/octet-stream'),
                'size_bytes' => $size,
                'created_at' => AuditLog::now(),
            ]);
        }
    }

    public function deletePresetAttachment(int $userId, int $attachmentId): void
    {
        $statement = $this->pdo->prepare('SELECT * FROM preset_attachments WHERE id = :id AND user_id = :user_id');
        $statement->execute(['id' => $attachmentId, 'user_id' => $userId]);
        $row = $statement->fetch();
        if (!$row) {
            throw new \InvalidArgumentException('Attachment not found');
        }

        $this->pdo->prepare('DELETE FROM preset_attachments WHERE id = :id AND user_id = :user_id')
            ->execute(['id' => $attachmentId, 'user_id' => $userId]);

        $usage = $this->pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM preset_attachments WHERE stored_name = :stored_name) +
                (SELECT COUNT(*) FROM job_attachments WHERE stored_name = :stored_name) AS total_count'
        );
        $usage->execute(['stored_name' => $row['stored_name']]);
        if ((int) $usage->fetchColumn() === 0) {
            $path = $this->path($row['stored_name']);
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function snapshotPresetToJob(int $userId, int $presetId, int $jobId): void
    {
        $attachments = $this->forPreset($userId, $presetId);
        if (!$attachments) {
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO job_attachments
             (job_id, user_id, original_name, stored_name, mime_type, size_bytes, created_at)
             VALUES (:job_id, :user_id, :original_name, :stored_name, :mime_type, :size_bytes, :created_at)'
        );
        foreach ($attachments as $attachment) {
            $insert->execute([
                'job_id' => $jobId,
                'user_id' => $userId,
                'original_name' => $attachment['original_name'],
                'stored_name' => $attachment['stored_name'],
                'mime_type' => $attachment['mime_type'],
                'size_bytes' => $attachment['size_bytes'],
                'created_at' => AuditLog::now(),
            ]);
        }
    }

    public function clonePresetAttachments(int $userId, int $sourcePresetId, int $targetPresetId): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO preset_attachments
             (user_id, preset_id, original_name, stored_name, mime_type, size_bytes, created_at)
             VALUES (:user_id, :preset_id, :original_name, :stored_name, :mime_type, :size_bytes, :created_at)'
        );
        foreach ($this->forPreset($userId, $sourcePresetId) as $attachment) {
            $insert->execute([
                'user_id' => $userId,
                'preset_id' => $targetPresetId,
                'original_name' => $attachment['original_name'],
                'stored_name' => $attachment['stored_name'],
                'mime_type' => $attachment['mime_type'],
                'size_bytes' => $attachment['size_bytes'],
                'created_at' => AuditLog::now(),
            ]);
        }
    }

    public function path(string $storedName): string
    {
        return rtrim((string) app_config('attachment_storage_path'), '/\\') . '/' . $storedName;
    }
}

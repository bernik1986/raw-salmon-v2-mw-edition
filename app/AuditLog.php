<?php

declare(strict_types=1);

namespace App;

use PDO;

final class AuditLog
{
    public function __construct(private PDO $pdo)
    {
    }

    public function record(?int $userId, string $action, array $details = []): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_logs (user_id, action, details, created_at) VALUES (:user_id, :action, :details, :created_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'action' => $action,
            'details' => $details ? json_encode($details, JSON_UNESCAPED_SLASHES) : null,
            'created_at' => self::now(),
        ]);
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

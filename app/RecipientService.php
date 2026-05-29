<?php

declare(strict_types=1);

namespace App;

use PDO;

final class RecipientService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(int $userId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM recipients WHERE user_id = :user_id ORDER BY is_active DESC, name ASC');
        $statement->execute(['user_id' => $userId]);
        return $statement->fetchAll();
    }

    public function active(int $userId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM recipients WHERE user_id = :user_id AND is_active = 1 ORDER BY name ASC');
        $statement->execute(['user_id' => $userId]);
        return $statement->fetchAll();
    }

    public function create(int $userId, string $name, string $email): int
    {
        $name = trim($name);
        $email = strtolower(trim($email));
        if ($name === '') {
            throw new \InvalidArgumentException('Recipient name is required');
        }
        if (!Security::safeEmail($email)) {
            throw new \InvalidArgumentException('Valid recipient email is required');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO recipients (user_id, name, email, is_active, created_at)
             VALUES (:user_id, :name, :email, 1, :created_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'name' => $name,
            'email' => $email,
            'created_at' => AuditLog::now(),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function setActive(int $userId, int $recipientId, bool $active): void
    {
        $statement = $this->pdo->prepare('UPDATE recipients SET is_active = :is_active WHERE id = :id AND user_id = :user_id');
        $statement->execute([
            'is_active' => $active ? 1 : 0,
            'id' => $recipientId,
            'user_id' => $userId,
        ]);
    }

    public function findActiveByIds(int $userId, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(
            "SELECT * FROM recipients WHERE user_id = ? AND is_active = 1 AND id IN ($placeholders) ORDER BY name ASC"
        );
        $statement->execute(array_merge([$userId], $ids));

        return $statement->fetchAll();
    }
}

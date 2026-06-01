<?php

declare(strict_types=1);

namespace App;

use PDO;

final class UserService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM users ORDER BY created_at DESC')->fetchAll();
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE id = :id');
        $statement->execute(['id' => $id]);
        return $statement->fetch() ?: null;
    }

    public function create(string $name, string $email, string $password, string $role = 'user'): int
    {
        $name = trim($name);
        $email = strtolower(trim($email));
        $role = $role === 'admin' ? 'admin' : 'user';

        if ($name === '') {
            throw new \InvalidArgumentException('Name is required');
        }
        if (!Security::safeEmail($email)) {
            throw new \InvalidArgumentException('Valid email is required');
        }
        if (strlen($password) < 8) {
            throw new \InvalidArgumentException('Password must be at least 8 characters');
        }

        $now = AuditLog::now();
        $statement = $this->pdo->prepare(
            'INSERT INTO users (name, email, password_hash, role, status, created_at, updated_at)
             VALUES (:name, :email, :password_hash, :role, :status, :created_at, :updated_at)'
        );
        $statement->execute([
            'name' => $name,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name, string $email, string $role, string $status): void
    {
        $name = trim($name);
        $email = strtolower(trim($email));
        $role = $role === 'admin' ? 'admin' : 'user';
        $status = $status === 'inactive' ? 'inactive' : 'active';

        if ($name === '' || !Security::safeEmail($email)) {
            throw new \InvalidArgumentException('Name and valid email are required');
        }

        $statement = $this->pdo->prepare(
            'UPDATE users SET name = :name, email = :email, role = :role, status = :status, updated_at = :updated_at WHERE id = :id'
        );
        $statement->execute([
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'status' => $status,
            'updated_at' => AuditLog::now(),
            'id' => $id,
        ]);
    }

    public function resetPassword(int $id, string $password): void
    {
        if (strlen($password) < 8) {
            throw new \InvalidArgumentException('Password must be at least 8 characters');
        }

        $statement = $this->pdo->prepare('UPDATE users SET password_hash = :password_hash, updated_at = :updated_at WHERE id = :id');
        $statement->execute([
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'updated_at' => AuditLog::now(),
            'id' => $id,
        ]);
    }

    public function resetAdminPasswordByEmail(string $email, string $password): void
    {
        $email = strtolower(trim($email));
        if (!Security::safeEmail($email) || strlen($password) < 8) {
            throw new \InvalidArgumentException('Invalid recovery token or admin email');
        }

        $statement = $this->pdo->prepare(
            "UPDATE users
             SET password_hash = :password_hash, status = 'active', updated_at = :updated_at
             WHERE email = :email AND role = 'admin'"
        );
        $statement->execute([
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'updated_at' => AuditLog::now(),
            'email' => $email,
        ]);

        if ($statement->rowCount() !== 1) {
            throw new \InvalidArgumentException('Invalid recovery token or admin email');
        }
    }
}

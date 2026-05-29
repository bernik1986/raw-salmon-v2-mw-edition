<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Auth
{
    public function __construct(private PDO $pdo)
    {
    }

    public function login(string $email, string $password): bool
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $statement->execute(['email' => strtolower(trim($email))]);
        $user = $statement->fetch();

        if (!$user || $user['status'] !== 'active' || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['last_activity'] = time();

        $update = $this->pdo->prepare('UPDATE users SET last_login_at = :last_login_at WHERE id = :id');
        $update->execute(['last_login_at' => AuditLog::now(), 'id' => $user['id']]);
        (new AuditLog($this->pdo))->record((int) $user['id'], 'login');

        return true;
    }

    public function logout(): void
    {
        unset($_SESSION['user_id'], $_SESSION['last_activity']);
        session_regenerate_id(true);
    }

    public function user(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }

        $last = (int) ($_SESSION['last_activity'] ?? 0);
        $limit = (int) app_config('session_lifetime_seconds', 3600);
        if ($last > 0 && time() - $last > $limit) {
            $this->logout();
            return null;
        }

        $statement = $this->pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => (int) $_SESSION['user_id']]);
        $user = $statement->fetch() ?: null;
        if (!$user || $user['status'] !== 'active') {
            $this->logout();
            return null;
        }

        $_SESSION['last_activity'] = time();
        return $user;
    }

    public function requireUser(): array
    {
        $user = $this->user();
        if (!$user) {
            redirect('/login.php');
        }

        return $user;
    }

    public function requireAdmin(): array
    {
        $user = $this->requireUser();
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            exit('Forbidden');
        }

        return $user;
    }
}

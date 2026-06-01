<?php

declare(strict_types=1);

namespace App;

use PDO;

final class AdminRecoveryService
{
    private string $localPath;

    public function __construct(private PDO $pdo, ?string $localPath = null)
    {
        $this->localPath = $localPath ?? APP_BASE_PATH . '/config/local.php';
    }

    public function ensureToken(): void
    {
        $local = $this->readLocal();
        if (!empty($local['admin_recovery_token'])) {
            return;
        }

        $local['admin_recovery_token'] = bin2hex(random_bytes(24));
        $this->writeLocal($local);
    }

    public function resetAdminPassword(string $token, string $email, string $password): void
    {
        $local = $this->readLocal();
        $savedToken = (string) ($local['admin_recovery_token'] ?? '');
        if ($savedToken === '' || $token === '' || !hash_equals($savedToken, trim($token))) {
            throw new \InvalidArgumentException('Invalid recovery token or admin email');
        }

        (new UserService($this->pdo))->resetAdminPasswordByEmail($email, $password);
        $local['admin_recovery_token'] = bin2hex(random_bytes(24));
        $this->writeLocal($local);
    }

    private function readLocal(): array
    {
        if (!is_file($this->localPath)) {
            throw new \RuntimeException('Application config/local.php was not found');
        }

        $local = require $this->localPath;
        if (!is_array($local)) {
            throw new \RuntimeException('Application config/local.php is invalid');
        }

        return $local;
    }

    private function writeLocal(array $local): void
    {
        $content = "<?php\n\nreturn " . var_export($local, true) . ";\n";
        if (@file_put_contents($this->localPath, $content, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to update config/local.php. Check config directory permissions.');
        }
    }
}

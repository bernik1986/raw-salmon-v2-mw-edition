<?php

declare(strict_types=1);

namespace App;

final class AppLog
{
    public static function write(string $level, string $event, array $context = [], ?string $path = null): void
    {
        $path ??= (string) app_config('app_log_path', APP_BASE_PATH . '/storage/logs/app.log');
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $line = json_encode([
            'date' => AuditLog::now(),
            'level' => strtolower(trim($level)),
            'event' => trim($event),
            'context' => self::sanitize($context),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($line !== false) {
            @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }

    public static function recent(int $limit = 100, ?string $path = null): array
    {
        $path ??= (string) app_config('app_log_path', APP_BASE_PATH . '/storage/logs/app.log');
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $events = [];
        foreach (array_reverse(array_slice($lines, -max(1, $limit))) as $line) {
            $event = json_decode($line, true);
            if (is_array($event)) {
                $events[] = $event;
            }
        }

        return $events;
    }

    private static function sanitize(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match('/(?:api[_-]?key|token|secret|password|signature|authorization|auth)/i', $key)) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            $clean = [];
            foreach ($value as $childKey => $childValue) {
                $clean[$childKey] = self::sanitize($childValue, (string) $childKey);
            }
            return $clean;
        }

        if (is_object($value)) {
            return self::sanitize((array) $value);
        }

        if (is_string($value)) {
            return mb_substr($value, 0, 1000);
        }

        return $value;
    }
}

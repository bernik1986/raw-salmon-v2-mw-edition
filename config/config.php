<?php

$basePath = dirname(__DIR__);

$defaults = [
    'app_name' => 'RAW SALMON V2.0 MW edition',
    'timezone' => 'Europe/Paris',
    'app_key' => 'change-me-before-use',
    'db_path' => $basePath . '/storage/app.sqlite',
    'session_name' => 'mail_test_sender',
    'session_lifetime_seconds' => 3600,
    'session_storage_path' => $basePath . '/storage/sessions',
    'cron_token' => 'change-me-before-use',
    'max_send_per_cron_run' => 30,
    'mailgun_timeout_seconds' => 20,
    'max_attachment_bytes' => 5242880,
    'attachment_storage_path' => $basePath . '/storage/attachments',
    'app_log_path' => $basePath . '/storage/logs/app.log',
];

$localPath = __DIR__ . '/local.php';
if (is_file($localPath)) {
    $local = require $localPath;
    if (is_array($local)) {
        return array_replace($defaults, $local);
    }
}

return $defaults;

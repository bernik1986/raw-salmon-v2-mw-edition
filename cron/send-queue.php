<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\QueueService;

header('Content-Type: application/json');

if (!is_installed()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Application is not installed']);
    exit;
}

$token = (string) ($_GET['token'] ?? '');
if (PHP_SAPI === 'cli') {
    foreach ($argv ?? [] as $argument) {
        if (str_starts_with($argument, '--token=')) {
            $token = substr($argument, 8);
        }
    }
}

if (!hash_equals((string) app_config('cron_token'), $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid cron token']);
    exit;
}

$limit = (int) app_config('max_send_per_cron_run', 30);
$result = (new QueueService(db()))->processDue($limit);
echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_SLASHES);

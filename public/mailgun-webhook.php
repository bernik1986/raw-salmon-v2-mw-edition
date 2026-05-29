<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\WebhookService;

header('Content-Type: application/json');

if (!is_installed()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Application is not installed']);
    exit;
}

try {
    $raw = (string) file_get_contents('php://input');
    $result = (new WebhookService(db()))->handle($raw, $_POST);
    echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_SLASHES);
} catch (Throwable $throwable) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => $throwable->getMessage()], JSON_UNESCAPED_SLASHES);
}

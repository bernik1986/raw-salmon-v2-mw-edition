<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
ensure_installed();

use App\PresetService;

$user = require_auth();
$id = (int) ($_GET['id'] ?? 0);
$data = (new PresetService(db()))->export((int) $user['id'], $id);
$filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $data['name']) . '.preset.json';

header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

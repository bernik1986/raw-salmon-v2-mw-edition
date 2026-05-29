<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Database;
use App\DomainService;
use App\FromGenerator;
use App\JsonValidator;
use App\MailgunClient;
use App\MailgunSettingsService;
use App\PresetService;
use App\QueueService;
use App\RecipientService;
use App\Security;
use App\UserService;
use App\WebhookService;

$tests = [];
$skipped = [];
$testDatabases = [];

foreach (glob(APP_BASE_PATH . '/storage/test-*.sqlite') ?: [] as $oldTestDatabase) {
    if (is_file($oldTestDatabase)) {
        unlink($oldTestDatabase);
    }
}

register_shutdown_function(function () use (&$testDatabases): void {
    foreach ($testDatabases as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

function test(string $name, callable $callback): void
{
    global $tests;
    $tests[$name] = $callback;
}

function skip_test(string $name, string $reason): void
{
    global $skipped;
    $skipped[$name] = $reason;
}

function assert_true(bool $condition, string $message = 'Expected true'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_same(mixed $expected, mixed $actual, string $message = 'Values are not identical'): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assert_contains(string $needle, string $haystack, string $message = 'String does not contain expected text'): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message . ' Missing: ' . $needle);
    }
}

final class FakeMailgunClient extends MailgunClient
{
    public array $sent = [];

    public function __construct(private bool $fail = false)
    {
    }

    public function send(array $settings, array $message): array
    {
        $this->sent[] = ['settings' => $settings, 'message' => $message];

        if ($this->fail) {
            return [
                'ok' => false,
                'status_code' => 500,
                'message_id' => null,
                'response' => '{"message":"failed"}',
                'error' => 'fake failure',
            ];
        }

        return [
            'ok' => true,
            'status_code' => 200,
            'message_id' => '<fake@mailgun>',
            'response' => '{"id":"<fake@mailgun>","message":"Queued. Thank you."}',
            'error' => null,
        ];
    }

    public function testConnection(string $apiKey, string $region, string $domain): array
    {
        $this->sent[] = ['test' => compact('apiKey', 'region', 'domain')];
        return [
            'ok' => !$this->fail,
            'status_code' => $this->fail ? 401 : 200,
            'response' => $this->fail ? '{"message":"Forbidden"}' : '{"domain":{"name":"' . $domain . '"}}',
            'error' => $this->fail ? 'Forbidden' : null,
        ];
    }
}

function integration_pdo(): PDO
{
    global $testDatabases;
    if (!extension_loaded('pdo_sqlite') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('SKIP: pdo_sqlite is not available');
    }

    $path = APP_BASE_PATH . '/storage/test-' . bin2hex(random_bytes(4)) . '.sqlite';
    $testDatabases[] = $path;
    $config = array_replace(app_config(), [
        'app_key' => 'test-key-' . bin2hex(random_bytes(8)),
        'db_path' => $path,
    ]);
    $pdo = Database::connect($config);
    Database::migrate($pdo);
    return $pdo;
}

function sample_json(): string
{
    return json_encode([
        'preset_name' => 'Banking test emails FR',
        'language' => 'fr',
        'topic' => 'banking customer support',
        'emails' => [
            [
                'subject' => 'Question concernant mon compte',
                'body' => "Bonjour, je voudrais savoir pourquoi mon virement n'est pas encore arrive.",
                'tags' => ['banking', 'transfer'],
            ],
            [
                'subject' => null,
                'body' => 'Bonjour, pouvez-vous confirmer si ma carte est activee ?',
                'tags' => ['banking', 'card'],
            ],
        ],
    ], JSON_UNESCAPED_SLASHES);
}

test('JsonValidator accepts the planned schema', function (): void {
    $result = JsonValidator::validate(sample_json());
    assert_true($result['valid'], implode('; ', $result['errors']));
    assert_same(2, count($result['data']['emails']));
});

test('JsonValidator rejects subject and body both null', function (): void {
    $payload = json_encode([
        'preset_name' => 'Invalid',
        'language' => 'fr',
        'topic' => 'banking',
        'emails' => [['subject' => null, 'body' => null, 'tags' => []]],
    ]);
    $result = JsonValidator::validate($payload);
    assert_true(!$result['valid']);
    assert_contains('cannot have subject and body both null', implode('; ', $result['errors']));
});

test('JsonValidator rejects invalid JSON', function (): void {
    $result = JsonValidator::validate('{broken');
    assert_true(!$result['valid']);
    assert_contains('Invalid JSON', implode('; ', $result['errors']));
});

test('FromGenerator previews and generates valid addresses', function (): void {
    $preview = FromGenerator::preview('qa-{date}-{random}@testmail.example.com');
    assert_contains('@testmail.example.com', $preview);
    assert_true(Security::safeEmail($preview));

    $generated = FromGenerator::generate('client-{random}@testmail.example.com', new DateTimeImmutable('2026-05-28'));
    assert_true(Security::safeEmail($generated));
    assert_contains('@testmail.example.com', $generated);
});

test('Security encrypts, decrypts, and masks API keys', function (): void {
    $secret = 'key-test-secret-123456789';
    $encrypted = Security::encrypt($secret);
    assert_true($encrypted !== null && $encrypted !== $secret);
    assert_same($secret, Security::decrypt($encrypted));
    assert_same('key-*****************6789', Security::maskSecret($secret));
});

test('MailgunClient builds region endpoint and payload', function (): void {
    assert_same('https://api.mailgun.net/v3/mg.example.com/messages', MailgunClient::endpoint('US', 'mg.example.com'));
    assert_same('https://api.eu.mailgun.net/v3/mg.example.com/messages', MailgunClient::endpoint('EU', 'mg.example.com'));

    $payload = MailgunClient::payload(
        ['default_reply_to' => 'no-reply@example.com', 'test_mode' => true],
        [
            'from_name' => 'Client Test',
            'from_email' => 'client-1@example.com',
            'recipient_email' => 'qa@example.com',
            'subject' => null,
            'body_text' => 'hello',
        ]
    );
    assert_same('"Client Test" <client-1@example.com>', $payload['from']);
    assert_same('qa@example.com', $payload['to']);
    assert_same('', $payload['subject']);
    assert_same('yes', $payload['o:testmode']);
    assert_same('no-reply@example.com', $payload['h:Reply-To']);

    $payloadWithAttachment = MailgunClient::payload(
        ['default_reply_to' => '', 'test_mode' => false],
        [
            'id' => 55,
            'job_id' => 44,
            'from_email' => 'client-1@example.com',
            'recipient_email' => 'qa@example.com',
            'body_text' => 'hello',
            'attachments' => [],
        ]
    );
    assert_same('55', $payloadWithAttachment['v:queue_id']);
    assert_same('44', $payloadWithAttachment['v:job_id']);
});

test('Database services create users, settings, recipients, presets, and queue', function (): void {
    $pdo = integration_pdo();
    $userService = new UserService($pdo);
    $userId = $userService->create('QA User', 'qa@example.com', 'password123', 'user');

    (new MailgunSettingsService($pdo))->save($userId, [
        'api_key' => 'key-test',
        'domain' => 'mg.example.com',
        'region' => 'EU',
        'default_from_name' => 'Client Test',
        'default_reply_to' => 'no-reply@example.com',
        'test_mode' => '1',
        'daily_limit' => '100',
        'hourly_limit' => '100',
    ]);

    $recipientService = new RecipientService($pdo);
    $r1 = $recipientService->create($userId, 'Program A', 'program-a@example.com');
    $r2 = $recipientService->create($userId, 'Program B', 'program-b@example.com');

    $presetId = (new PresetService($pdo))->save($userId, [
        'name' => 'KAI FR Banking 2 emails',
        'mailgun_domain' => 'mg.example.com',
        'language' => 'fr',
        'topic' => 'banking customer support',
        'from_pattern' => 'client-{random}@mg.example.com',
        'delay_mode' => 'fixed',
        'delay_min_seconds' => '0',
        'delay_max_seconds' => '0',
        'batch_size' => '1',
        'json_payload' => sample_json(),
        'recipient_ids' => [$r1, $r2],
    ]);

    $fake = new FakeMailgunClient(false);
    $queue = new QueueService($pdo, $fake);
    $jobId = $queue->createJob($userId, $presetId);
    assert_same(4, (int) $pdo->query('SELECT COUNT(*) FROM email_queue')->fetchColumn());

    $result = $queue->processDue(10);
    assert_same(4, $result['sent']);
    assert_same(4, count($fake->sent));
    assert_same('completed', (string) $pdo->query("SELECT status FROM sending_jobs WHERE id = $jobId")->fetchColumn());
    assert_same(4, (int) $pdo->query('SELECT COUNT(*) FROM email_logs WHERE status = "sent"')->fetchColumn());
});

test('Queue supports failed retry and cancellation', function (): void {
    $pdo = integration_pdo();
    $userId = (new UserService($pdo))->create('QA User', 'retry@example.com', 'password123', 'user');
    (new MailgunSettingsService($pdo))->save($userId, [
        'api_key' => 'key-test',
        'domain' => 'mg.example.com',
        'region' => 'US',
        'default_from_name' => '',
        'default_reply_to' => '',
        'test_mode' => '1',
        'daily_limit' => '100',
        'hourly_limit' => '100',
    ]);
    $recipientId = (new RecipientService($pdo))->create($userId, 'Program A', 'program-a@example.com');
    $presetInput = [
        'name' => 'Retry preset',
        'mailgun_domain' => 'mg.example.com',
        'language' => 'fr',
        'topic' => 'banking',
        'from_pattern' => 'qa-{random}@mg.example.com',
        'delay_mode' => 'fixed',
        'delay_min_seconds' => '0',
        'delay_max_seconds' => '0',
        'batch_size' => '1',
        'json_payload' => sample_json(),
        'recipient_ids' => [$recipientId],
    ];
    $presetId = (new PresetService($pdo))->save($userId, $presetInput);

    $failQueue = new QueueService($pdo, new FakeMailgunClient(true));
    $jobId = $failQueue->createJob($userId, $presetId);
    $failQueue->processDue(10);
    assert_same('failed', (string) $pdo->query("SELECT status FROM sending_jobs WHERE id = $jobId")->fetchColumn());

    $okFake = new FakeMailgunClient(false);
    $okQueue = new QueueService($pdo, $okFake);
    $okQueue->retryFailed($userId, $jobId);
    $okQueue->processDue(10);
    assert_same('completed', (string) $pdo->query("SELECT status FROM sending_jobs WHERE id = $jobId")->fetchColumn());

    $cancelJobId = $okQueue->createJob($userId, $presetId);
    $okQueue->cancelJob($userId, $cancelJobId);
    assert_same('cancelled', (string) $pdo->query("SELECT status FROM sending_jobs WHERE id = $cancelJobId")->fetchColumn());
});

test('Domains can be managed and tested without sending mail', function (): void {
    $pdo = integration_pdo();
    $userId = (new UserService($pdo))->create('Domain User', 'domain@example.com', 'password123', 'user');
    (new MailgunSettingsService($pdo))->save($userId, [
        'api_key' => 'key-test',
        'webhook_signing_key' => 'signing-secret',
        'domain' => '',
        'region' => 'US',
        'test_mode' => '1',
        'daily_limit' => '100',
        'hourly_limit' => '100',
    ]);

    $fake = new FakeMailgunClient(false);
    $domains = new DomainService($pdo, $fake);
    $domainId = $domains->save($userId, [
        'domain' => 'mg-domain.example.com',
        'region' => 'EU',
        'default_from_name' => 'QA',
        'default_reply_to' => 'reply@example.com',
        'test_mode' => '1',
        'daily_limit' => '200',
        'hourly_limit' => '50',
        'is_active' => '1',
        'is_default' => '1',
    ]);

    $result = $domains->test($userId, $domainId);
    assert_true($result['ok']);
    $domain = $domains->find($userId, $domainId);
    assert_same('ok', $domain['last_test_status']);
    assert_same('EU', $domain['region']);
});

test('Preset export import clone and pause resume work', function (): void {
    $pdo = integration_pdo();
    $userId = (new UserService($pdo))->create('Preset User', 'preset@example.com', 'password123', 'user');
    (new MailgunSettingsService($pdo))->save($userId, [
        'api_key' => 'key-test',
        'domain' => '',
        'region' => 'US',
        'test_mode' => '1',
        'daily_limit' => '100',
        'hourly_limit' => '100',
    ]);
    $domainId = (new DomainService($pdo))->save($userId, [
        'domain' => 'mg-preset.example.com',
        'region' => 'US',
        'test_mode' => '1',
        'daily_limit' => '100',
        'hourly_limit' => '100',
        'is_active' => '1',
        'is_default' => '1',
    ]);
    $recipientId = (new RecipientService($pdo))->create($userId, 'Program A', 'program-a@example.com');
    $service = new PresetService($pdo);
    $presetId = $service->save($userId, [
        'name' => 'Exportable',
        'mailgun_domain_id' => $domainId,
        'language' => 'fr',
        'topic' => 'banking',
        'from_pattern' => 'qa-{random}@mg-preset.example.com',
        'delay_mode' => 'fixed',
        'delay_min_seconds' => '0',
        'delay_max_seconds' => '0',
        'json_payload' => sample_json(),
        'recipient_ids' => [$recipientId],
    ]);

    $cloneId = $service->clone($userId, $presetId);
    assert_true($cloneId !== $presetId);

    $export = $service->export($userId, $presetId);
    $importId = $service->import($userId, json_encode($export, JSON_UNESCAPED_SLASHES));
    assert_true($importId > 0);

    $queue = new QueueService($pdo, new FakeMailgunClient(false));
    $jobId = $queue->createJob($userId, $presetId);
    $queue->pauseJob($userId, $jobId);
    assert_true((bool) $pdo->query("SELECT paused_at FROM sending_jobs WHERE id = $jobId")->fetchColumn());
    $result = $queue->processDue(10);
    assert_same(0, $result['processed']);
    $queue->resumeJob($userId, $jobId);
    $result = $queue->processDue(10);
    assert_same(2, $result['sent']);
});

test('Webhook signatures store delivery events and update queue delivery status', function (): void {
    $pdo = integration_pdo();
    $userId = (new UserService($pdo))->create('Hook User', 'hook@example.com', 'password123', 'user');
    (new MailgunSettingsService($pdo))->save($userId, [
        'api_key' => 'key-test',
        'webhook_signing_key' => 'signing-secret',
        'domain' => '',
        'region' => 'US',
        'test_mode' => '1',
        'daily_limit' => '100',
        'hourly_limit' => '100',
    ]);
    $domainId = (new DomainService($pdo))->save($userId, [
        'domain' => 'mg-hook.example.com',
        'region' => 'US',
        'test_mode' => '1',
        'daily_limit' => '100',
        'hourly_limit' => '100',
        'is_active' => '1',
        'is_default' => '1',
    ]);
    $recipientId = (new RecipientService($pdo))->create($userId, 'Program A', 'program-a@example.com');
    $presetId = (new PresetService($pdo))->save($userId, [
        'name' => 'Hook preset',
        'mailgun_domain_id' => $domainId,
        'language' => 'fr',
        'topic' => 'banking',
        'from_pattern' => 'qa-{random}@mg-hook.example.com',
        'delay_mode' => 'fixed',
        'delay_min_seconds' => '0',
        'delay_max_seconds' => '0',
        'json_payload' => sample_json(),
        'recipient_ids' => [$recipientId],
    ]);
    $queue = new QueueService($pdo, new FakeMailgunClient(false));
    $jobId = $queue->createJob($userId, $presetId);
    $queueId = (int) $pdo->query("SELECT id FROM email_queue WHERE job_id = $jobId ORDER BY id LIMIT 1")->fetchColumn();

    $timestamp = '1770000000';
    $token = 'token-123';
    $signature = hash_hmac('sha256', $timestamp . $token, 'signing-secret');
    $payload = [
        'signature' => [
            'timestamp' => $timestamp,
            'token' => $token,
            'signature' => $signature,
        ],
        'event-data' => [
            'id' => 'event-1',
            'event' => 'delivered',
            'domain' => ['name' => 'mg-hook.example.com'],
            'recipient' => 'program-a@example.com',
            'message' => ['headers' => ['message-id' => 'message-id']],
            'user-variables' => ['queue_id' => (string) $queueId, 'job_id' => (string) $jobId],
        ],
    ];

    $result = (new WebhookService($pdo))->handle(json_encode($payload, JSON_UNESCAPED_SLASHES));
    assert_same('delivered', $result['event_type']);
    assert_same('delivered', (string) $pdo->query("SELECT delivery_status FROM email_queue WHERE id = $queueId")->fetchColumn());
    assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM mailgun_webhook_events WHERE signature_valid = 1')->fetchColumn());
});

$failures = 0;
foreach ($tests as $name => $callback) {
    try {
        $callback();
        echo "[PASS] $name\n";
    } catch (Throwable $throwable) {
        if (str_starts_with($throwable->getMessage(), 'SKIP:')) {
            echo "[SKIP] $name - " . substr($throwable->getMessage(), 6) . "\n";
            continue;
        }
        $failures++;
        echo "[FAIL] $name - " . $throwable->getMessage() . "\n";
    }
}

foreach ($skipped as $name => $reason) {
    echo "[SKIP] $name - $reason\n";
}

if ($failures > 0) {
    exit(1);
}

echo "All runnable tests passed.\n";

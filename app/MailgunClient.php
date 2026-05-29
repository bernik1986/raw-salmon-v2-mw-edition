<?php

declare(strict_types=1);

namespace App;

class MailgunClient
{
    public function send(array $settings, array $message): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP cURL extension is required');
        }

        $endpoint = self::endpoint($settings['region'], $settings['domain']);
        $payload = self::payload($settings, $message);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => 'api:' . $settings['api_key'],
            CURLOPT_TIMEOUT => (int) app_config('mailgun_timeout_seconds', 20),
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($response === false) {
            return [
                'ok' => false,
                'status_code' => 0,
                'message_id' => null,
                'response' => null,
                'error' => $error ?: 'Mailgun request failed',
            ];
        }

        $decoded = json_decode((string) $response, true);
        $messageId = is_array($decoded) ? ($decoded['id'] ?? null) : null;

        return [
            'ok' => $statusCode >= 200 && $statusCode < 300,
            'status_code' => $statusCode,
            'message_id' => is_string($messageId) ? $messageId : null,
            'response' => (string) $response,
            'error' => $statusCode >= 200 && $statusCode < 300 ? null : (string) $response,
        ];
    }

    public function testConnection(string $apiKey, string $region, string $domain): array
    {
        $endpoint = self::apiBase($region) . '/v4/domains/' . rawurlencode($domain);
        return $this->request('GET', $endpoint, $apiKey);
    }

    public function request(string $method, string $endpoint, string $apiKey, array $payload = []): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP cURL extension is required');
        }

        $ch = curl_init($endpoint);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => 'api:' . $apiKey,
            CURLOPT_TIMEOUT => (int) app_config('mailgun_timeout_seconds', 20),
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $payload;
        } elseif ($method !== 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
            if ($payload) {
                $options[CURLOPT_POSTFIELDS] = $payload;
            }
        }
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($response === false) {
            return [
                'ok' => false,
                'status_code' => 0,
                'response' => null,
                'error' => $error ?: 'Mailgun request failed',
            ];
        }

        return [
            'ok' => $statusCode >= 200 && $statusCode < 300,
            'status_code' => $statusCode,
            'response' => (string) $response,
            'error' => $statusCode >= 200 && $statusCode < 300 ? null : (string) $response,
        ];
    }

    public static function endpoint(string $region, string $domain): string
    {
        $base = strtoupper($region) === 'EU'
            ? 'https://api.eu.mailgun.net'
            : 'https://api.mailgun.net';

        return $base . '/v3/' . rawurlencode($domain) . '/messages';
    }

    public static function apiBase(string $region): string
    {
        return strtoupper($region) === 'EU'
            ? 'https://api.eu.mailgun.net'
            : 'https://api.mailgun.net';
    }

    public static function payload(array $settings, array $message): array
    {
        $fromName = trim((string) ($message['from_name'] ?? ''));
        $fromEmail = trim((string) $message['from_email']);
        $from = $fromName !== '' ? sprintf('"%s" <%s>', addcslashes($fromName, '"\\'), $fromEmail) : $fromEmail;

        $payload = [
            'from' => $from,
            'to' => (string) $message['recipient_email'],
            'subject' => (string) ($message['subject'] ?? ''),
            'text' => (string) ($message['body_text'] ?? ''),
        ];

        if (!empty($message['body_html'])) {
            $payload['html'] = (string) $message['body_html'];
        }

        if (!empty($message['id'])) {
            $payload['v:queue_id'] = (string) $message['id'];
        }
        if (!empty($message['job_id'])) {
            $payload['v:job_id'] = (string) $message['job_id'];
        }

        if (!empty($settings['default_reply_to'])) {
            $payload['h:Reply-To'] = (string) $settings['default_reply_to'];
        }

        if (!empty($settings['test_mode'])) {
            $payload['o:testmode'] = 'yes';
        }

        foreach (($message['attachments'] ?? []) as $index => $attachment) {
            if (!empty($attachment['path']) && is_file($attachment['path'])) {
                $payload['attachment[' . $index . ']'] = curl_file_create(
                    $attachment['path'],
                    $attachment['mime_type'] ?: 'application/octet-stream',
                    $attachment['original_name'] ?: basename($attachment['path'])
                );
            }
        }

        return $payload;
    }
}

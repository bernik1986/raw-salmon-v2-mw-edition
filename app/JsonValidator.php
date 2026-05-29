<?php

declare(strict_types=1);

namespace App;

final class JsonValidator
{
    public static function validate(string $json): array
    {
        $errors = [];
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'valid' => false,
                'errors' => ['Invalid JSON: ' . json_last_error_msg()],
                'data' => null,
            ];
        }

        if (!is_array($data) || array_is_list($data)) {
            return [
                'valid' => false,
                'errors' => ['Root JSON value must be an object'],
                'data' => null,
            ];
        }

        foreach (['preset_name', 'language', 'topic'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field]) || trim($data[$field]) === '') {
                $errors[] = "$field must be a non-empty string";
            }
        }

        if (!isset($data['emails']) || !is_array($data['emails']) || !array_is_list($data['emails'])) {
            $errors[] = 'emails must be an array';
        } else {
            if (count($data['emails']) === 0) {
                $errors[] = 'emails must contain at least one email';
            }

            foreach ($data['emails'] as $index => $email) {
                $label = 'emails[' . $index . ']';
                if (!is_array($email) || array_is_list($email)) {
                    $errors[] = "$label must be an object";
                    continue;
                }

                $subject = $email['subject'] ?? null;
                $body = $email['body'] ?? null;
                $subjectIsValid = $subject === null || is_string($subject);
                $bodyIsValid = $body === null || is_string($body);

                if (!$subjectIsValid) {
                    $errors[] = "$label.subject must be string or null";
                }
                if (!$bodyIsValid) {
                    $errors[] = "$label.body must be string or null";
                }
                if ($subjectIsValid && $bodyIsValid && $subject === null && $body === null) {
                    $errors[] = "$label cannot have subject and body both null";
                }

                if (!isset($email['tags']) || !is_array($email['tags']) || !array_is_list($email['tags'])) {
                    $errors[] = "$label.tags must be an array";
                } else {
                    foreach ($email['tags'] as $tagIndex => $tag) {
                        if (!is_string($tag)) {
                            $errors[] = "$label.tags[$tagIndex] must be a string";
                        }
                    }
                }
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'data' => $errors === [] ? $data : null,
        ];
    }
}

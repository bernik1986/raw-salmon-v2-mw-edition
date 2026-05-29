<?php

declare(strict_types=1);

namespace App;

final class FromGenerator
{
    public static function generate(string $pattern, ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable();
        $random = (string) random_int(10000, 999999);
        $email = str_replace(
            ['{random}', '{date}'],
            [$random, $now->format('Ymd')],
            trim($pattern)
        );

        if (!Security::safeEmail($email)) {
            throw new \InvalidArgumentException('From pattern must generate a valid email address');
        }

        return strtolower($email);
    }

    public static function preview(string $pattern): string
    {
        $email = str_replace(
            ['{random}', '{date}'],
            ['583920', date('Ymd')],
            trim($pattern)
        );

        return strtolower($email);
    }
}

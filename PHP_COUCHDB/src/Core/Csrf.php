<?php

declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        if (!isset($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }

    public static function isValid(mixed $candidate): bool
    {
        return is_string($candidate)
            && isset($_SESSION['_csrf_token'])
            && is_string($_SESSION['_csrf_token'])
            && hash_equals($_SESSION['_csrf_token'], $candidate);
    }
}

<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class View
{
    /** @param array<string, mixed> $data */
    public static function render(string $template, array $data = []): string
    {
        $path = dirname(__DIR__, 2) . '/templates/' . $template . '.php';
        if (!is_file($path)) {
            throw new RuntimeException('View template not found: ' . $template);
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $path;
        return (string) ob_get_clean();
    }
}

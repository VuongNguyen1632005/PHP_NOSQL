<?php

declare(strict_types=1);

namespace App\Core;

final readonly class Response
{
    public function __construct(
        public string $body,
        public int $status = 200,
        public string $contentType = 'text/html; charset=utf-8',
        public array $headers = [],
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Core;

use Closure;

final readonly class StreamedResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public Closure $stream,
        public int $status = 200,
        public string $contentType = 'text/event-stream; charset=utf-8',
        public array $headers = [],
    ) {
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: ' . $this->contentType);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-cache, no-store, private');
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        try {
            ($this->stream)();
        } catch (\Throwable $exception) {
            error_log('SSE stream failed: ' . $exception->getMessage());
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\CouchDB;

use JsonException;
use RuntimeException;

final readonly class CouchDbResponse
{
    public function __construct(
        public int $statusCode,
        public string $body,
    ) {
    }

    /** @return array<string, mixed>|list<mixed> */
    public function json(): array
    {
        try {
            $value = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('CouchDB returned invalid JSON.', previous: $exception);
        }

        if (!is_array($value)) {
            throw new RuntimeException('CouchDB returned a JSON value where an object or list was expected.');
        }

        return $value;
    }
}

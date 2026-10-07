<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Core\Response;

final class ProductMediaController
{
    public function __construct(private readonly ProductImageStorage $storage)
    {
    }

    /** @param array<string, string> $params */
    public function show(array $params): Response
    {
        $image = $this->storage->read($params['file'] ?? '');
        if ($image === null) return new Response('', 404, 'text/plain; charset=utf-8');
        return new Response($image['body'], 200, $image['mime'], [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}

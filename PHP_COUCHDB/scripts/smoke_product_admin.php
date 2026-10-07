<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Catalog\ProductAdminService;
use App\Catalog\ProductImageStorage;
use App\Catalog\ProductMediaController;
use App\Infrastructure\CouchDB\CouchDbClient;

$source = getenv('COUCHDB_DATABASE') ?: '';
if (!str_ends_with($source, '_test')) {
    fwrite(STDERR, "Refusing to run product admin smoke test unless COUCHDB_DATABASE ends in _test.\n");
    exit(2);
}
$client = new CouchDbClient(getenv('COUCHDB_URL') ?: '', getenv('COUCHDB_USER') ?: '', getenv('COUCHDB_PASSWORD') ?: '');
$database = 'retail_product_admin_smoke_' . bin2hex(random_bytes(5));
$root = rawurlencode($database);
$created = false;

try {
    $response = $client->request('PUT', $root);
    if ($response->statusCode < 200 || $response->statusCode >= 300) throw new RuntimeException('Could not create isolated product smoke database.');
    $created = true;
    $design = $client->request('GET', rawurlencode($source) . '/_design/domain_validation')->json();
    unset($design['_rev']);
    $response = $client->request('PUT', $root . '/_design/domain_validation', $design);
    if ($response->statusCode < 200 || $response->statusCode >= 300) throw new RuntimeException('Could not copy validation design document.');
    foreach ([
        ['active_products', ['type', 'active'], ['type' => 'product', 'active' => true]],
        ['admin_products', ['type'], ['type' => 'product']],
    ] as [$name, $fields, $partial]) {
        $response = $client->request('POST', $root . '/_index', [
            'index' => ['fields' => $fields, 'partial_filter_selector' => $partial], 'ddoc' => '_design/catalog_indexes', 'name' => $name, 'type' => 'json',
        ]);
        if ($response->statusCode < 200 || $response->statusCode >= 300) throw new RuntimeException('Could not create product test index.');
    }
    sleep(2);
    $uploadDirectory = dirname(__DIR__) . '/var/uploads';
    $imageStorage = new ProductImageStorage($uploadDirectory);
    $service = new ProductAdminService($client, $database, $imageStorage);
    $createdProduct = $service->save(null, [
        'name' => 'Smoke Product', 'description' => 'Initial', 'category_code' => 'A',
        'variants_json' => json_encode([['size' => 'S', 'price' => 125000, 'stock' => 4, 'active' => true]], JSON_THROW_ON_ERROR),
    ], 'manager-smoke');
    $legacyId = (string) $createdProduct['legacy_id'];
    $initialVariantId = (string) $createdProduct['variants'][0]['variant_id'];
    if (($createdProduct['active'] ?? false) !== true || count($createdProduct['images'] ?? []) !== 1) throw new RuntimeException('New product defaults were not applied.');

    $updated = $service->save($legacyId, [
        'name' => 'Smoke Product Updated', 'description' => 'Updated', 'category_code' => 'Q',
        'variants_json' => json_encode([
            ['variant_id' => $initialVariantId, 'size' => 'S', 'price' => 150000, 'stock' => 7, 'active' => true],
            ['size' => 'M', 'price' => 150000, 'stock' => 2, 'active' => false],
        ], JSON_THROW_ON_ERROR),
    ], 'manager-smoke');
    if ($updated['variants'][0]['variant_id'] !== $initialVariantId || (int) $updated['variants'][0]['stock'] !== 7
        || count($updated['variants']) !== 2 || $updated['images'] !== $createdProduct['images']) {
        throw new RuntimeException('Product update did not preserve variant IDs/images or apply the edits.');
    }

    try {
        $service->save($legacyId, [
            'name' => 'Invalid', 'category_code' => 'A',
            'variants_json' => json_encode([['variant_id' => $initialVariantId, 'size' => 'M', 'price' => 0, 'stock' => 1, 'active' => true]], JSON_THROW_ON_ERROR),
        ], 'manager-smoke');
        throw new RuntimeException('Product service accepted a changed historical variant size.');
    } catch (RuntimeException $exception) {
        if (!str_contains($exception->getMessage(), 'Không được đổi size')) throw $exception;
    }

    $hidden = $service->softDelete($legacyId, 'manager-smoke');
    if (($hidden['active'] ?? true) !== false || ($service->get($legacyId)['active'] ?? true) !== false) throw new RuntimeException('Product soft delete failed.');
    $restored = $service->restore($legacyId, 'manager-smoke');
    if (($restored['active'] ?? false) !== true || count($service->allProducts()) !== 1) throw new RuntimeException('Product restore/admin listing failed.');
    $publicQuery = $client->request('POST', $root . '/_find', [
        'selector' => ['type' => 'product', 'active' => true],
        'use_index' => ['_design/catalog_indexes', 'active_products'], 'limit' => 2000,
        'fields' => ['_id', 'type', 'legacy_id', 'name', 'description', 'category', 'variants', 'images', 'active', 'product_id', 'reviewer_name', 'rating', 'content'],
    ]);
    if ($publicQuery->statusCode < 200 || $publicQuery->statusCode >= 300) throw new RuntimeException('Public product query failed: ' . $publicQuery->body);
    $public = $publicQuery->json()['docs'] ?? [];
    if (count($public) !== 1 || ($public[0]['legacy_id'] ?? '') !== $legacyId) throw new RuntimeException('Restored product did not return to the public catalog.');

    $mediaFilename = bin2hex(random_bytes(16)) . '.png';
    $mediaPath = $uploadDirectory . DIRECTORY_SEPARATOR . $mediaFilename;
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true) && !is_dir($uploadDirectory)) throw new RuntimeException('Could not prepare media smoke directory.');
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jv9sAAAAASUVORK5CYII=', true);
    if (!is_string($png) || file_put_contents($mediaPath, $png) !== strlen($png)) throw new RuntimeException('Could not create media smoke image.');
    try {
        $media = (new ProductMediaController($imageStorage))->show(['file' => $mediaFilename]);
        $invalidMedia = (new ProductMediaController($imageStorage))->show(['file' => '../' . $mediaFilename]);
        if ($media->status !== 200 || $media->contentType !== 'image/png' || ($media->headers['X-Content-Type-Options'] ?? '') !== 'nosniff'
            || $media->body !== $png || $invalidMedia->status !== 404) throw new RuntimeException('Media endpoint validation or response headers failed.');
    } finally {
        if (is_file($mediaPath)) unlink($mediaPath);
    }

    fwrite(STDOUT, "PASS: manager product create/edit, stable variant references, validation, soft delete/restore and public catalog filtering.\n");
} finally {
    if ($created) $client->request('DELETE', $root);
}

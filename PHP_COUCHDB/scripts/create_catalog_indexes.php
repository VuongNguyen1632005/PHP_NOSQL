<?php

declare(strict_types=1);

use App\Infrastructure\CouchDB\CouchDbClient;

require dirname(__DIR__) . '/vendor/autoload.php';

function envValue(string $key): string
{
    $value = getenv($key);
    if (!is_string($value) || trim($value) === '') {
        fwrite(STDERR, "Missing required environment variable: {$key}\n");
        exit(1);
    }
    return $value;
}

$client = new CouchDbClient(envValue('COUCHDB_URL'), envValue('COUCHDB_USER'), envValue('COUCHDB_PASSWORD'));
$database = rawurlencode(envValue('COUCHDB_DATABASE'));
$catalogDesign = json_decode((string) file_get_contents(dirname(__DIR__) . '/database/couchdb/indexes/catalog_indexes.json'), true, 512, JSON_THROW_ON_ERROR);
$indexes = [
    ['name' => 'active_products', 'fields' => ['type', 'active'], 'partial' => ['type' => 'product', 'active' => true]],
    ['name' => 'admin_products', 'fields' => ['type'], 'partial' => ['type' => 'product']],
    ['name' => 'active_reviews', 'fields' => ['type', 'active'], 'partial' => ['type' => 'review', 'active' => true]],
    ['name' => 'admin_reviews', 'fields' => ['type'], 'partial' => ['type' => 'review']],
    ['name' => 'account_usernames', 'fields' => ['type', 'auth.username'], 'partial' => ['type' => ['$in' => ['customer', 'staff']]]],
    ['name' => 'customer_orders', 'fields' => ['type', 'customer.customer_id', 'ordered_at'], 'partial' => ['type' => 'order']],
    ['name' => 'admin_orders', 'fields' => ['type', 'status', 'ordered_at'], 'partial' => ['type' => 'order']],
    ['name' => 'admin_orders_by_date', 'fields' => ['type', 'ordered_at'], 'partial' => ['type' => 'order']],
    ['name' => 'delivery_tracking_code', 'fields' => ['type', 'delivery_tracking.tracking_code'], 'partial' => ['type' => 'order']],
];
$indexesResponse = $client->request('GET', $database . '/_index');
if ($indexesResponse->statusCode < 200 || $indexesResponse->statusCode >= 300) {
    fwrite(STDERR, 'Could not inspect existing CouchDB indexes.' . PHP_EOL);
    exit(1);
}
$existingIndexes = $indexesResponse->json()['indexes'] ?? [];

foreach ($indexes as $index) {
    $current = null;
    foreach ($existingIndexes as $candidate) {
        if (($candidate['ddoc'] ?? null) === '_design/catalog_indexes' && ($candidate['name'] ?? null) === $index['name']) {
            $current = $candidate;
            break;
        }
    }
    $expectedView = $catalogDesign['views'][$index['name']]['map']['partial_filter_selector'] ?? null;
    $currentFields = array_map(static fn (array $field): string => (string) array_key_first($field), $current['def']['fields'] ?? []);
    if ($current !== null && $currentFields === $index['fields'] && ($current['def']['partial_filter_selector'] ?? null) === $expectedView) {
        fwrite(STDOUT, 'Catalog index ready: ' . $index['name'] . PHP_EOL);
        continue;
    }
    if ($current !== null) {
        $delete = $client->request('DELETE', $database . '/_index/' . rawurlencode('_design/catalog_indexes') . '/json/' . rawurlencode($index['name']));
        if ($delete->statusCode !== 200 && $delete->statusCode !== 404) {
            fwrite(STDERR, 'Could not replace index ' . $index['name'] . ' (HTTP ' . $delete->statusCode . ').' . PHP_EOL);
            exit(1);
        }
    }
    $response = $client->request('POST', $database . '/_index', [
        'index' => ['fields' => $index['fields'], 'partial_filter_selector' => $index['partial']],
        'ddoc' => '_design/catalog_indexes',
        'name' => $index['name'],
        'type' => 'json',
    ]);
    if ($response->statusCode < 200 || $response->statusCode >= 300) {
        fwrite(STDERR, 'Could not create index ' . $index['name'] . ' (HTTP ' . $response->statusCode . ').' . PHP_EOL);
        exit(1);
    }
    fwrite(STDOUT, 'Catalog index ready: ' . $index['name'] . PHP_EOL);
}

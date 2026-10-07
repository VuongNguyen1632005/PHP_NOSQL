<?php

declare(strict_types=1);

use App\Infrastructure\CouchDB\CouchDbClient;

require dirname(__DIR__) . '/vendor/autoload.php';

function phase07Fail(string $message): never
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function phase07Request(CouchDbClient $client, string $method, string $path, ?array $body = null): array
{
    $response = $client->request($method, $path, $body);
    if ($response->statusCode < 200 || $response->statusCode >= 300) {
        phase07Fail($method . ' returned HTTP ' . $response->statusCode . '.');
    }

    return $response->body === '' ? [] : $response->json();
}

$database = 'phase07_nosql_fixture_test';
if ($database === (string) getenv('COUCHDB_DATABASE') || $database === (string) getenv('PHASE06_DEMO_DATABASE')) {
    phase07Fail('fixture database must differ from configured and Phase 06 demo databases.');
}
foreach (['COUCHDB_URL', 'COUCHDB_USER', 'COUCHDB_PASSWORD'] as $name) {
    if (trim((string) getenv($name)) === '') phase07Fail('missing CouchDB configuration.');
}

$client = new CouchDbClient((string) getenv('COUCHDB_URL'), (string) getenv('COUCHDB_USER'), (string) getenv('COUCHDB_PASSWORD'));
$databasePath = rawurlencode($database);
$existing = $client->request('GET', $databasePath);
if ($existing->statusCode !== 404) phase07Fail('fixture target already exists or could not be safely checked; no change made.');
phase07Request($client, 'PUT', $databasePath);

$validator = json_decode((string) file_get_contents(dirname(__DIR__) . '/database/couchdb/design-docs/domain_validation.json'), true, 512, JSON_THROW_ON_ERROR);
phase07Request($client, 'PUT', $databasePath . '/_design%2Fdomain_validation', $validator);

$fixtureId = 'product:phase07-rev-fixture';
$fixturePath = $databasePath . '/' . rawurlencode($fixtureId);
$doc = [
    '_id' => $fixtureId,
    'type' => 'product',
    'schema_version' => 2,
    'legacy_id' => 'PHASE07-REV-1',
    'name' => 'Phase 07 Revision Fixture',
    'category' => 'Fixture',
    'variants' => [[
        'variant_id' => 'PHASE07-REV-1-M',
        'size' => 'M',
        'price' => 10000,
        'stock' => 1,
        'active' => true,
    ]],
    'images' => [],
    'active' => true,
    'meta' => ['phase07_fixture' => true],
];
$created = phase07Request($client, 'PUT', $fixturePath, $doc);
$oldRevision = (string) ($created['rev'] ?? '');
if (!str_starts_with($oldRevision, '1-')) phase07Fail('fixture did not receive its initial revision.');

$updated = $doc;
$updated['_rev'] = $oldRevision;
$updated['description'] = 'Updated with the current revision.';
$saved = phase07Request($client, 'PUT', $fixturePath, $updated);
$newRevision = (string) ($saved['rev'] ?? '');
if (!str_starts_with($newRevision, '2-') || $newRevision === $oldRevision) phase07Fail('valid update did not advance _rev.');

$stale = $doc;
$stale['_rev'] = $oldRevision;
$stale['description'] = 'This stale update must be rejected.';
$conflict = $client->request('PUT', $fixturePath, $stale);
if ($conflict->statusCode !== 409) phase07Fail('stale revision expected HTTP 409; got ' . $conflict->statusCode . '.');

$invalidOrder = [
    '_id' => 'order:phase07-invalid-status',
    'type' => 'order',
    'schema_version' => 2,
    'status' => 'invalid_status',
    'receiver' => ['name' => 'Fixture', 'phone' => '0000000000', 'address' => 'Fixture address'],
    'items' => [['product_id' => 'product:phase07-rev-fixture', 'variant_id' => 'PHASE07-REV-1-M', 'quantity' => 1]],
    'totals' => ['total_quantity' => 1, 'subtotal' => 0, 'shipping_fee' => 0, 'discount_amount' => 0, 'grand_total' => 0],
    'status_history' => [],
    'meta' => ['phase07_fixture' => true],
];
$rejected = $client->request('PUT', $databasePath . '/' . rawurlencode((string) $invalidOrder['_id']), $invalidOrder);
if ($rejected->statusCode !== 403) phase07Fail('invalid order status expected validator HTTP 403; got ' . $rejected->statusCode . '.');

$verified = phase07Request($client, 'GET', $fixturePath);
if (($verified['_rev'] ?? null) !== $newRevision || ($verified['description'] ?? null) !== $updated['description']) {
    phase07Fail('fixture revision verification failed.');
}

$inventory = phase07Request($client, 'GET', $databasePath . '/_all_docs');
$ids = array_column($inventory['rows'] ?? [], 'id');
sort($ids, SORT_STRING);
$expectedIds = ['_design/domain_validation', $fixtureId];
sort($expectedIds, SORT_STRING);
if ($ids !== $expectedIds) phase07Fail('fixture cleanup guard found an unexpected document; database left for inspection.');

$deleted = $client->request('DELETE', $databasePath);
if (!in_array($deleted->statusCode, [200, 202], true)) phase07Fail('guarded fixture database cleanup failed.');
if ($client->request('GET', $databasePath)->statusCode !== 404) phase07Fail('fixture cleanup could not be verified.');

fwrite(STDOUT, 'PASS: isolated fixture _rev generation 1→2; stale write HTTP 409; invalid order status rejected HTTP 403; fixture database cleaned up.' . PHP_EOL);

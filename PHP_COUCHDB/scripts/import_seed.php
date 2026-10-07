<?php

declare(strict_types=1);

use App\Infrastructure\CouchDB\CouchDbClient;
use App\Infrastructure\CouchDB\CouchDbResponse;
require dirname(__DIR__) . '/vendor/autoload.php';

const MAX_DOCS_PER_BATCH = 40;
const MAX_DATABASE_ROWS = 10000;

function fail(string $message, int $status = 1): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($status);
}

function requiredEnvironment(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || trim($value) === '') {
        fail("Missing required environment variable: {$name}");
    }

    return $value;
}

function responseJson(CouchDbResponse $response, string $operation): array
{
    try {
        return $response->json();
    } catch (RuntimeException $exception) {
        fail($operation . ' returned an invalid response.');
    }
}

/** @param array<string, mixed>|list<mixed> $value */
function canonicalize(array $value): array
{
    if (!array_is_list($value)) {
        unset($value['_rev'], $value['_revisions'], $value['_conflicts']);
        ksort($value, SORT_STRING);
    }

    foreach ($value as $key => $item) {
        if (is_array($item)) {
            $value[$key] = canonicalize($item);
        }
    }

    return $value;
}

/** @param list<array<string, mixed>> $documents */
function checkBulkResponse(CouchDbResponse $response, array $documents): void
{
    if ($response->statusCode < 200 || $response->statusCode >= 300) {
        $body = json_decode($response->body, true);
        $reason = is_array($body) ? ($body['reason'] ?? $body['error'] ?? 'request rejected') : 'request rejected';
        fail('CouchDB bulk request failed (HTTP ' . $response->statusCode . '): ' . $reason);
    }

    try {
        $results = $response->json();
    } catch (RuntimeException $exception) {
        fail('CouchDB bulk response was invalid JSON.');
    }

    if (count($results) !== count($documents)) {
        fail('CouchDB returned a different number of results than submitted documents.');
    }

    $errors = [];
    foreach ($results as $result) {
        if (!is_array($result) || ($result['ok'] ?? false) !== true) {
            $id = is_array($result) ? (string) ($result['id'] ?? 'unknown document') : 'unknown document';
            $error = is_array($result) ? (string) ($result['error'] ?? 'unknown error') : 'invalid result';
            $reason = is_array($result) ? (string) ($result['reason'] ?? '') : '';
            $errors[] = $id . ' (' . $error . ($reason !== '' ? ': ' . $reason : '') . ')';
        }
    }

    if ($errors !== []) {
        fail("Some documents were not written. Successful bulk rows remain in the test database; rerun the importer to resume safely.\n- " . implode("\n- ", $errors));
    }
}

/**
 * @param array<string, mixed> $expected
 * @param array<string, mixed> $infrastructure
 * @return array<string, array<string, mixed>>
 */
function readDatabaseDocuments(CouchDbClient $client, string $database, array $expected, array $infrastructure): array
{
    $path = rawurlencode($database) . '/_all_docs?include_docs=true&limit=' . MAX_DATABASE_ROWS;
    $response = $client->request('GET', $path);
    if ($response->statusCode !== 200) {
        fail('Could not read the test database documents (HTTP ' . $response->statusCode . ').');
    }

    $payload = responseJson($response, 'Reading the test database');
    $totalRows = $payload['total_rows'] ?? null;
    $rows = $payload['rows'] ?? null;
    if (!is_int($totalRows) || !is_array($rows) || $totalRows > MAX_DATABASE_ROWS || count($rows) !== $totalRows) {
        fail('The test database is too large or could not be read completely; no seed documents were written.');
    }

    $current = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !is_string($row['id'] ?? null) || !is_array($row['doc'] ?? null)) {
            fail('CouchDB returned a row without a complete document; no seed documents were written.');
        }
        $current[$row['id']] = $row['doc'];
    }

    $knownDocuments = $expected + $infrastructure;
    $unexpected = array_diff(array_keys($current), array_keys($knownDocuments));
    if ($unexpected !== []) {
        fail('The *_test database contains documents outside this seed; no seed documents were written. Examples: ' . implode(', ', array_slice($unexpected, 0, 5)));
    }

    foreach ($current as $id => $document) {
        if (canonicalize($document) !== canonicalize($knownDocuments[$id])) {
            fail("Existing document {$id} differs from the fixture; no seed documents were written.");
        }
    }

    return array_intersect_key($current, $expected);
}

function main(array $arguments): int
{
    $verifyOnly = in_array('--verify-only', $arguments, true);
    $unknownOptions = array_values(array_filter(
        $arguments,
        static fn (string $argument): bool => $argument !== '--verify-only',
    ));
    if ($unknownOptions !== []) {
        fail('Usage: php scripts/import_seed.php [--verify-only]');
    }

    $database = getenv('COUCHDB_DATABASE') ?: 'retail_order_delivery_test';
    if (!str_ends_with($database, '_test')) {
        fail('For safety, this script only uses database names ending in _test.');
    }

    $seedPath = dirname(__DIR__) . '/database/couchdb/seeds/retail_order_delivery.json';
    if (!is_file($seedPath)) {
        fail('Seed file not found: ' . $seedPath);
    }

    try {
        $seed = json_decode((string) file_get_contents($seedPath), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        fail('The seed file does not contain valid JSON.');
    }

    if (!is_array($seed) || !is_array($seed['docs'] ?? null) || $seed['docs'] === []) {
        fail('The seed file must contain a non-empty docs array.');
    }

    $expected = [];
    foreach ($seed['docs'] as $document) {
        if (!is_array($document) || !is_string($document['_id'] ?? null) || $document['_id'] === '') {
            fail('Every seed document must have a non-empty _id.');
        }
        if (isset($expected[$document['_id']])) {
            fail('Duplicate document ID in seed: ' . $document['_id']);
        }
        $expected[$document['_id']] = $document;
    }

    $indexPath = dirname(__DIR__) . '/database/couchdb/indexes/catalog_indexes.json';
    try {
        $indexDocument = json_decode((string) file_get_contents($indexPath), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        fail('The catalog index design document is invalid JSON.');
    }
    if (!is_array($indexDocument) || ($indexDocument['_id'] ?? null) !== '_design/catalog_indexes') {
        fail('The catalog index design document has an invalid ID.');
    }
    $infrastructure = [$indexDocument['_id'] => $indexDocument];

    $designDocuments = array_filter(
        $expected,
        static fn (array $document): bool => str_starts_with($document['_id'], '_design/'),
    );
    if (count($designDocuments) !== 1) {
        fail('The fixture must contain exactly one validation design document.');
    }
    $designDocument = array_values($designDocuments)[0];
    $client = new CouchDbClient(
        requiredEnvironment('COUCHDB_URL'),
        requiredEnvironment('COUCHDB_USER'),
        requiredEnvironment('COUCHDB_PASSWORD'),
        requestTimeoutSeconds: 45,
    );

    $deadline = time() + min(180, max(0, (int) (getenv('COUCHDB_WAIT_SECONDS') ?: 60)));
    $serverReady = false;
    while (true) {
        try {
            $rootResponse = $client->request('GET', '/');
            if ($rootResponse->statusCode === 200) {
                $serverReady = true;
                break;
            }
            fail('CouchDB rejected the configured credentials (HTTP ' . $rootResponse->statusCode . ').');
        } catch (RuntimeException $exception) {
            if (time() >= $deadline) {
                fail('Could not connect to CouchDB. Check Docker Compose status and COUCHDB_URL.');
            }
            sleep(1);
        }
    }
    if (!$serverReady) {
        fail('CouchDB did not become ready.');
    }

    $databasePath = rawurlencode($database);
    $databaseResponse = $client->request('GET', $databasePath);
    if ($databaseResponse->statusCode === 404) {
        if ($verifyOnly) {
            fail("Database {$database} does not exist; verify-only does not create it.");
        }
        $createResponse = $client->request('PUT', $databasePath);
        if ($createResponse->statusCode !== 201 && $createResponse->statusCode !== 202) {
            fail('Could not create the test database (HTTP ' . $createResponse->statusCode . ').');
        }
        fwrite(STDOUT, "Created empty test database {$database}.\n");
    } elseif ($databaseResponse->statusCode !== 200) {
        fail('Could not inspect the test database (HTTP ' . $databaseResponse->statusCode . ').');
    }

    $current = readDatabaseDocuments($client, $database, $expected, $infrastructure);
    $missing = array_diff_key($expected, $current);
    if ($verifyOnly) {
        if ($missing !== []) {
            fail('Seed verification failed; missing ' . count($missing) . ' of ' . count($expected) . ' expected documents.');
        }
        fwrite(STDOUT, 'Verified ' . count($current) . ' fixture documents in ' . $database . "; no changes made.\n");
        return 0;
    }

    if (isset($missing[$designDocument['_id']])) {
        fwrite(STDOUT, "Installing the validation design document before business data.\n");
        $designResponse = $client->request('POST', $databasePath . '/_bulk_docs', ['docs' => [$designDocument]]);
        checkBulkResponse($designResponse, [$designDocument]);
        unset($missing[$designDocument['_id']]);
    }

    $pending = array_values($missing);
    $imported = 0;
    foreach (array_chunk($pending, MAX_DOCS_PER_BATCH) as $batch) {
        $response = $client->request('POST', $databasePath . '/_bulk_docs', ['docs' => $batch]);
        checkBulkResponse($response, $batch);
        $imported += count($batch);
        fwrite(STDOUT, 'Imported ' . $imported . ' of ' . count($pending) . " missing documents.\n");
    }

    $afterImport = readDatabaseDocuments($client, $database, $expected, $infrastructure);
    if (count($afterImport) !== count($expected)) {
        fail('Import stopped because the database does not contain the complete expected fixture.');
    }

    fwrite(STDOUT, 'Verified all ' . count($afterImport) . ' fixture documents in ' . $database . ".\n");
    return 0;
}

try {
    exit(main(array_slice($argv, 1)));
} catch (Throwable $exception) {
    fail('Seed import stopped safely: ' . $exception->getMessage());
}

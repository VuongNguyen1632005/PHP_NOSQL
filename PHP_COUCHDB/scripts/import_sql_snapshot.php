<?php

declare(strict_types=1);

use App\Infrastructure\CouchDB\CouchDbClient;
use App\Infrastructure\CouchDB\CouchDbResponse;

require dirname(__DIR__) . '/vendor/autoload.php';

const SNAPSHOT_IMPORT_BATCH_SIZE = 40;
const SNAPSHOT_IMPORT_MAX_ROWS = 10000;

function snapshotImportFail(string $message, int $status = 1): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($status);
}

function snapshotImportEnv(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || trim($value) === '') {
        snapshotImportFail("Missing required environment variable: {$name}");
    }
    return $value;
}

function snapshotImportJson(CouchDbResponse $response, string $operation): array
{
    try {
        $decoded = $response->json();
    } catch (Throwable $exception) {
        snapshotImportFail($operation . ' returned invalid JSON.');
    }
    if (!is_array($decoded)) snapshotImportFail($operation . ' returned an unexpected response.');
    return $decoded;
}

/** @param array<string, mixed>|list<mixed> $value */
function snapshotImportCanonicalize(array $value): array
{
    if (!array_is_list($value)) {
        unset($value['_rev'], $value['_revisions'], $value['_conflicts']);
        ksort($value, SORT_STRING);
    }
    foreach ($value as $key => $item) {
        if (is_array($item)) $value[$key] = snapshotImportCanonicalize($item);
    }
    return $value;
}

/** @param list<array<string, mixed>> $documents */
function snapshotImportCheckBulk(CouchDbResponse $response, array $documents): void
{
    if ($response->statusCode < 200 || $response->statusCode >= 300) {
        snapshotImportFail('CouchDB bulk write failed (HTTP ' . $response->statusCode . ').');
    }
    $results = snapshotImportJson($response, 'CouchDB bulk write');
    if (count($results) !== count($documents)) snapshotImportFail('CouchDB bulk response count did not match submitted documents.');
    $errors = [];
    foreach ($results as $result) {
        if (!is_array($result) || ($result['ok'] ?? false) !== true) {
            $errors[] = (string) ($result['id'] ?? 'unknown') . ' (' . (string) ($result['error'] ?? 'unknown error') . ')';
        }
    }
    if ($errors !== []) {
        snapshotImportFail("Some writes failed; successful rows remain and an identical rerun can resume safely.\n- " . implode("\n- ", $errors));
    }
}

/** @param array<string, array<string, mixed>> $expected @return array<string, array<string, mixed>> */
function snapshotImportReadCurrent(CouchDbClient $client, string $database, array $expected): array
{
    $path = rawurlencode($database) . '/_all_docs?include_docs=true&limit=' . SNAPSHOT_IMPORT_MAX_ROWS;
    $response = $client->request('GET', $path);
    if ($response->statusCode !== 200) snapshotImportFail('Could not inspect migration test database (HTTP ' . $response->statusCode . ').');
    $body = snapshotImportJson($response, 'Reading migration test database');
    $rows = $body['rows'] ?? null;
    $total = $body['total_rows'] ?? null;
    if (!is_array($rows) || !is_int($total) || $total > SNAPSHOT_IMPORT_MAX_ROWS || count($rows) !== $total) {
        snapshotImportFail('Migration test database exceeds the safe verification limit or was read incompletely.');
    }
    $current = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !is_string($row['id'] ?? null) || !is_array($row['doc'] ?? null)) {
            snapshotImportFail('CouchDB returned an incomplete document row.');
        }
        $current[$row['id']] = $row['doc'];
    }
    $unexpected = array_diff(array_keys($current), array_keys($expected));
    if ($unexpected !== []) {
        snapshotImportFail('Target contains documents outside this snapshot; no new documents were submitted. Examples: ' . implode(', ', array_slice($unexpected, 0, 5)));
    }
    foreach ($current as $id => $document) {
        $actualCanonical = snapshotImportCanonicalize($document);
        $expectedCanonical = snapshotImportCanonicalize($expected[$id]);
        if ($actualCanonical !== $expectedCanonical) {
            $fieldNames = array_unique(array_merge(array_keys($actualCanonical), array_keys($expectedCanonical)));
            $differentFields = array_values(array_filter(
                $fieldNames,
                static fn (string $field): bool => ($actualCanonical[$field] ?? null) !== ($expectedCanonical[$field] ?? null),
            ));
            snapshotImportFail("Existing document {$id} differs from the migration artifact in fields: " . implode(', ', $differentFields) . '; no further documents were submitted.');
        }
    }
    return $current;
}

function snapshotImportMain(array $arguments): int
{
    if (count($arguments) !== 1 || str_starts_with($arguments[0], '-')) {
        snapshotImportFail('Usage: php scripts/import_sql_snapshot.php <schema-v2-artifact.json>', 2);
    }
    $database = getenv('COUCHDB_DATABASE') ?: '';
    if ($database === '' || !str_ends_with($database, '_test')) {
        snapshotImportFail('For safety, snapshot import only accepts COUCHDB_DATABASE names ending in _test.', 2);
    }
    $artifactPath = $arguments[0];
    if (!is_file($artifactPath)) snapshotImportFail('Migration artifact file does not exist.');
    try {
        $artifact = json_decode((string) file_get_contents($artifactPath), true, 512, JSON_THROW_ON_ERROR);
        $index = json_decode((string) file_get_contents(dirname(__DIR__) . '/database/couchdb/indexes/catalog_indexes.json'), true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        snapshotImportFail('Could not read valid JSON from the artifact or checked-in index design document.');
    }
    if (!is_array($artifact) || ($artifact['format_version'] ?? null) !== 2 || !is_array($artifact['docs'] ?? null) || $artifact['docs'] === []) {
        snapshotImportFail('Artifact must contain format_version 2 and a non-empty docs array.');
    }
    if (!is_array($index) || ($index['_id'] ?? null) !== '_design/catalog_indexes') {
        snapshotImportFail('Checked-in catalog index design document is invalid.');
    }

    $expected = [];
    foreach (array_merge($artifact['docs'], [$index]) as $document) {
        if (!is_array($document) || !is_string($document['_id'] ?? null) || $document['_id'] === '') {
            snapshotImportFail('Every artifact document must have a non-empty _id.');
        }
        if (isset($expected[$document['_id']])) snapshotImportFail('Artifact contains a duplicate document ID.');
        $expected[$document['_id']] = $document;
    }
    if (!isset($expected['_design/domain_validation'])) snapshotImportFail('Artifact is missing the domain validation design document.');
    if (count(array_filter(array_keys($expected), static fn (string $id): bool => str_starts_with($id, '_design/'))) !== 2) {
        snapshotImportFail('Artifact must contain exactly the domain validator and catalog index design documents.');
    }

    $client = new CouchDbClient(snapshotImportEnv('COUCHDB_URL'), snapshotImportEnv('COUCHDB_USER'), snapshotImportEnv('COUCHDB_PASSWORD'), requestTimeoutSeconds: 45);
    $root = $client->request('GET', '/');
    if ($root->statusCode !== 200) snapshotImportFail('Could not authenticate to CouchDB (HTTP ' . $root->statusCode . ').');
    $databasePath = rawurlencode($database);
    $databaseResponse = $client->request('GET', $databasePath);
    if ($databaseResponse->statusCode === 404) {
        $create = $client->request('PUT', $databasePath);
        if ($create->statusCode !== 201 && $create->statusCode !== 202) snapshotImportFail('Could not create the migration test database.');
        fwrite(STDOUT, "Created empty test database {$database}.\n");
    } elseif ($databaseResponse->statusCode !== 200) {
        snapshotImportFail('Could not inspect target database (HTTP ' . $databaseResponse->statusCode . ').');
    }

    $current = snapshotImportReadCurrent($client, $database, $expected);
    $missing = array_diff_key($expected, $current);
    $validator = $missing['_design/domain_validation'] ?? null;
    if (is_array($validator)) {
        snapshotImportCheckBulk($client->request('POST', $databasePath . '/_bulk_docs', ['docs' => [$validator]]), [$validator]);
        unset($missing['_design/domain_validation']);
        fwrite(STDOUT, "Installed domain validator before business documents.\n");
    }
    $pending = array_values($missing);
    $written = 0;
    foreach (array_chunk($pending, SNAPSHOT_IMPORT_BATCH_SIZE) as $batch) {
        snapshotImportCheckBulk($client->request('POST', $databasePath . '/_bulk_docs', ['docs' => $batch]), $batch);
        $written += count($batch);
        fwrite(STDOUT, 'Imported ' . $written . ' of ' . count($pending) . " missing documents.\n");
    }
    $verified = snapshotImportReadCurrent($client, $database, $expected);
    if (count($verified) !== count($expected)) snapshotImportFail('Post-import verification found missing migration documents.');
    fwrite(STDOUT, 'Verified ' . count($verified) . ' documents in ' . $database . ".\n");
    return 0;
}

try {
    exit(snapshotImportMain(array_slice($argv, 1)));
} catch (Throwable $exception) {
    snapshotImportFail('Snapshot import stopped safely: ' . $exception->getMessage());
}

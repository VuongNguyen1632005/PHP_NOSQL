<?php

declare(strict_types=1);

use App\Infrastructure\CouchDB\CouchDbClient;
use App\Infrastructure\CouchDB\CouchDbResponse;

require dirname(__DIR__) . '/vendor/autoload.php';

function diagnosticsFail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function diagnosticsJson(CouchDbResponse $response, string $operation): array
{
    if ($response->statusCode < 200 || $response->statusCode >= 300) {
        diagnosticsFail($operation . ' failed with CouchDB HTTP ' . $response->statusCode . '.');
    }

    try {
        return $response->json();
    } catch (RuntimeException) {
        diagnosticsFail($operation . ' returned invalid JSON.');
    }
}

$database = getenv('COUCHDB_DATABASE') ?: '';
if (!preg_match('/^[a-z][a-z0-9_$()+\/-]*_test$/', $database)) {
    diagnosticsFail('Read-only CouchDB diagnostics are restricted to database names ending in _test.');
}

foreach (['COUCHDB_URL', 'COUCHDB_USER', 'COUCHDB_PASSWORD'] as $key) {
    if (trim((string) getenv($key)) === '') diagnosticsFail('Missing required CouchDB configuration: ' . $key . '.');
}

$client = new CouchDbClient(
    (string) getenv('COUCHDB_URL'),
    (string) getenv('COUCHDB_USER'),
    (string) getenv('COUCHDB_PASSWORD'),
);
$databasePath = rawurlencode($database);
$queryDirectory = dirname(__DIR__) . '/database/couchdb/queries';
$files = glob($queryDirectory . '/*.json') ?: [];
sort($files, SORT_STRING);
if ($files === []) diagnosticsFail('No Mango query examples were found.');

foreach ($files as $file) {
    try {
        $example = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        diagnosticsFail('Invalid JSON in query example: ' . basename($file) . '.');
    }

    $name = $example['name'] ?? basename($file, '.json');
    $expectedIndex = $example['expected_index'] ?? null;
    $request = $example['request'] ?? null;
    if (!is_string($name) || !is_string($expectedIndex) || !is_array($request)
        || !is_array($request['selector'] ?? null) || !is_array($request['use_index'] ?? null)) {
        diagnosticsFail('Query example is missing its name, expected index, selector, or use_index: ' . basename($file) . '.');
    }

    $explain = diagnosticsJson(
        $client->request('POST', $databasePath . '/_explain', $request),
        'Explain query ' . $name,
    );
    $selectedIndex = $explain['index']['name'] ?? null;
    if ($selectedIndex !== $expectedIndex) {
        $indexDetails = json_encode($explain['index'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $candidates = json_encode($explain['index_candidates'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $options = json_encode($explain['opts'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        diagnosticsFail('Query ' . $name . ' selected index ' . (is_string($selectedIndex) ? $selectedIndex : 'unknown') . '; expected ' . $expectedIndex . '. Options: ' . ($options ?: 'unavailable') . '. Plan: ' . ($indexDetails ?: 'unavailable') . '. Candidates: ' . ($candidates ?: 'unavailable'));
    }

    $result = diagnosticsJson(
        $client->request('POST', $databasePath . '/_find', $request),
        'Run query ' . $name,
    );
    $documents = $result['docs'] ?? null;
    if (!is_array($documents)) diagnosticsFail('Query ' . $name . ' did not return a docs list.');

    fwrite(STDOUT, sprintf(
        "%s: index=%s, returned=%d document(s) (read-only).\n",
        $name,
        $selectedIndex,
        count($documents),
    ));
}

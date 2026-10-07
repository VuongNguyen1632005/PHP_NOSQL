<?php
declare(strict_types=1);

use App\Infrastructure\CouchDB\CouchDbClient;

require dirname(__DIR__) . '/vendor/autoload.php';

function requiredEnv(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || trim($value) === '') {
        fwrite(STDERR, 'Missing required environment variable: ' . $name . PHP_EOL);
        exit(2);
    }
    return $value;
}

$database = getenv('COUCHDB_DATABASE') ?: '';
if ($database === '' || !str_ends_with($database, '_test')) {
    fwrite(STDERR, 'Refusing to update a CouchDB validator unless COUCHDB_DATABASE ends in _test.' . PHP_EOL);
    exit(2);
}

$path = rawurlencode($database) . '/_design/domain_validation';
$sourcePath = dirname(__DIR__) . '/database/couchdb/design-docs/domain_validation.json';
try {
    $desired = json_decode((string) file_get_contents($sourcePath), true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Could not read the checked-in domain validator JSON.' . PHP_EOL);
    exit(1);
}
if (!is_array($desired) || ($desired['_id'] ?? null) !== '_design/domain_validation') {
    fwrite(STDERR, 'The checked-in validator has an invalid document ID.' . PHP_EOL);
    exit(1);
}

$client = new CouchDbClient(requiredEnv('COUCHDB_URL'), requiredEnv('COUCHDB_USER'), requiredEnv('COUCHDB_PASSWORD'));
$currentResponse = $client->request('GET', $path);
if ($currentResponse->statusCode !== 200) {
    fwrite(STDERR, 'Could not read the current test validator (HTTP ' . $currentResponse->statusCode . ').' . PHP_EOL);
    exit(1);
}
$current = $currentResponse->json();
if (($current['validate_doc_update'] ?? null) === ($desired['validate_doc_update'] ?? null)) {
    fwrite(STDOUT, 'Domain validator already matches the checked-in contract in ' . $database . '.' . PHP_EOL);
    exit(0);
}

if (isset($current['_rev'])) $desired['_rev'] = $current['_rev'];
$updated = $client->request('PUT', $path, $desired);
if ($updated->statusCode !== 201 && $updated->statusCode !== 202) {
    fwrite(STDERR, 'Could not update the test validator (HTTP ' . $updated->statusCode . ').' . PHP_EOL);
    exit(1);
}
$verify = $client->request('GET', $path);
if ($verify->statusCode !== 200 || ($verify->json()['validate_doc_update'] ?? null) !== $desired['validate_doc_update']) {
    fwrite(STDERR, 'Validator update could not be verified.' . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, 'Updated the CouchDB domain validator in ' . $database . '; no business documents were changed.' . PHP_EOL);

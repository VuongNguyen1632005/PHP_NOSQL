<?php
declare(strict_types=1);

use App\Infrastructure\CouchDB\CouchDbClient;
use App\Infrastructure\CouchDB\CouchDbResponse;

require dirname(__DIR__) . '/vendor/autoload.php';

const BACKUP_PAGE_SIZE = 500;

function failBackup(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function couchClient(): CouchDbClient
{
    return new CouchDbClient(
        getenv('COUCHDB_URL') ?: '',
        getenv('COUCHDB_USER') ?: '',
        getenv('COUCHDB_PASSWORD') ?: '',
    );
}

function backupJson(CouchDbResponse $response, string $operation): array
{
    if ($response->statusCode < 200 || $response->statusCode >= 300) {
        failBackup($operation . ' failed with CouchDB HTTP ' . $response->statusCode . '.');
    }
    try {
        return $response->json();
    } catch (RuntimeException $exception) {
        failBackup($operation . ' returned invalid JSON.');
    }
}

function safeDatabaseName(string $database): void
{
    if (!preg_match('/^[a-z][a-z0-9_$()+\/-]*_test$/', $database)) {
        failBackup('Backup and restore are restricted to database names ending in _test.');
    }
}

function backupDatabase(): never
{
    global $argv;
    $database = getenv('COUCHDB_DATABASE') ?: '';
    safeDatabaseName($database);
    $name = $argv[2] ?? ('couchdb-' . gmdate('Ymd-His') . '.json');
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.json$/', $name)) {
        failBackup('Provide a simple .json filename; directory traversal is not allowed.');
    }

    $client = couchClient();
    $documents = [];
    $startKey = null;
    while (true) {
        $query = ['include_docs' => 'true', 'attachments' => 'true', 'limit' => BACKUP_PAGE_SIZE];
        if ($startKey !== null) {
            $query['startkey'] = json_encode($startKey, JSON_THROW_ON_ERROR);
            $query['startkey_docid'] = $startKey;
            $query['skip'] = '1';
        }
        $path = rawurlencode($database) . '/_all_docs?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $page = backupJson($client->request('GET', $path), 'Reading backup page');
        $rows = $page['rows'] ?? null;
        if (!is_array($rows)) failBackup('CouchDB backup page did not contain rows.');
        foreach ($rows as $row) {
            $document = $row['doc'] ?? null;
            if (!is_array($document) || !is_string($document['_id'] ?? null)) {
                failBackup('CouchDB returned an incomplete document; backup was not written.');
            }
            $documents[] = $document;
        }
        if (count($rows) < BACKUP_PAGE_SIZE) break;
        $last = $rows[array_key_last($rows)]['id'] ?? null;
        if (!is_string($last) || $last === $startKey) failBackup('CouchDB backup pagination did not advance.');
        $startKey = $last;
    }

    $directory = dirname(__DIR__) . '/var/backups';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        failBackup('Could not create var/backups.');
    }
    $path = $directory . '/' . $name;
    if (file_exists($path)) failBackup('Backup file already exists; refusing to overwrite it.');
    $payload = [
        'format_version' => 1,
        'source_database' => $database,
        'created_at' => gmdate(DATE_ATOM),
        'document_count' => count($documents),
        'documents' => $documents,
    ];
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) === false) failBackup('Could not write backup file.');
    @chmod($path, 0600);
    fwrite(STDOUT, 'Backup created: var/backups/' . $name . ' (' . count($documents) . " documents). Protect this file as sensitive data.\n");
    exit(0);
}

function restoreDatabase(): never
{
    global $argv;
    $name = $argv[2] ?? '';
    $target = $argv[3] ?? '';
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.json$/', $name)) failBackup('Usage: composer db:restore -- <backup.json> <target_test_database>');
    safeDatabaseName($target);
    $path = dirname(__DIR__) . '/var/backups/' . $name;
    if (!is_file($path) || is_link($path)) failBackup('Backup file was not found in var/backups.');
    try {
        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        failBackup('Backup file contains invalid JSON.');
    }
    $documents = $payload['documents'] ?? null;
    if (($payload['format_version'] ?? null) !== 1 || !is_array($documents) || !array_is_list($documents)
        || ($payload['document_count'] ?? null) !== count($documents) || $documents === []) {
        failBackup('Backup format or document count is invalid.');
    }
    foreach ($documents as $document) {
        if (!is_array($document) || !is_string($document['_id'] ?? null)) failBackup('Backup contains a document without _id.');
    }

    $client = couchClient();
    $databasePath = rawurlencode($target);
    $exists = $client->request('GET', $databasePath);
    if ($exists->statusCode === 200) failBackup('Restore target already exists; refusing to overwrite any database.');
    if ($exists->statusCode !== 404) failBackup('Could not safely check restore target (HTTP ' . $exists->statusCode . ').');
    $created = $client->request('PUT', $databasePath);
    backupJson($created, 'Creating restore target');

    // Design documents must be installed before the business documents so validators run on restored data.
    usort($documents, static fn (array $a, array $b): int => str_starts_with((string) $a['_id'], '_design/') === str_starts_with((string) $b['_id'], '_design/')
        ? strcmp((string) $a['_id'], (string) $b['_id'])
        : (str_starts_with((string) $a['_id'], '_design/') ? -1 : 1));
    foreach (array_chunk($documents, 40) as $batch) {
        foreach ($batch as &$document) unset($document['_rev'], $document['_revisions'], $document['_conflicts']);
        unset($document);
        $response = $client->request('POST', $databasePath . '/_bulk_docs', ['docs' => $batch]);
        $results = backupJson($response, 'Restoring document batch');
        if (count($results) !== count($batch)) failBackup('Restore returned an incomplete batch result; target may be partially restored.');
        foreach ($results as $result) {
            if (($result['ok'] ?? false) !== true) {
                failBackup('Restore had a document error (' . (string) ($result['id'] ?? 'unknown') . '); target may be partially restored.');
            }
        }
    }

    $info = backupJson($client->request('GET', $databasePath), 'Verifying restored database');
    if (($info['doc_count'] ?? null) !== count($documents)) failBackup('Restored document count does not match; target may be partially restored.');
    fwrite(STDOUT, 'Restore verified: ' . $target . ' (' . count($documents) . " documents). Source backup remains unchanged.\n");
    exit(0);
}

$action = $argv[1] ?? '';
if ($action === 'backup') backupDatabase();
if ($action === 'restore') restoreDatabase();
failBackup('Usage: php scripts/database_backup.php backup [filename.json] | restore <backup.json> <target_test_database>');

<?php

declare(strict_types=1);

use App\Infrastructure\CouchDB\CouchDbClient;

require dirname(__DIR__) . '/vendor/autoload.php';

function isolatedSeedFail(string $message, int $code = 1): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
}

$configuredDatabase = (string) (getenv('COUCHDB_DATABASE') ?: '');
if (!preg_match('/^[a-z][a-z0-9_$()+\/-]*_test$/', $configuredDatabase)) {
    isolatedSeedFail('The configured COUCHDB_DATABASE must be a valid name ending in _test.', 2);
}

foreach (['COUCHDB_URL', 'COUCHDB_USER', 'COUCHDB_PASSWORD'] as $name) {
    if (trim((string) getenv($name)) === '') {
        isolatedSeedFail('Missing required CouchDB configuration: ' . $name . '.', 2);
    }
}

try {
    $database = 'phase5_seed_verify_' . bin2hex(random_bytes(8)) . '_test';
} catch (Throwable) {
    isolatedSeedFail('Could not generate a unique isolated database name.');
}

$client = new CouchDbClient(
    (string) getenv('COUCHDB_URL'),
    (string) getenv('COUCHDB_USER'),
    (string) getenv('COUCHDB_PASSWORD'),
    requestTimeoutSeconds: 45,
);
$databasePath = rawurlencode($database);
$create = $client->request('PUT', $databasePath);
if ($create->statusCode !== 201 && $create->statusCode !== 202) {
    isolatedSeedFail('Could not create the isolated seed verification database (HTTP ' . $create->statusCode . ').');
}

$failed = false;
try {
    $environment = getenv();
    if (!is_array($environment)) {
        throw new RuntimeException('Could not read the current process environment.');
    }
    $environment['COUCHDB_DATABASE'] = $database;
    $importer = __DIR__ . '/import_seed.php';

    foreach ([[], ['--verify-only']] as $arguments) {
        $command = array_merge([PHP_BINARY, $importer], $arguments);
        $pipes = [];
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__),
            $environment,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the seed importer.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if (is_string($stdout) && $stdout !== '') fwrite(STDOUT, $stdout);
        if ($status !== 0) {
            $failed = true;
            throw new RuntimeException(trim((string) $stderr) ?: 'The seed importer returned a non-zero exit code.');
        }
    }
} catch (Throwable $exception) {
    $failed = true;
    fwrite(STDERR, 'Isolated seed verification failed: ' . $exception->getMessage() . PHP_EOL);
} finally {
    try {
        $deleted = $client->request('DELETE', $databasePath);
        if ($deleted->statusCode !== 200 && $deleted->statusCode !== 202) {
            fwrite(STDERR, 'Could not delete isolated database ' . $database . ' (HTTP ' . $deleted->statusCode . ').' . PHP_EOL);
            $failed = true;
        }
    } catch (Throwable) {
        fwrite(STDERR, 'Could not connect to CouchDB to delete isolated database ' . $database . '.' . PHP_EOL);
        $failed = true;
    }
}

if ($failed) {
    exit(1);
}

fwrite(STDOUT, 'PASS: isolated seed import and verification completed; temporary database was deleted.' . PHP_EOL);

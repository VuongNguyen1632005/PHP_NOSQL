<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Response;
use App\Core\Router;

function couchFailureSmokeFail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$reservation = @stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if (!is_resource($reservation)) {
    couchFailureSmokeFail('Could not reserve a local port for the CouchDB unavailable test.');
}
$address = stream_socket_get_name($reservation, false);
fclose($reservation);
if (!is_string($address) || !preg_match('/:(\d+)$/', $address, $matches)) {
    couchFailureSmokeFail('Could not determine the reserved local port.');
}

$unavailableUrl = 'http://127.0.0.1:' . $matches[1];
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
$child = <<<'PHP'
<?php
require __AUTOLOAD__;
$router = new App\Core\Router();
$router->get('/unavailable', static function (array $params, array $query): App\Core\Response {
    $client = new App\Infrastructure\CouchDB\CouchDbClient(__URL__, 'fixture-user', 'fixture-secret', 1, 2);
    $client->request('GET', 'isolated_test');
    return new App\Core\Response('Unexpected success.');
});
register_shutdown_function(static function (): void {
    fwrite(STDERR, "__HTTP_STATUS__" . http_response_code());
});
$router->dispatch('GET', '/unavailable');
PHP;
$child = str_replace(
    ['__AUTOLOAD__', '__URL__'],
    [var_export($autoload, true), var_export($unavailableUrl, true)],
    $child,
);
$temporaryScript = tempnam(sys_get_temp_dir(), 'couchdb_failure_smoke_');
if ($temporaryScript === false || file_put_contents($temporaryScript, $child) === false) {
    couchFailureSmokeFail('Could not create a temporary CouchDB failure test script.');
}

try {
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $temporaryScript],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__),
    );
    if (!is_resource($process)) {
        couchFailureSmokeFail('Could not start the CouchDB failure-path test.');
    }
    fclose($pipes[0]);
    $body = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $expectedBody = '<h1>Dịch vụ tạm thời chưa sẵn sàng</h1><p>Vui lòng tải lại sau.</p>';
    if ($exitCode !== 0 || $body !== $expectedBody || !str_contains((string) $stderr, '__HTTP_STATUS__503')) {
        couchFailureSmokeFail('CouchDB connection failure did not return the expected generic HTTP 503 response.');
    }
    if (str_contains((string) $body, 'fixture-secret') || str_contains((string) $body, '127.0.0.1')) {
        couchFailureSmokeFail('CouchDB connection details leaked into the HTTP response.');
    }
} finally {
    @unlink($temporaryScript);
}

fwrite(STDOUT, 'PASS: CouchDB unavailable returns a generic HTTP 503 without exposing connection details.' . PHP_EOL);

<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Auth\AccountRepository;
use App\Infrastructure\CouchDB\CouchDbClient;

function failCustomerProvision(string $message, int $status = 1): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($status);
}

function readCustomerPassword(string $prompt): string
{
    if (!function_exists('stream_isatty') || !stream_isatty(STDIN) || !is_readable('/dev/tty')) {
        failCustomerProvision('Run this command from an interactive terminal so the password can be entered without echo.');
    }
    fwrite(STDOUT, $prompt);
    $output = [];
    $status = 0;
    exec('stty -echo < /dev/tty', $output, $status);
    if ($status !== 0) failCustomerProvision('Could not disable terminal echo; no account was changed.');
    try {
        $value = fgets(STDIN);
    } finally {
        $restoreStatus = 0;
        exec('stty echo < /dev/tty', $output, $restoreStatus);
        fwrite(STDOUT, PHP_EOL);
        if ($restoreStatus !== 0) failCustomerProvision('Could not restore terminal echo. Close this terminal and open a new one.');
    }
    if (!is_string($value)) failCustomerProvision('Could not read the password.');
    return rtrim($value, "\r\n");
}

$database = getenv('COUCHDB_DATABASE') ?: '';
if (!str_ends_with($database, '_test')) {
    failCustomerProvision('Refusing to modify customer accounts unless COUCHDB_DATABASE ends in _test.', 2);
}
if (!isset($argv[1]) || count($argv) !== 2) {
    failCustomerProvision('Usage: php scripts/provision_customer_password.php <customer-email>', 2);
}
$email = strtolower(trim((string) $argv[1]));
if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) failCustomerProvision('A valid customer email is required.', 2);

$password = readCustomerPassword('Temporary password: ');
$confirmation = readCustomerPassword('Confirm temporary password: ');
if (!hash_equals($password, $confirmation)) failCustomerProvision('Passwords do not match; no account was changed.');
if (strlen($password) < 12 || strlen($password) > 128
    || preg_match('/[a-z]/', $password) !== 1
    || preg_match('/[A-Z]/', $password) !== 1
    || preg_match('/\d/', $password) !== 1
    || preg_match('/[^a-zA-Z0-9]/', $password) !== 1) {
    failCustomerProvision('Password must be 12–128 characters and contain lowercase, uppercase, a digit, and a symbol.');
}

try {
    $client = new CouchDbClient(
        getenv('COUCHDB_URL') ?: '',
        getenv('COUCHDB_USER') ?: '',
        getenv('COUCHDB_PASSWORD') ?: '',
    );
    $accounts = new AccountRepository($client, $database);
    $matches = $accounts->byUsername('customer', $email);
    if (count($matches) !== 1) failCustomerProvision('Expected exactly one customer account for that email; no account was changed.');
    $account = $matches[0];
    if (($account['active'] ?? false) !== true) failCustomerProvision('The customer account is inactive; no account was changed.');
    $now = gmdate('c');
    $account['auth']['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    $account['auth']['requires_password_reset'] = true;
    $account['auth']['password_reset_issued_at'] = $now;
    $account['meta']['password_provisioned_at'] = $now;
    $account['meta']['password_provisioned_by'] = 'local_cli';
    $accounts->update($account);
    fwrite(STDOUT, 'Temporary password issued for customer account ' . $email . '. The password was not stored in logs.' . PHP_EOL);
} catch (Throwable $exception) {
    failCustomerProvision('Could not provision the customer account. Check the configured test database and retry.');
} finally {
    if (function_exists('sodium_memzero')) {
        if (isset($password)) sodium_memzero($password);
        if (isset($confirmation)) sodium_memzero($confirmation);
    }
}

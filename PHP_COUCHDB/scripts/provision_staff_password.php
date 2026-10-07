<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Auth\AccountRepository;
use App\Infrastructure\CouchDB\CouchDbClient;

function failProvision(string $message, int $status = 1): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($status);
}

function readHiddenPassword(string $prompt): string
{
    if (!function_exists('stream_isatty') || !stream_isatty(STDIN) || !is_readable('/dev/tty')) {
        failProvision('Run this command from an interactive terminal so the password can be entered without echo.');
    }
    fwrite(STDOUT, $prompt);
    $output = [];
    $status = 0;
    exec('stty -echo < /dev/tty', $output, $status);
    if ($status !== 0) failProvision('Could not disable terminal echo; no account was changed.');
    try {
        $value = fgets(STDIN);
    } finally {
        $restoreStatus = 0;
        exec('stty echo < /dev/tty', $output, $restoreStatus);
        fwrite(STDOUT, PHP_EOL);
        if ($restoreStatus !== 0) failProvision('Could not restore terminal echo. Close this terminal and open a new one.');
    }
    if (!is_string($value)) failProvision('Could not read the password.');
    return rtrim($value, "\r\n");
}

$database = getenv('COUCHDB_DATABASE') ?: '';
if (!str_ends_with($database, '_test')) {
    failProvision('Refusing to modify staff accounts unless COUCHDB_DATABASE ends in _test.', 2);
}
if (!isset($argv[1]) || count($argv) !== 2) {
    failProvision('Usage: php scripts/provision_staff_password.php <staff-username>', 2);
}
$username = strtolower(trim((string) $argv[1]));
if ($username === '' || strlen($username) > 254 || preg_match('/[\s\x00-\x1F]/', $username) === 1) {
    failProvision('A valid staff username is required.', 2);
}

$password = readHiddenPassword('New password: ');
$confirmation = readHiddenPassword('Confirm password: ');
if (!hash_equals($password, $confirmation)) failProvision('Passwords do not match; no account was changed.');
if (strlen($password) < 12 || strlen($password) > 128
    || preg_match('/[a-z]/', $password) !== 1
    || preg_match('/[A-Z]/', $password) !== 1
    || preg_match('/\d/', $password) !== 1
    || preg_match('/[^a-zA-Z0-9]/', $password) !== 1) {
    failProvision('Password must be 12–128 characters and contain lowercase, uppercase, a digit, and a symbol.');
}

try {
    $client = new CouchDbClient(
        getenv('COUCHDB_URL') ?: '',
        getenv('COUCHDB_USER') ?: '',
        getenv('COUCHDB_PASSWORD') ?: '',
    );
    $accounts = new AccountRepository($client, $database);
    $matches = $accounts->byUsername('staff', $username);
    if (count($matches) !== 1) failProvision('Expected exactly one staff account for that email; no account was changed.');
    $account = $matches[0];
    if (($account['active'] ?? false) !== true) failProvision('The staff account is inactive; no account was changed.');
    $account['auth']['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    $account['auth']['requires_password_reset'] = false;
    $account['auth']['password_updated_at'] = gmdate('c');
    $account['meta']['password_provisioned_at'] = gmdate('c');
    $account['meta']['password_provisioned_by'] = 'local_cli';
    $accounts->update($account);
    fwrite(STDOUT, 'Password hash updated for staff account ' . $username . '. The password was not stored in logs.' . PHP_EOL);
} catch (Throwable $exception) {
    failProvision('Could not provision the staff account. Check the configured test database and retry.');
} finally {
    if (function_exists('sodium_memzero')) {
        if (isset($password)) sodium_memzero($password);
        if (isset($confirmation)) sodium_memzero($confirmation);
    }
}

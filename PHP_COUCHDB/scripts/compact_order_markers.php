<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Checkout\CheckoutRepository;
use App\Checkout\OrderMarkerCompactionService;
use App\Infrastructure\CouchDB\CouchDbClient;
function failCompaction(string $message, int $status = 1): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($status);
}

$apply = false;
$days = 90;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--apply') {
        $apply = true;
        continue;
    }
    if (preg_match('/^--older-than-days=(\d{1,4})$/', $argument, $matches) === 1) {
        $days = (int) $matches[1];
        continue;
    }
    failCompaction('Usage: php scripts/compact_order_markers.php [--older-than-days=90] [--apply]', 2);
}
if ($days < 30 || $days > 3650) failCompaction('Retention must be between 30 and 3650 days.', 2);

$database = getenv('COUCHDB_DATABASE') ?: '';
if (!str_ends_with($database, '_test')) failCompaction('This maintenance utility is restricted to a database ending in _test.', 2);
if ($apply) {
    if (!function_exists('stream_isatty') || !stream_isatty(STDIN)) failCompaction('Apply mode requires an interactive terminal. No data was changed.');
    fwrite(STDOUT, 'This will compact terminal-order markers in database ' . $database . '. Type the database name to continue: ');
    $confirmation = fgets(STDIN);
    if (!is_string($confirmation) || trim($confirmation) !== $database) failCompaction('Database confirmation did not match. No data was changed.', 2);
}

try {
    $client = new CouchDbClient(
        getenv('COUCHDB_URL') ?: '',
        getenv('COUCHDB_USER') ?: '',
        getenv('COUCHDB_PASSWORD') ?: '',
    );
    $repository = new CheckoutRepository($client, $database);
    $service = new OrderMarkerCompactionService($repository, $client, $database);
    $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-' . $days . ' days');
    $summary = $service->compact($cutoff, $apply);
    fwrite(STDOUT, ($apply ? 'Applied marker compaction.' : 'Dry run; no documents changed.') . PHP_EOL);
    fwrite(STDOUT, 'Cutoff: ' . $summary['cutoff'] . PHP_EOL);
    fwrite(STDOUT, 'Eligible terminal orders: ' . $summary['eligible_orders'] . PHP_EOL);
    fwrite(STDOUT, 'Product/voucher documents examined: ' . $summary['resources_examined'] . PHP_EOL);
    fwrite(STDOUT, 'Markers ' . ($apply ? 'removed' : 'eligible') . ': ' . $summary[$apply ? 'markers_removed' : 'markers_found'] . PHP_EOL);
    fwrite(STDOUT, 'Documents updated: ' . $summary['documents_updated'] . PHP_EOL);
} catch (Throwable $exception) {
    failCompaction('Marker compaction failed. Check the test database and retry; rerunning is safe.');
}

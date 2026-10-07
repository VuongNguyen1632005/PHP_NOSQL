<?php
declare(strict_types=1);

use App\Cart\MemberCartRepository;
use App\Cart\MemberCartService;
use App\Catalog\ProductRepository;
use App\Checkout\CheckoutRepository;
use App\Checkout\CheckoutService;
use App\Infrastructure\CouchDB\CouchDbClient;

require dirname(__DIR__) . '/vendor/autoload.php';
$_SESSION = [];
$orderId = trim((string) ($argv[1] ?? ''));
if ($orderId === '') {
    fwrite(STDERR, "Usage: php scripts/recover_checkout.php order:checkout:<operation-hash>\n");
    exit(2);
}

$database = getenv('COUCHDB_DATABASE') ?: '';
$client = new CouchDbClient(getenv('COUCHDB_URL') ?: '', getenv('COUCHDB_USER') ?: '', getenv('COUCHDB_PASSWORD') ?: '');
$orders = new CheckoutRepository($client, $database);
$order = $orders->get($orderId);
if ($order === null || ($order['type'] ?? null) !== 'order') {
    fwrite(STDERR, "Checkout order not found.\n");
    exit(1);
}
$phase = (string) ($order['meta']['checkout']['phase'] ?? 'unknown');
if (in_array($phase, ['completed', 'failed'], true)) {
    fwrite(STDOUT, 'Checkout is already terminal: ' . $phase . PHP_EOL);
    exit(0);
}

$products = new ProductRepository($client, $database);
$customerId = $order['customer']['customer_id'] ?? null;
if (is_string($customerId) && $customerId !== '') {
    $cart = new MemberCartService(new MemberCartRepository($client, $database), $products, $customerId);
} else {
    if (!in_array($phase, ['compensating', 'failed', 'completed'], true)) {
        fwrite(STDERR, "Guest checkout is in phase {$phase}. Finish/rollback requires the browser's original checkout session; resubmit the original form there with its checkout token.\n");
        exit(2);
    }
    $cart = null;
}

$service = new CheckoutService($orders, $cart, is_string($customerId) ? $customerId : null, 'customer:' . (string) $customerId);
try {
    $recovered = $service->recover($orderId);
    fwrite(STDOUT, 'Checkout recovered: ' . (string) ($recovered['meta']['checkout']['phase'] ?? 'unknown') . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Recovery needs attention: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

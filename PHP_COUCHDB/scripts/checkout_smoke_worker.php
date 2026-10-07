<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Cart\GuestCartService;
use App\Catalog\ProductRepository;
use App\Checkout\CheckoutException;
use App\Checkout\CheckoutRepository;
use App\Checkout\CheckoutService;
use App\Infrastructure\CouchDB\CouchDbClient;

[$script, $database, $productId, $variantId, $token, $barrier] = $argv + [null, '', '', '', '', '', ''];
while (!is_file($barrier)) usleep(1000);
usleep(random_int(0, 50000));
session_start();
$_SESSION = [];
$client = new CouchDbClient(getenv('COUCHDB_URL') ?: '', getenv('COUCHDB_USER') ?: '', getenv('COUCHDB_PASSWORD') ?: '');
$cart = new GuestCartService(new ProductRepository($client, $database));
$cart->add($productId, $variantId, 1);
$cart->setSelected($variantId, true);
$service = new CheckoutService(new CheckoutRepository($client, $database), $cart, null, 'guest:parallel:' . $token);
try {
    $service->placeOrder(['name' => 'Parallel Buyer', 'phone' => '0912345678', 'email' => 'parallel@example.test', 'address' => 'Test Street', 'note' => ''], 'BD', '', 'cod', $token);
    fwrite(STDOUT, "COMPLETED\n");
} catch (CheckoutException $exception) {
    fwrite(STDOUT, 'REJECTED: ' . $exception->getMessage() . "\n");
}

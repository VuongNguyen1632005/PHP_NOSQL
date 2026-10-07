<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/templates/helpers.php';

use App\Cart\GuestCartService;
use App\Catalog\ProductRepository;
use App\Checkout\CheckoutException;
use App\Checkout\CheckoutRepository;
use App\Checkout\CheckoutService;
use App\Checkout\OrderMarkerCompactionService;
use App\Infrastructure\CouchDB\CouchDbClient;
use App\Orders\OrderController;
use App\Orders\OrderAdminController;
use App\Orders\OrderWorkflowException;
use App\Orders\OrderWorkflowService;

$source = getenv('COUCHDB_DATABASE') ?: '';
if (!str_ends_with($source, '_test')) {
    fwrite(STDERR, "Refusing to run checkout smoke test unless COUCHDB_DATABASE ends in _test.\n");
    exit(2);
}
$client = new CouchDbClient(getenv('COUCHDB_URL') ?: '', getenv('COUCHDB_USER') ?: '', getenv('COUCHDB_PASSWORD') ?: '');
$database = 'retail_checkout_smoke_' . bin2hex(random_bytes(5));
$root = rawurlencode($database);
$created = false;

try {
    $response = $client->request('PUT', $root);
    if ($response->statusCode < 200 || $response->statusCode >= 300) throw new RuntimeException('Could not create isolated smoke database.');
    $created = true;

    $document = $client->request('GET', rawurlencode($source) . '/_design/domain_validation')->json();
    unset($document['_rev']);
    $response = $client->request('PUT', $root . '/_design/domain_validation', $document);
    if ($response->statusCode < 200 || $response->statusCode >= 300) throw new RuntimeException('Could not copy test validation design document.');
    foreach ([
        ['active_products', ['type', 'active'], ['type' => 'product', 'active' => true]],
        ['admin_products', ['type'], ['type' => 'product']],
        ['active_reviews', ['type', 'active'], ['type' => 'review', 'active' => true]],
        ['admin_reviews', ['type'], ['type' => 'review']],
        ['account_usernames', ['type', 'auth.username'], ['type' => ['$in' => ['customer', 'staff']]]],
        ['customer_orders', ['type', 'customer.customer_id', 'ordered_at'], ['type' => 'order']],
        ['admin_orders', ['type', 'status', 'ordered_at'], ['type' => 'order']],
        ['delivery_tracking_code', ['type', 'delivery_tracking.tracking_code'], ['type' => 'order']],
    ] as [$name, $fields, $partial]) {
        $response = $client->request('POST', $root . '/_index', [
            'index' => ['fields' => $fields, 'partial_filter_selector' => $partial], 'ddoc' => '_design/catalog_indexes', 'name' => $name, 'type' => 'json',
        ]);
        if ($response->statusCode < 200 || $response->statusCode >= 300) throw new RuntimeException('Could not create test Mango index.');
    }

    $paymentAuditFixture = [
        '_id' => 'order:PAYMENT-AUDIT-VALIDATION', 'type' => 'order', 'schema_version' => 2,
        'legacy_id' => 'PAYMENT-AUDIT-VALIDATION', 'status' => 'delivered', 'ordered_at' => gmdate('c'),
        'customer' => null, 'guest_order' => true,
        'receiver' => ['name' => 'Validator Test', 'phone' => '0900000000', 'address' => 'Test'],
        'items' => [['product_id' => 'A', 'variant_id' => 'A-S', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]],
        'totals' => ['total_quantity' => 1, 'subtotal' => 100, 'shipping_fee' => 0, 'discount_amount' => 0, 'grand_total' => 100],
        'payment' => ['method' => 'bank_transfer', 'status' => 'unpaid', 'paid' => false],
        'status_history' => [['status' => 'delivered', 'at' => gmdate('c'), 'source' => 'smoke']],
    ];
    $paymentAuditCreate = $client->request('PUT', $root . '/' . rawurlencode($paymentAuditFixture['_id']), $paymentAuditFixture);
    if ($paymentAuditCreate->statusCode < 200 || $paymentAuditCreate->statusCode >= 300) throw new RuntimeException('Could not create payment audit validator fixture.');
    $paymentAuditFixture['_rev'] = $client->request('GET', $root . '/' . rawurlencode($paymentAuditFixture['_id']))->json()['_rev'];
    $invalidAuditDoc = $paymentAuditFixture;
    $invalidAuditDoc['payment_history'] = [['status' => 'paid']];
    $invalidAudit = $client->request('PUT', $root . '/' . rawurlencode($paymentAuditFixture['_id']), $invalidAuditDoc);
    if ($invalidAudit->statusCode !== 403) throw new RuntimeException('CouchDB validator accepted malformed payment_history.');
    $paymentAuditFixture['_rev'] = $client->request('GET', $root . '/' . rawurlencode($paymentAuditFixture['_id']))->json()['_rev'];
    $paymentAuditFixture['payment']['status'] = 'paid';
    $paymentAuditFixture['payment']['paid'] = true;
    $paymentAuditFixture['payment_history'] = [[
        'status' => 'paid', 'at' => gmdate('c'), 'source' => 'php_admin_payment_verification',
        'staff_id' => 'SMOKE-STAFF', 'method' => 'bank_transfer', 'verification' => 'manual',
    ]];
    $validAudit = $client->request('PUT', $root . '/' . rawurlencode($paymentAuditFixture['_id']), $paymentAuditFixture);
    if ($validAudit->statusCode < 200 || $validAudit->statusCode >= 300) throw new RuntimeException('CouchDB validator rejected a valid payment audit.');
    $paymentAuditFixture['_rev'] = $client->request('GET', $root . '/' . rawurlencode($paymentAuditFixture['_id']))->json()['_rev'];
    $paymentAuditFixture['payment_history'] = [];
    $removedAudit = $client->request('PUT', $root . '/' . rawurlencode($paymentAuditFixture['_id']), $paymentAuditFixture);
    if ($removedAudit->statusCode !== 403) throw new RuntimeException('CouchDB validator allowed payment_history removal.');
    $paymentAuditFixture['payment_history'] = [[
        'status' => 'paid', 'at' => gmdate('c'), 'source' => 'php_admin_payment_verification',
        'staff_id' => 'SMOKE-STAFF', 'method' => 'bank_transfer', 'verification' => 'manual',
    ]];
    $paymentAuditFixture['payment']['status'] = 'unpaid';
    $paymentAuditFixture['payment']['paid'] = false;
    $paymentDowngrade = $client->request('PUT', $root . '/' . rawurlencode($paymentAuditFixture['_id']), $paymentAuditFixture);
    if ($paymentDowngrade->statusCode !== 403) throw new RuntimeException('CouchDB validator allowed a paid order to revert to unpaid.');

    $sourceDocuments = new CheckoutRepository($client, $source);
    $product = $sourceDocuments->activeDocuments('product')[0] ?? null;
    if (!is_array($product)) throw new RuntimeException('No active fixture product found.');
    $variant = null;
    foreach ($product['variants'] as $candidate) {
        if (($candidate['active'] ?? false) === true && (int) ($candidate['stock'] ?? 0) > 0) { $variant = $candidate; break; }
    }
    if (!is_array($variant)) throw new RuntimeException('No in-stock variant found.');
    $voucher = $sourceDocuments->get('voucher:SAVE10');
    foreach ([$product, $sourceDocuments->get('shipping_method:BD'), $voucher] as $document) {
        unset($document['_rev']);
        $response = $client->request('PUT', $root . '/' . rawurlencode((string) $document['_id']), $document);
        if ($response->statusCode < 200 || $response->statusCode >= 300) throw new RuntimeException('Could not copy test commerce fixture.');
    }
    sleep(2); // Let CouchDB build the copied Mango indexes before the first _find.

    $_SESSION = [];
    $cart = new GuestCartService(new ProductRepository($client, $database));
    $cart->add((string) $product['legacy_id'], (string) $variant['variant_id'], 1);
    $cart->setSelected((string) $variant['variant_id'], true);
    $service = new CheckoutService(new CheckoutRepository($client, $database), $cart, null, 'guest:checkout-smoke');
    $receiver = ['name' => 'Smoke Buyer', 'phone' => '0912345678', 'email' => 'smoke@example.test', 'address' => 'Test Street', 'note' => ''];
    $token = bin2hex(random_bytes(16));
    $order = $service->placeOrder($receiver, 'BD', 'SAVE10', 'cod', $token);
    if (($order['meta']['checkout']['phase'] ?? null) !== 'completed') throw new RuntimeException('Order did not complete.');
    $expectedDiscount = (int) round((int) $order['totals']['subtotal'] * 0.10, 0, PHP_ROUND_HALF_UP);
    if ((int) $order['totals']['discount_amount'] !== $expectedDiscount
        || (int) $order['totals']['grand_total'] !== (int) $order['totals']['subtotal'] + (int) $order['totals']['shipping_fee'] - $expectedDiscount
        || ($order['payment']['status'] ?? null) !== 'unpaid') {
        throw new RuntimeException('Order pricing/payment snapshot was incorrect.');
    }

    $documents = new CheckoutRepository($client, $database);
    $productAfter = $documents->get((string) $product['_id']);
    $voucherAfter = $documents->get('voucher:SAVE10');
    $expectedStock = (int) $variant['stock'] - 1;
    $actualStock = null;
    foreach ($productAfter['variants'] as $candidate) if ($candidate['variant_id'] === $variant['variant_id']) $actualStock = (int) $candidate['stock'];
    if ($actualStock !== $expectedStock || (int) $voucherAfter['remaining_quantity'] !== (int) $voucher['remaining_quantity'] - 1 || $cart->lineCount() !== 0) {
        throw new RuntimeException('Checkout reservation or cart clearing was incorrect.');
    }

    $replay = $service->placeOrder($receiver, 'BD', 'SAVE10', 'cod', $token);
    $productAfterReplay = $documents->get((string) $product['_id']);
    $replayStock = null;
    foreach ($productAfterReplay['variants'] as $candidate) if ($candidate['variant_id'] === $variant['variant_id']) $replayStock = (int) $candidate['stock'];
    if (($replay['_id'] ?? null) !== ($order['_id'] ?? null) || $replayStock !== $expectedStock) throw new RuntimeException('Replaying the same token changed stock or order identity.');

    $_SESSION['_completed_cart_orders'] = [];
    $_SESSION['guest_cart'] = [(string) $variant['variant_id'] => ['product_id' => $product['legacy_id'], 'quantity' => (int) $variant['stock'] + 1, 'selected' => true]];
    try {
        $service->placeOrder($receiver, 'BD', '', 'cod', bin2hex(random_bytes(16)));
        throw new RuntimeException('Expected insufficient stock to be rejected.');
    } catch (CheckoutException $exception) {
        if (!str_contains($exception->getMessage(), 'tồn kho')) throw $exception;
    }
    $productAfterFailure = $documents->get((string) $product['_id']);
    $failureStock = null;
    foreach ($productAfterFailure['variants'] as $candidate) if ($candidate['variant_id'] === $variant['variant_id']) $failureStock = (int) $candidate['stock'];
    if ($failureStock !== $expectedStock) throw new RuntimeException('Failed checkout modified stock.');

    // Recover a finalizing journal after stock was reserved but before the guest cart was cleared.
    $recoveryOperation = hash('sha256', 'smoke-finalizing:' . bin2hex(random_bytes(8)));
    $recoveryOrder = $order;
    unset($recoveryOrder['_rev']);
    $recoveryOrder['_id'] = 'order:checkout:' . $recoveryOperation;
    $recoveryOrder['legacy_id'] = 'CO' . strtoupper(substr($recoveryOperation, 0, 24));
    $recoveryOrder['meta']['checkout']['operation_id'] = $recoveryOperation;
    $recoveryOrder['meta']['checkout']['request_fingerprint'] = hash('sha256', 'recovery-finalizing');
    $recoveryOrder['meta']['checkout']['phase'] = 'finalizing';
    $recoveryOrder['meta']['checkout']['stock_plan'] = [$product['legacy_id'] => [$variant['variant_id'] => ['quantity' => 1, 'unit_price' => (int) $variant['price']]]];
    $recoveryOrder['meta']['checkout']['voucher_code'] = null;
    $productForRecovery = $documents->get((string) $product['_id']);
    foreach ($productForRecovery['variants'] as &$candidate) {
        if ($candidate['variant_id'] === $variant['variant_id']) $candidate['stock'] = (int) $candidate['stock'] - 1;
    }
    unset($candidate);
    $productForRecovery['meta']['checkout_reservations'][$recoveryOperation] = ['order_id' => $recoveryOrder['_id'], 'status' => 'reserved', 'variants' => [$variant['variant_id'] => ['quantity' => 1, 'status' => 'reserved']]];
    $documents->put($productForRecovery);
    $journalResponse = $documents->put($recoveryOrder);
    if ($journalResponse->statusCode < 200 || $journalResponse->statusCode >= 300) throw new RuntimeException('Could not create finalizing recovery journal.');
    $_SESSION['_completed_cart_orders'] = [];
    $_SESSION['guest_cart'] = [(string) $variant['variant_id'] => ['product_id' => $product['legacy_id'], 'quantity' => 1, 'selected' => true]];
    $recoveryCart = new GuestCartService(new ProductRepository($client, $database));
    $recovered = (new CheckoutService($documents, $recoveryCart, null, 'guest:recovery'))->recover((string) $recoveryOrder['_id']);
    $stockAfterRecovery = $documents->get((string) $product['_id']);
    $recoveredStock = null;
    foreach ($stockAfterRecovery['variants'] as $candidate) if ($candidate['variant_id'] === $variant['variant_id']) $recoveredStock = (int) $candidate['stock'];
    if (($recovered['meta']['checkout']['phase'] ?? null) !== 'completed' || $recoveryCart->lineCount() !== 0 || $recoveredStock !== $expectedStock - 1) {
        throw new RuntimeException('Finalizing checkout recovery did not complete idempotently.');
    }

    // Recover compensation after a simulated crash between journal update and stock release.
    $compensationOperation = hash('sha256', 'smoke-compensating:' . bin2hex(random_bytes(8)));
    $compensationOrder = $order;
    unset($compensationOrder['_rev']);
    $compensationOrder['_id'] = 'order:checkout:' . $compensationOperation;
    $compensationOrder['legacy_id'] = 'CO' . strtoupper(substr($compensationOperation, 0, 24));
    $compensationOrder['meta']['checkout']['operation_id'] = $compensationOperation;
    $compensationOrder['meta']['checkout']['request_fingerprint'] = hash('sha256', 'recovery-compensating');
    $compensationOrder['meta']['checkout']['phase'] = 'compensating';
    $compensationOrder['meta']['checkout']['failure_reason'] = 'smoke simulated interruption';
    $compensationOrder['meta']['checkout']['stock_plan'] = [$product['legacy_id'] => [$variant['variant_id'] => ['quantity' => 1, 'unit_price' => (int) $variant['price']]]];
    $compensationOrder['meta']['checkout']['voucher_code'] = null;
    $productForCompensation = $documents->get((string) $product['_id']);
    foreach ($productForCompensation['variants'] as &$candidate) {
        if ($candidate['variant_id'] === $variant['variant_id']) $candidate['stock'] = (int) $candidate['stock'] - 1;
    }
    unset($candidate);
    $productForCompensation['meta']['checkout_reservations'][$compensationOperation] = ['order_id' => $compensationOrder['_id'], 'status' => 'reserved', 'variants' => [$variant['variant_id'] => ['quantity' => 1, 'status' => 'reserved']]];
    $documents->put($productForCompensation);
    $journalResponse = $documents->put($compensationOrder);
    if ($journalResponse->statusCode < 200 || $journalResponse->statusCode >= 300) throw new RuntimeException('Could not create compensating recovery journal.');
    $recoveryService = new CheckoutService($documents, null, null, 'guest:compensation-recovery');
    try {
        $recoveryService->recover((string) $compensationOrder['_id']);
        throw new RuntimeException('Expected compensating recovery to report the failed checkout.');
    } catch (CheckoutException $exception) {
        if (!str_contains($exception->getMessage(), 'simulated interruption')) throw $exception;
    }
    $stockAfterCompensation = $documents->get((string) $product['_id']);
    $compensatedStock = null;
    foreach ($stockAfterCompensation['variants'] as $candidate) if ($candidate['variant_id'] === $variant['variant_id']) $compensatedStock = (int) $candidate['stock'];
    if ($compensatedStock !== $expectedStock - 1 || ($stockAfterCompensation['meta']['checkout_reservations'][$compensationOperation]['status'] ?? null) !== 'released') {
        throw new RuntimeException('Compensation recovery did not restore stock exactly once.');
    }

    // Two independent PHP processes race for one remaining unit.
    $raceProduct = $documents->get((string) $product['_id']);
    foreach ($raceProduct['variants'] as &$candidate) {
        if ($candidate['variant_id'] === $variant['variant_id']) $candidate['stock'] = 1;
    }
    unset($candidate);
    $documents->put($raceProduct);
    $barrier = '/tmp/checkout-smoke-' . bin2hex(random_bytes(8));
    $workers = [];
    foreach ([bin2hex(random_bytes(16)), bin2hex(random_bytes(16))] as $workerToken) {
        $command = PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/checkout_smoke_worker.php') . ' '
            . escapeshellarg($database) . ' ' . escapeshellarg((string) $product['legacy_id']) . ' '
            . escapeshellarg((string) $variant['variant_id']) . ' ' . escapeshellarg($workerToken) . ' ' . escapeshellarg($barrier);
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not start a checkout race worker.');
        fclose($pipes[0]);
        $workers[] = [$process, $pipes[1], $pipes[2]];
    }
    file_put_contents($barrier, 'go');
    $workerResults = [];
    foreach ($workers as [$process, $stdout, $stderr]) {
        $workerResults[] = trim(stream_get_contents($stdout) ?: '');
        $workerError = trim(stream_get_contents($stderr) ?: '');
        fclose($stdout);
        fclose($stderr);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) throw new RuntimeException('Checkout race worker failed: ' . $workerError);
    }
    unlink($barrier);
    $raceProductAfter = $documents->get((string) $product['_id']);
    $raceStock = null;
    foreach ($raceProductAfter['variants'] as $candidate) if ($candidate['variant_id'] === $variant['variant_id']) $raceStock = (int) $candidate['stock'];
    if (count(array_filter($workerResults, static fn (string $result): bool => str_starts_with($result, 'COMPLETED'))) !== 1
        || count(array_filter($workerResults, static fn (string $result): bool => str_starts_with($result, 'REJECTED:'))) !== 1
        || $raceStock !== 0) {
        throw new RuntimeException('Concurrent checkout did not protect the last stock unit: ' . implode(' | ', $workerResults));
    }

    $cancelProduct = $documents->get((string) $product['_id']);
    foreach ($cancelProduct['variants'] as &$candidate) {
        if ($candidate['variant_id'] === $variant['variant_id']) $candidate['stock'] = 2;
    }
    unset($candidate);
    $documents->put($cancelProduct);
    $cancelVoucher = $documents->get('voucher:SAVE10');
    $cancelVoucher['remaining_quantity'] = 10;
    $documents->put($cancelVoucher);
    $_SESSION['_completed_cart_orders'] = [];
    $_SESSION['guest_cart'] = [];
    $cancelCart = new GuestCartService(new ProductRepository($client, $database));
    $cancelCart->add((string) $product['legacy_id'], (string) $variant['variant_id'], 1);
    $cancelCart->setSelected((string) $variant['variant_id'], true);
    $cancelService = new CheckoutService($documents, $cancelCart, null, 'guest:cancel-smoke');
    $cancelOrder = $cancelService->placeOrder($receiver, 'BD', 'SAVE10', 'cod', bin2hex(random_bytes(16)));
    $workflow = new OrderWorkflowService($documents);
    $cancelledOrder = $workflow->transition((string) $cancelOrder['_id'], 'cancelled', 'staff:smoke');
    $cancelledProduct = $documents->get((string) $product['_id']);
    $cancelledVoucher = $documents->get('voucher:SAVE10');
    $cancelledStock = null;
    foreach ($cancelledProduct['variants'] as $candidate) if ($candidate['variant_id'] === $variant['variant_id']) $cancelledStock = (int) $candidate['stock'];
    if (($cancelledOrder['status'] ?? null) !== 'cancelled'
        || ($cancelledOrder['meta']['order_transition']['phase'] ?? null) !== 'completed'
        || $cancelledStock !== 2 || (int) $cancelledVoucher['remaining_quantity'] !== 10) {
        throw new RuntimeException('Cancelling a COD order did not restore stock and voucher usage: ' . json_encode([
            'status' => $cancelledOrder['status'] ?? null,
            'phase' => $cancelledOrder['meta']['order_transition']['phase'] ?? null,
            'stock' => $cancelledStock,
            'voucher' => $cancelledVoucher['remaining_quantity'] ?? null,
            'reservation' => $cancelledProduct['meta']['checkout_reservations'][$cancelledOrder['meta']['checkout']['operation_id'] ?? ''] ?? null,
            'cancel_marker' => $cancelledProduct['meta']['order_cancellations'][$cancelOrder['_id']] ?? null,
        ], JSON_UNESCAPED_SLASHES));
    }
    $interruptedCancel = $documents->get((string) $cancelOrder['_id']);
    $interruptedCancel['status'] = 'pending'; // Simulate a crash after releasing documents but before finalizing the order journal.
    if (($interruptedCancel['status_history'][array_key_last($interruptedCancel['status_history'])]['status'] ?? null) === 'cancelled') array_pop($interruptedCancel['status_history']);
    $interruptedCancel['meta']['order_transition']['phase'] = 'cancelling';
    $documents->put($interruptedCancel);
    try {
        $workflow->transition((string) $cancelOrder['_id'], 'confirmed', 'staff:smoke');
        throw new RuntimeException('Expected an in-progress cancellation to block a competing status change.');
    } catch (OrderWorkflowException) {
    }
    $resumedCancel = $workflow->transition((string) $cancelOrder['_id'], 'cancelled', 'staff:smoke');
    if (($resumedCancel['meta']['order_transition']['phase'] ?? null) !== 'completed'
        || count(array_filter($resumedCancel['status_history'], static fn (array $entry): bool => ($entry['status'] ?? null) === 'cancelled')) !== 1) {
        throw new RuntimeException('In-progress cancellation did not recover with a single status event.');
    }
    $workflow->transition((string) $cancelOrder['_id'], 'cancelled', 'staff:smoke');
    $afterCancelReplayProduct = $documents->get((string) $product['_id']);
    $afterCancelReplayVoucher = $documents->get('voucher:SAVE10');
    $afterCancelReplayStock = null;
    foreach ($afterCancelReplayProduct['variants'] as $candidate) if ($candidate['variant_id'] === $variant['variant_id']) $afterCancelReplayStock = (int) $candidate['stock'];
    if ($afterCancelReplayStock !== 2 || (int) $afterCancelReplayVoucher['remaining_quantity'] !== 10) {
        throw new RuntimeException('Retrying cancellation restored stock or voucher more than once.');
    }
    try {
        $workflow->transition((string) $cancelOrder['_id'], 'confirmed', 'staff:smoke');
        throw new RuntimeException('Expected a terminal cancelled order to reject further transitions.');
    } catch (OrderWorkflowException) {
    }
    $workflowOperation = hash('sha256', 'smoke-status-flow:' . bin2hex(random_bytes(8)));
    $workflowOrder = $order;
    unset($workflowOrder['_rev']);
    $workflowOrder['_id'] = 'order:checkout:' . $workflowOperation;
    $workflowOrder['legacy_id'] = 'CO' . strtoupper(substr($workflowOperation, 0, 24));
    $workflowOrder['customer'] = ['customer_id' => 'customer:smoke-delivery', 'name' => 'Delivery Buyer', 'email' => 'delivery@example.test'];
    $workflowOrder['guest_order'] = false;
    $workflowOrder['meta']['checkout']['operation_id'] = $workflowOperation;
    $workflowOrder['meta']['checkout']['phase'] = 'completed';
    $workflowOrder['status'] = 'pending';
    $workflowOrder['status_history'] = [['status' => 'pending', 'at' => gmdate('c'), 'source' => 'smoke']];
    $workflowWrite = $documents->put($workflowOrder);
    if ($workflowWrite->statusCode < 200 || $workflowWrite->statusCode >= 300) throw new RuntimeException('Could not create order status workflow fixture.');
    try {
        $workflow->transition((string) $workflowOrder['_id'], 'shipping', 'staff:smoke');
        throw new RuntimeException('Expected an invalid pending-to-shipping transition to be rejected.');
    } catch (OrderWorkflowException) {
    }
    $_SESSION['auth_user'] = ['type' => 'staff', 'legacy_id' => 'staff:smoke', 'name' => 'Smoke Staff', 'username' => 'staff@example.test', 'role' => 'manager'];
    $_POST = ['csrf_token' => csrfToken(), 'status' => 'confirmed'];
    $adminWorkflowController = new OrderAdminController($documents, $workflow);
    $postTransition = $adminWorkflowController->updateStatus(['id' => (string) $workflowOrder['_id']]);
    if ($postTransition->status !== 303 || ($documents->get((string) $workflowOrder['_id'])['status'] ?? null) !== 'confirmed') {
        throw new RuntimeException('Staff CSRF-protected status POST did not update the order.');
    }
    foreach (['packing', 'shipping'] as $nextStatus) {
        $workflowOrder = $workflow->transition((string) $workflowOrder['_id'], $nextStatus, 'staff:smoke');
    }
    $tracking = $workflowOrder['delivery_tracking'] ?? null;
    if (($workflowOrder['status'] ?? null) !== 'shipping' || !is_array($tracking)
        || ($tracking['status'] ?? null) !== 'created'
        || preg_match('/^DEL-\d{8}-[A-F0-9]{12}$/', (string) ($tracking['tracking_code'] ?? '')) !== 1
        || isset($workflowOrder['shipping']['tracking_code'], $workflowOrder['shipping']['estimated_delivery_date'])
        || ($workflowOrder['assigned_staff_id'] ?? null) !== 'staff:smoke'
        || count($workflowOrder['status_history'] ?? []) !== 4) {
        throw new RuntimeException('Starting shipment did not create separate, assigned, and uniquely formatted delivery tracking.');
    }
    $_SESSION['auth_user'] = ['type' => 'customer', 'legacy_id' => 'customer:smoke-delivery', 'name' => 'Delivery Buyer', 'username' => 'delivery@example.test'];
    $customerDeliveryPage = (new OrderController($documents, $workflow))->detail(['id' => (string) $workflowOrder['_id']]);
    $_SESSION['auth_user'] = ['type' => 'customer', 'legacy_id' => 'customer:someone-else', 'name' => 'Outsider', 'username' => 'outsider@example.test'];
    $foreignDeliveryPage = (new OrderController($documents, $workflow))->detail(['id' => (string) $workflowOrder['_id']]);
    $_SESSION['auth_user'] = ['type' => 'staff', 'legacy_id' => 'staff:smoke', 'name' => 'Smoke Staff', 'username' => 'staff@example.test', 'role' => 'staff'];
    $adminDeliveryPage = (new OrderAdminController($documents, $workflow))->detail(['id' => (string) $workflowOrder['_id']]);
    if ($customerDeliveryPage->status !== 200 || !str_contains($customerDeliveryPage->body, (string) $tracking['tracking_code'])
        || !str_contains($customerDeliveryPage->body, 'Đã tạo vận đơn') || !str_contains($customerDeliveryPage->body, 'Sản phẩm trong đơn')
        || str_contains($customerDeliveryPage->body, 'staff:smoke') || str_contains($customerDeliveryPage->body, 'name="delivery_status"')
        || $foreignDeliveryPage->status !== 404 || $adminDeliveryPage->status !== 200
        || !str_contains($adminDeliveryPage->body, '/delivery') || !str_contains($adminDeliveryPage->body, 'name="csrf_token"')) {
        throw new RuntimeException('Customer/admin delivery detail rendering, ownership, or CSRF form failed.');
    }
    $_POST = ['csrf_token' => 'invalid-token', 'delivery_status' => 'picked_up'];
    if ((new OrderAdminController($documents, $workflow))->updateDeliveryStatus(['id' => (string) $workflowOrder['_id']])->status !== 303
        || ($documents->get((string) $workflowOrder['_id'])['delivery_tracking']['status'] ?? null) !== 'created') {
        throw new RuntimeException('Delivery update accepted an invalid CSRF token.');
    }
    $_SESSION['auth_user']['role'] = 'manager';
    $trackingStaleCopy = $workflowOrder;
    $invalidDeliveryJump = $trackingStaleCopy;
    $invalidDeliveryJump['delivery_tracking']['status'] = 'in_transit';
    $invalidDeliveryJump['delivery_tracking']['history'][] = [
        'status' => 'in_transit', 'at' => gmdate('c'), 'note' => 'invalid jump', 'updated_by' => 'staff:smoke',
    ];
    if ($documents->put($invalidDeliveryJump)->statusCode !== 403) {
        throw new RuntimeException('CouchDB validator accepted a skipped delivery transition.');
    }
    try {
        $workflow->transition((string) $workflowOrder['_id'], 'delivered', 'staff:smoke');
        throw new RuntimeException('Order was marked delivered before its delivery tracking completed.');
    } catch (OrderWorkflowException) {
    }
    try {
        $workflow->updateDeliveryStatus((string) $workflowOrder['_id'], 'in_transit', 'staff:smoke');
        throw new RuntimeException('Delivery skipped the picked_up state.');
    } catch (OrderWorkflowException) {
    }
    $_POST = ['csrf_token' => csrfToken(), 'delivery_status' => 'picked_up', 'note' => 'Đã nhận tại kho'];
    $adminDeliveryUpdate = (new OrderAdminController($documents, $workflow))->updateDeliveryStatus(['id' => (string) $workflowOrder['_id']]);
    $workflowOrder = $documents->get((string) $workflowOrder['_id']);
    if ($adminDeliveryUpdate->status !== 303 || ($workflowOrder['delivery_tracking']['status'] ?? null) !== 'picked_up'
        || ($workflowOrder['delivery_tracking']['history'][1]['note'] ?? null) !== 'Đã nhận tại kho') {
        throw new RuntimeException('CSRF-protected staff delivery form did not record its update.');
    }
    $staleDocument = $trackingStaleCopy;
    $staleDocument['delivery_tracking']['history'][0]['note'] = 'stale overwrite';
    $staleWrite = $client->request('PUT', $root . '/' . rawurlencode((string) $workflowOrder['_id']), $staleDocument);
    if ($staleWrite->statusCode !== 409) throw new RuntimeException('CouchDB did not reject a stale order revision during delivery update.');
    $workflowOrder = $workflow->updateDeliveryStatus((string) $workflowOrder['_id'], 'failed_delivery', 'staff:smoke', 'Không liên lạc được người nhận');
    $_SESSION['auth_user'] = ['type' => 'customer', 'legacy_id' => 'customer:smoke-delivery', 'name' => 'Delivery Buyer', 'username' => 'delivery@example.test'];
    $failedDeliveryPage = (new OrderController($documents, $workflow))->detail(['id' => (string) $workflowOrder['_id']]);
    if ($failedDeliveryPage->status !== 200 || !str_contains($failedDeliveryPage->body, 'Giao hàng thất bại')
        || !str_contains($failedDeliveryPage->body, 'Không liên lạc được người nhận')
        || str_contains($failedDeliveryPage->body, 'staff:smoke')) {
        throw new RuntimeException('Customer failed-delivery UI did not show its actual event safely.');
    }
    $_SESSION['auth_user'] = ['type' => 'staff', 'legacy_id' => 'staff:smoke', 'name' => 'Smoke Staff', 'username' => 'staff@example.test', 'role' => 'manager'];
    $workflowOrder = $workflow->updateDeliveryStatus((string) $workflowOrder['_id'], 'in_transit', 'staff:smoke');
    $historyTamper = $workflowOrder;
    $historyTamper['delivery_tracking']['history'][0]['note'] = 'tampered event';
    if ($documents->put($historyTamper)->statusCode !== 403) throw new RuntimeException('CouchDB validator allowed a prior delivery event to be rewritten.');
    $pickedUpHistoryCount = count($workflowOrder['delivery_tracking']['history'] ?? []);
    $workflowOrder = $workflow->updateDeliveryStatus((string) $workflowOrder['_id'], 'out_for_delivery', 'staff:smoke');
    try {
        $workflow->updateDeliveryStatus((string) $workflowOrder['_id'], 'picked_up', 'staff:smoke');
        throw new RuntimeException('Invalid backward delivery transition was accepted.');
    } catch (OrderWorkflowException) {
    }
    $workflowOrder = $workflow->updateDeliveryStatus((string) $workflowOrder['_id'], 'delivered', 'staff:smoke');
    $workflowHistory = $workflowOrder['delivery_tracking']['history'] ?? [];
    if (($workflowOrder['status'] ?? null) !== 'delivered'
        || ($workflowOrder['delivery_tracking']['status'] ?? null) !== 'delivered'
        || ($workflowOrder['delivery_tracking']['delivered_at'] ?? null) !== ($workflowHistory[array_key_last($workflowHistory)]['at'] ?? null)
        || count($workflowHistory) !== $pickedUpHistoryCount + 2
        || count($workflowOrder['status_history'] ?? []) !== 5) {
        throw new RuntimeException('Delivery completion did not atomically finish the order and append tracking history.');
    }
    $_SESSION['auth_user'] = ['type' => 'customer', 'legacy_id' => 'customer:smoke-delivery', 'name' => 'Delivery Buyer', 'username' => 'delivery@example.test'];
    $deliveredDeliveryPage = (new OrderController($documents, $workflow))->detail(['id' => (string) $workflowOrder['_id']]);
    if ($deliveredDeliveryPage->status !== 200 || !str_contains($deliveredDeliveryPage->body, (string) $tracking['tracking_code'])
        || !str_contains($deliveredDeliveryPage->body, 'Giao thành công')
        || !str_contains($deliveredDeliveryPage->body, date('d/m/Y H:i', strtotime((string) $workflowOrder['delivery_tracking']['delivered_at'])))) {
        throw new RuntimeException('Customer delivered-order UI did not show tracking code, delivered status and actual delivery time.');
    }
    try {
        $workflow->transition((string) $workflowOrder['_id'], 'pending', 'staff:smoke');
        throw new RuntimeException('Delivered order was allowed to return to pending.');
    } catch (OrderWorkflowException) {
    }
    if ($workflow->updateDeliveryStatus((string) $workflowOrder['_id'], 'delivered', 'staff:smoke') !== $workflowOrder) {
        throw new RuntimeException('Repeating the terminal delivery update was not idempotent.');
    }

    $receiptOrder = $order;
    unset($receiptOrder['_rev']);
    $receiptOrder['_id'] = 'order:smoke-customer-delivery-confirm';
    $receiptOrder['legacy_id'] = 'CUSTOMER-DELIVERY-CONFIRM';
    $receiptOrder['customer'] = ['customer_id' => 'customer:smoke-receipt', 'name' => 'Receipt Buyer'];
    $receiptOrder['guest_order'] = false;
    $receiptOrder['status'] = 'pending';
    $receiptOrder['status_history'] = [['status' => 'pending', 'at' => gmdate('c'), 'source' => 'smoke']];
    if ($documents->put($receiptOrder)->statusCode >= 300) throw new RuntimeException('Could not create customer receipt fixture.');
    foreach (['confirmed', 'packing', 'shipping'] as $orderStatus) {
        $receiptOrder = $workflow->transition((string) $receiptOrder['_id'], $orderStatus, 'staff:smoke');
    }
    $receiptOrder = $workflow->confirmReceived((string) $receiptOrder['_id'], 'customer:smoke-receipt');
    $receiptDelivery = $receiptOrder['delivery_tracking']['history'] ?? [];
    if (($receiptOrder['status'] ?? null) !== 'delivered' || ($receiptOrder['delivery_tracking']['status'] ?? null) !== 'delivered'
        || ($receiptOrder['delivery_tracking']['history'][1]['source'] ?? null) !== 'php_customer_confirm_received'
        || ($receiptOrder['payment']['paid'] ?? false) !== true || count($receiptDelivery) !== 2) {
        throw new RuntimeException('Customer receipt confirmation did not atomically complete tracked delivery and COD.');
    }

    $legacyShippingOrder = $order;
    unset($legacyShippingOrder['_rev']);
    $legacyShippingOrder['_id'] = 'order:smoke-legacy-shipping-tracking';
    $legacyShippingOrder['legacy_id'] = 'LEGACY-SHIPPING-TRACKING';
    $legacyShippingOrder['status'] = 'shipping';
    $legacyShippingOrder['assigned_staff_id'] = null;
    $legacyShippingOrder['shipping']['tracking_code'] = 'LEGACY-TRACK-001';
    $legacyShippingOrder['shipping']['estimated_delivery_date'] = '2026-10-10';
    $legacyShippingOrder['status_history'] = [['status' => 'shipping', 'at' => gmdate('c'), 'source' => 'legacy-smoke']];
    if ($documents->put($legacyShippingOrder)->statusCode >= 300) throw new RuntimeException('Could not create a legacy tracking compatibility fixture.');
    $legacyTracking = $workflow->updateDeliveryStatus((string) $legacyShippingOrder['_id'], 'created', 'staff:smoke');
    if (($legacyTracking['delivery_tracking']['tracking_code'] ?? null) !== 'LEGACY-TRACK-001'
        || ($legacyTracking['delivery_tracking']['estimated_delivery_date'] ?? null) !== '2026-10-10'
        || isset($legacyTracking['shipping']['tracking_code'], $legacyTracking['shipping']['estimated_delivery_date'])
        || ($legacyTracking['assigned_staff_id'] ?? null) !== 'staff:smoke') {
        throw new RuntimeException('Legacy shipping tracking was not transferred without duplicate fields.');
    }


    $_SESSION['auth_user'] = ['type' => 'customer', 'legacy_id' => 'customer:unauthorized', 'name' => 'Unauthorized', 'username' => 'customer@example.test'];
    $_POST = ['csrf_token' => csrfToken(), 'delivery_status' => 'failed_delivery'];
    if ((new OrderAdminController($documents, $workflow))->updateDeliveryStatus(['id' => (string) $workflowOrder['_id']])->status !== 403) {
        throw new RuntimeException('Customer account could update delivery tracking.');
    }

    $historyCustomer = 'customer:smoke-history:' . bin2hex(random_bytes(5));
    $outsiderCustomer = 'customer:smoke-outsider:' . bin2hex(random_bytes(5));
    $historyIds = [];
    foreach ([$historyCustomer, $historyCustomer, $outsiderCustomer] as $index => $ownerId) {
        $historyOrder = $order;
        unset($historyOrder['_rev']);
        $historyOrder['_id'] = 'order:smoke-history:' . $index . ':' . bin2hex(random_bytes(5));
        $historyOrder['legacy_id'] = 'HISTORY' . $index . strtoupper(bin2hex(random_bytes(3)));
        $historyOrder['customer'] = ['customer_id' => $ownerId, 'name' => 'History Buyer', 'email' => 'history@example.test', 'phone' => '0912345678'];
        $historyOrder['guest_order'] = false;
        if ($index === 1) $historyOrder['meta']['checkout']['phase'] = 'failed';
        $historyWrite = $documents->put($historyOrder);
        if ($historyWrite->statusCode < 200 || $historyWrite->statusCode >= 300) throw new RuntimeException('Could not create isolated order history fixture.');
        $historyIds[$index] = $historyOrder['_id'];
    }
    $visibleHistory = $documents->ordersForCustomer($historyCustomer);
    if (count($visibleHistory) !== 1 || ($visibleHistory[0]['customer']['customer_id'] ?? null) !== $historyCustomer
        || ($visibleHistory[0]['meta']['checkout']['phase'] ?? null) !== 'completed') {
        throw new RuntimeException('Customer order history query exposed another customer’s order or an incomplete checkout journal.');
    }
    $_SESSION['auth_user'] = ['type' => 'customer', 'legacy_id' => $historyCustomer, 'name' => 'History Buyer', 'username' => 'history@example.test'];
    $_SESSION['_cart_line_count'] = 0;
    $orderController = new OrderController($documents, $workflow);
    $historyPage = $orderController->index();
    $ownedDetail = $orderController->detail(['id' => $historyIds[0]]);
    $outsiderDetail = $orderController->detail(['id' => $historyIds[2]]);
    $failedDetail = $orderController->detail(['id' => $historyIds[1]]);
    $outsiderOrder = $documents->get((string) $historyIds[2]);
    if ($historyPage->status !== 200 || !str_contains($historyPage->body, (string) $visibleHistory[0]['legacy_id'])
        || str_contains($historyPage->body, (string) ($outsiderOrder['legacy_id'] ?? ''))
        || $ownedDetail->status !== 200 || $outsiderDetail->status !== 404 || $failedDetail->status !== 404) {
        throw new RuntimeException('Order history UI did not enforce customer ownership and completed checkout visibility.');
    }
    $_SESSION['auth_user'] = ['type' => 'staff', 'legacy_id' => 'staff:smoke', 'name' => 'Smoke Staff', 'username' => 'staff@example.test', 'role' => 'manager'];
    $adminController = new OrderAdminController($documents, $workflow);
    $staffList = $adminController->index([], ['status' => 'cancelled']);
    $staffDetail = $adminController->detail(['id' => $cancelOrder['_id']]);
    if ($staffList->status !== 200 || !str_contains($staffList->body, (string) $cancelOrder['legacy_id']) || $staffDetail->status !== 200) {
        throw new RuntimeException('Staff order management pages did not render the filtered order.');
    }
    $_SESSION['auth_user'] = ['type' => 'customer', 'legacy_id' => $historyCustomer, 'name' => 'History Buyer', 'username' => 'history@example.test'];
    if ($adminController->index([], [])->status !== 403 || $adminController->detail(['id' => $cancelOrder['_id']])->status !== 403) {
        throw new RuntimeException('Customer account gained access to staff order management.');
    }

    $oldTerminalOrder = $documents->get((string) $cancelOrder['_id']);
    $oldTerminalAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-120 days')->format(DATE_ATOM);
    $oldTerminalOrder['meta']['order_transition']['completed_at'] = $oldTerminalAt;
    $oldTerminalOrder['status_history'][array_key_last($oldTerminalOrder['status_history'])]['at'] = $oldTerminalAt;
    $documents->put($oldTerminalOrder);
    $compactor = new OrderMarkerCompactionService($documents, $client, $database);
    $retentionCutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-90 days');
    $dryRun = $compactor->compact($retentionCutoff);
    if ($dryRun['dry_run'] !== true || $dryRun['eligible_orders'] !== 1 || $dryRun['markers_found'] !== 4 || $dryRun['documents_updated'] !== 0) {
        throw new RuntimeException('Marker compaction dry run did not report the old terminal order without writing.');
    }
    $productBeforeApply = $documents->get((string) $product['_id']);
    $voucherBeforeApply = $documents->get('voucher:SAVE10');
    $operationId = (string) $cancelledOrder['meta']['checkout']['operation_id'];
    if (!isset($productBeforeApply['meta']['checkout_reservations'][$operationId])
        || !isset($productBeforeApply['meta']['order_cancellations'][$cancelOrder['_id']])
        || !isset($voucherBeforeApply['meta']['checkout_redemptions'][$operationId])
        || !isset($voucherBeforeApply['meta']['order_cancellations'][$cancelOrder['_id']])) {
        throw new RuntimeException('Dry-run compaction modified terminal-order markers.');
    }
    $appliedCompaction = $compactor->compact($retentionCutoff, true);
    $productAfterApply = $documents->get((string) $product['_id']);
    $voucherAfterApply = $documents->get('voucher:SAVE10');
    if ($appliedCompaction['markers_removed'] !== 4 || $appliedCompaction['documents_updated'] !== 2
        || isset($productAfterApply['meta']['checkout_reservations'][$operationId])
        || isset($productAfterApply['meta']['order_cancellations'][$cancelOrder['_id']])
        || isset($voucherAfterApply['meta']['checkout_redemptions'][$operationId])
        || isset($voucherAfterApply['meta']['order_cancellations'][$cancelOrder['_id']])
        || (int) ($productAfterApply['meta']['marker_compaction']['markers_removed'] ?? 0) !== 2
        || (int) ($voucherAfterApply['meta']['marker_compaction']['markers_removed'] ?? 0) !== 2) {
        throw new RuntimeException('Marker compaction did not remove only eligible terminal markers and leave an audit summary.');
    }
    $repeatCompaction = $compactor->compact($retentionCutoff, true);
    if ($repeatCompaction['markers_removed'] !== 0 || $repeatCompaction['documents_updated'] !== 0) {
        throw new RuntimeException('Marker compaction was not idempotent.');
    }
    $workflow->transition((string) $cancelOrder['_id'], 'cancelled', 'staff:smoke');
    $stockAfterCompactedReplay = null;
    foreach ($documents->get((string) $product['_id'])['variants'] as $candidate) if ($candidate['variant_id'] === $variant['variant_id']) $stockAfterCompactedReplay = (int) $candidate['stock'];
    if ($stockAfterCompactedReplay !== 2) throw new RuntimeException('A cancellation retry after compaction changed stock.');

    fwrite(STDOUT, "PASS: checkout recovery, order workflow, delivery tracking transitions/history/CSRF/ownership/revision conflict, concurrent stock protection, cancellation recovery, customer order history, and marker compaction.\n");
} finally {
    if ($created) {
        $response = $client->request('DELETE', $root);
        if ($response->statusCode < 200 || $response->statusCode >= 300) throw new RuntimeException('Could not remove the isolated smoke database.');
    }
}

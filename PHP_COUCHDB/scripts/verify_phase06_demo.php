<?php

declare(strict_types=1);

use App\Infrastructure\CouchDB\CouchDbClient;

require dirname(__DIR__) . '/vendor/autoload.php';

function verifyFail(string $message): never { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
$database = (string) (getenv('PHASE06_DEMO_DATABASE') ?: 'shopquan_ao_phase06_demo_test');
if (!preg_match('/^[a-z][a-z0-9_$()+\/-]*_phase06_demo_test$/', $database) || $database === (string) getenv('COUCHDB_DATABASE')) {
    verifyFail('refusing to inspect a database outside the dedicated demo target');
}
foreach (['COUCHDB_URL', 'COUCHDB_USER', 'COUCHDB_PASSWORD'] as $name) if (trim((string) getenv($name)) === '') verifyFail('missing CouchDB configuration ' . $name);
$client = new CouchDbClient((string) getenv('COUCHDB_URL'), (string) getenv('COUCHDB_USER'), (string) getenv('COUCHDB_PASSWORD'));
$response = $client->request('GET', rawurlencode($database) . '/_all_docs?include_docs=true&limit=1000');
if ($response->statusCode !== 200) verifyFail('demo database could not be read (HTTP ' . $response->statusCode . ')');
$payload = $response->json();
$rows = $payload['rows'] ?? null;
if (!is_array($rows) || ($payload['total_rows'] ?? -1) !== count($rows)) verifyFail('database rows were incomplete');
$counts = [];
$orders = [];
foreach ($rows as $row) {
    $id = (string) ($row['id'] ?? '');
    if (str_starts_with($id, '_design/')) continue;
    $doc = $row['doc'] ?? null;
    $demoCart = is_array($doc) && ($doc['type'] ?? null) === 'cart'
        && preg_match('/^P06-00[1-6]$/', (string) ($doc['customer_id'] ?? '')) === 1
        && $id === 'cart:' . (string) $doc['customer_id'];
    if ($demoCart) {
        foreach ($doc['items'] ?? [] as $line) if (!is_array($line) || (int) ($line['quantity'] ?? 0) <= 0) verifyFail('invalid demo cart quantity in ' . $id);
        continue;
    }
    if (!is_array($doc) || ($doc['meta']['phase06_demo']['dataset_version'] ?? null) !== 1) verifyFail('unowned document found: ' . $id);
    $type = (string) ($doc['type'] ?? '');
    $counts[$type] = ($counts[$type] ?? 0) + 1;
    if ($type === 'order') $orders[] = $doc;
    if ($type === 'product') foreach ($doc['variants'] ?? [] as $variant) if (!is_int($variant['stock'] ?? null) || $variant['stock'] < 0) verifyFail('invalid product stock in ' . $id);
}
foreach (['customer' => 6, 'staff' => 2, 'product' => 18, 'order' => 27, 'shipping_method' => 2, 'voucher' => 1] as $type => $expected) {
    if (($counts[$type] ?? 0) !== $expected) verifyFail($type . ' count mismatch (expected ' . $expected . ', got ' . ($counts[$type] ?? 0) . ')');
}
$expectedStatuses = ['pending' => 5, 'confirmed' => 4, 'packing' => 4, 'shipping' => 6, 'delivered' => 6, 'cancelled' => 2];
$statuses = [];
$deliveryStates = [];
$trackingCodes = [];
$legacy = 0;
$failedEvents = 0;
$retryEvents = 0;
foreach ($orders as $order) {
    $id = (string) $order['_id'];
    $status = (string) ($order['status'] ?? '');
    $statuses[$status] = ($statuses[$status] ?? 0) + 1;
    if (!preg_match('/^P06-00[1-6]$/', (string) ($order['customer']['customer_id'] ?? ''))) verifyFail('invalid customer ownership reference in ' . $id);
    $totalQty = 0; $subtotal = 0.0;
    foreach ($order['items'] ?? [] as $item) {
        if ((int) ($item['quantity'] ?? 0) <= 0 || (float) ($item['line_total'] ?? -1) !== (float) ($item['quantity'] * $item['unit_price'])) verifyFail('invalid item quantity or line total in ' . $id);
        $totalQty += (int) $item['quantity']; $subtotal += (float) $item['line_total'];
    }
    $totals = $order['totals'] ?? [];
    if ($totalQty !== (int) ($totals['total_quantity'] ?? -1) || abs($subtotal - (float) ($totals['subtotal'] ?? -1)) > 0.001
        || abs($subtotal + (float) ($totals['shipping_fee'] ?? 0) - (float) ($totals['discount_amount'] ?? 0) - (float) ($totals['grand_total'] ?? -1)) > 0.001) verifyFail('order totals inconsistent in ' . $id);
    $tracking = $order['delivery_tracking'] ?? null;
    if (!is_array($tracking)) {
        if ($status === 'shipping') verifyFail('shipping order is missing tracking ' . $id);
        if ($status === 'delivered') {
            if (($order['meta']['legacy_without_tracking'] ?? false) !== true) verifyFail('delivered untracked order is not marked legacy ' . $id);
            $legacy++;
        } elseif (($order['meta']['legacy_without_tracking'] ?? false) === true) {
            verifyFail('legacy marker is only valid for delivered untracked orders ' . $id);
        }
        continue;
    }
    $code = (string) ($tracking['tracking_code'] ?? '');
    if ($code === '' || isset($trackingCodes[$code])) verifyFail('missing or duplicate tracking code ' . $code);
    $trackingCodes[$code] = true;
    $delivery = (string) ($tracking['status'] ?? '');
    $deliveryStates[$delivery] = ($deliveryStates[$delivery] ?? 0) + 1;
    if (($delivery === 'delivered') !== ($status === 'delivered')) verifyFail('order/delivery terminal state mismatch in ' . $id);
    $history = $tracking['history'] ?? [];
    $previous = 0;
    foreach ($history as $event) {
        $at = strtotime((string) ($event['at'] ?? ''));
        if ($at === false || $at < $previous) verifyFail('delivery history timestamp order invalid in ' . $id);
        $previous = $at;
        if (($event['status'] ?? '') === 'failed_delivery') $failedEvents++;
    }
    for ($i = 0; $i < count($history) - 1; $i++) if (($history[$i]['status'] ?? '') === 'failed_delivery' && in_array(($history[$i + 1]['status'] ?? ''), ['in_transit', 'out_for_delivery'], true)) $retryEvents++;
}
foreach ($expectedStatuses as $state => $expectedCount) if (($statuses[$state] ?? 0) !== $expectedCount) verifyFail('order status distribution mismatch: ' . json_encode($statuses));
if ($legacy !== 2 || $failedEvents < 2 || $retryEvents < 1) verifyFail('required legacy/failed/retry scenarios are incomplete');
foreach (['created', 'picked_up', 'in_transit', 'out_for_delivery', 'failed_delivery', 'delivered'] as $state) if (!isset($deliveryStates[$state])) verifyFail('missing delivery scenario ' . $state);
fwrite(STDOUT, 'PASS: Phase 06 demo data integrity; customer=6 staff=2 product=18 order=27 legacy_untracked=2 failed_events=' . $failedEvents . ' retries=' . $retryEvents . PHP_EOL);
fwrite(STDOUT, 'Order statuses: ' . json_encode($statuses, JSON_UNESCAPED_UNICODE) . PHP_EOL);
fwrite(STDOUT, 'Delivery statuses: ' . json_encode($deliveryStates, JSON_UNESCAPED_UNICODE) . PHP_EOL);

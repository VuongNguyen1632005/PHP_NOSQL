<?php

declare(strict_types=1);

use App\Checkout\CheckoutRepository;
use App\Infrastructure\CouchDB\CouchDbClient;
use App\Orders\OrderWorkflowService;

require dirname(__DIR__) . '/vendor/autoload.php';

function demoFail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
function demoDb(): string
{
    $database = (string) (getenv('PHASE06_DEMO_DATABASE') ?: 'shopquan_ao_phase06_demo_test');
    if (!preg_match('/^[a-z][a-z0-9_$()+\/-]*_phase06_demo_test$/', $database)
        || $database === (string) getenv('COUCHDB_DATABASE')) {
        demoFail('Refusing demo database: it must end with _phase06_demo_test and differ from COUCHDB_DATABASE.');
    }
    return $database;
}
function demoClient(): CouchDbClient
{
    foreach (['COUCHDB_URL', 'COUCHDB_USER', 'COUCHDB_PASSWORD'] as $name) {
        if (trim((string) getenv($name)) === '') demoFail('Missing CouchDB configuration: ' . $name);
    }
    return new CouchDbClient((string) getenv('COUCHDB_URL'), (string) getenv('COUCHDB_USER'), (string) getenv('COUCHDB_PASSWORD'));
}
function requestOk(CouchDbClient $client, string $method, string $path, ?array $body = null, array $codes = [200, 201, 202]): array
{
    $response = $client->request($method, $path, $body);
    if (!in_array($response->statusCode, $codes, true)) {
        $data = json_decode($response->body, true);
        demoFail($method . ' failed for ' . $path . ' (HTTP ' . $response->statusCode . '): ' . (is_array($data) ? (string) ($data['reason'] ?? $data['error'] ?? 'rejected') : 'rejected'));
    }
    return $response->body !== '' ? $response->json() : [];
}
function marker(array $doc): array
{
    $doc['meta'] = array_merge(is_array($doc['meta'] ?? null) ? $doc['meta'] : [], ['phase06_demo' => ['dataset_version' => 1]]);
    return $doc;
}

$database = demoDb();
$client = demoClient();
$path = rawurlencode($database);
$reset = in_array('--reset', $argv, true);
$info = $client->request('GET', $path);
if ($reset) {
    if ($info->statusCode !== 200) demoFail('Cannot reset: demo database does not exist. Run composer demo:seed first.');
    $all = requestOk($client, 'GET', $path . '/_all_docs?include_docs=true&limit=1000');
    foreach ($all['rows'] ?? [] as $row) {
        $doc = $row['doc'] ?? [];
        $id = (string) ($row['id'] ?? '');
        if (str_starts_with($id, '_design/')) {
            if (!in_array($id, ['_design/domain_validation', '_design/catalog_indexes'], true)) demoFail('Reset refused: unknown design document ' . $id . '.');
            continue;
        }
        $demoOwnedCart = ($doc['type'] ?? null) === 'cart'
            && preg_match('/^P06-00[1-6]$/', (string) ($doc['customer_id'] ?? '')) === 1
            && $id === 'cart:' . (string) $doc['customer_id'];
        $demoOwnedCheckoutOrder = ($doc['type'] ?? null) === 'order'
            && str_starts_with($id, 'order:checkout:')
            && preg_match('/^P06-00[1-6]$/', (string) ($doc['customer']['customer_id'] ?? '')) === 1;
        if (($doc['meta']['phase06_demo']['dataset_version'] ?? null) !== 1 && !$demoOwnedCart && !$demoOwnedCheckoutOrder) demoFail('Reset refused: non-demo document found; database left unchanged.');
    }
    requestOk($client, 'DELETE', $path);
    requestOk($client, 'PUT', $path, null, [201, 202]);
} elseif ($info->statusCode === 200) {
    demoFail('Demo database already exists. Use composer demo:seed -- --reset only after the ownership guard passes.');
} elseif ($info->statusCode !== 404) {
    demoFail('Could not inspect demo database (HTTP ' . $info->statusCode . ').');
} else {
    requestOk($client, 'PUT', $path, null, [201, 202]);
}

$validator = json_decode((string) file_get_contents(dirname(__DIR__) . '/database/couchdb/design-docs/domain_validation.json'), true, 512, JSON_THROW_ON_ERROR);
$validator['_id'] = '_design/domain_validation';
requestOk($client, 'PUT', $path . '/_design%2Fdomain_validation', $validator);

$fixture = json_decode((string) file_get_contents(dirname(__DIR__) . '/database/couchdb/seeds/retail_order_delivery.json'), true, 512, JSON_THROW_ON_ERROR);
$products = array_values(array_filter($fixture['docs'], static fn (array $doc): bool => ($doc['type'] ?? '') === 'product' && ($doc['active'] ?? false) === true));
if (count($products) < 18) demoFail('Seed fixture has fewer than 18 active products.');
$docs = [];
foreach (array_slice($products, 0, 18) as $index => $product) {
    $product['_id'] = 'product:P06-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
    $product['legacy_id'] = 'P06-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
    foreach ($product['variants'] as &$variant) $variant['stock'] = 24 + $index;
    unset($variant);
    $docs[] = marker($product);
}
$password = (string) (getenv('PHASE06_DEMO_PASSWORD') ?: ('Demo-' . bin2hex(random_bytes(7)) . '!aA9'));
if (strlen($password) < 12 || preg_match('/[a-z]/', $password) !== 1 || preg_match('/[A-Z]/', $password) !== 1
    || preg_match('/\d/', $password) !== 1 || preg_match('/[^a-zA-Z0-9]/', $password) !== 1) {
    demoFail('Demo password must be at least 12 characters with upper/lowercase letters, a number, and a symbol.');
}
$accounts = [];
for ($i = 1; $i <= 6; $i++) {
    $id = 'customer:P06-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
    $email = 'customer' . $i . '@example.test';
    $accounts[] = marker(['_id' => $id, 'type' => 'customer', 'schema_version' => 2, 'legacy_id' => substr($id, 9),
        'auth' => ['username' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'requires_password_reset' => false],
        'profile' => ['name' => ['An Nguyễn', 'Bình Trần', 'Chi Lê', 'Dũng Phạm', 'Hà Võ', 'Minh Đỗ'][$i - 1], 'phone' => '09000000' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'email' => $email, 'addresses' => []], 'active' => true]);
}
foreach ([['staff:P06-001', 'staff', 'staff.demo@example.test', 'Nhân viên Demo'], ['staff:P06-002', 'manager', 'manager.demo@example.test', 'Quản lý Demo']] as [$id, $role, $username, $name]) {
    $accounts[] = marker(['_id' => $id, 'type' => 'staff', 'schema_version' => 2, 'legacy_id' => substr($id, 6),
        'auth' => ['username' => $username, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'requires_password_reset' => false], 'profile' => ['name' => $name], 'role' => $role, 'active' => true]);
}
array_push($docs, ...$accounts);
$docs[] = marker(['_id' => 'shipping_method:BD', 'type' => 'shipping_method', 'schema_version' => 2, 'code' => 'BD', 'name' => 'Bưu điện', 'fee' => 20000, 'active' => true, 'currency' => 'VND']);
$docs[] = marker(['_id' => 'shipping_method:HT', 'type' => 'shipping_method', 'schema_version' => 2, 'code' => 'HT', 'name' => 'Hỏa tốc', 'fee' => 30000, 'active' => true, 'currency' => 'VND']);
$docs[] = marker(['_id' => 'voucher:SAVE10', 'type' => 'voucher', 'schema_version' => 2, 'code' => 'SAVE10', 'value' => 10, 'discount_type' => 'percent', 'remaining_quantity' => 20, 'active' => true]);

$orderSpecs = array_merge(array_fill(0, 5, 'pending'), array_fill(0, 4, 'confirmed'), array_fill(0, 4, 'packing'), array_fill(0, 10, 'shipping'), array_fill(0, 2, 'delivered'), array_fill(0, 2, 'cancelled'));
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$customerNames = ['An Nguyễn', 'Bình Trần', 'Chi Lê', 'Dũng Phạm', 'Hà Võ', 'Minh Đỗ'];
foreach ($orderSpecs as $index => $status) {
    $number = $index + 1;
    $customerIndex = $index % 6;
    $customerId = 'P06-' . str_pad((string) ($customerIndex + 1), 3, '0', STR_PAD_LEFT);
    $product = $products[$index % 18];
    $variant = $product['variants'][0];
    $qty = ($index % 3) + 1;
    $price = (float) $variant['price'];
    $subtotal = $price * $qty;
    $shipping = 20000;
    $discount = $number % 5 === 0 ? min(10000, $subtotal) : 0;
    $orderedAt = $now->sub(new DateInterval('P' . (30 - $number) . 'D'))->format('Y-m-d\TH:i:s\Z');
    $id = 'order:P06-' . str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    $history = [['status' => 'pending', 'at' => $orderedAt, 'source' => 'phase06_demo']];
    if (in_array($status, ['confirmed', 'packing', 'shipping', 'delivered'], true)) $history[] = ['status' => 'confirmed', 'at' => $orderedAt, 'source' => 'phase06_demo'];
    if (in_array($status, ['packing', 'shipping', 'delivered'], true)) $history[] = ['status' => 'packing', 'at' => $orderedAt, 'source' => 'phase06_demo'];
    if (in_array($status, ['shipping', 'delivered'], true)) $history[] = ['status' => 'shipping', 'at' => $orderedAt, 'source' => 'phase06_demo'];
    if ($status === 'delivered') $history[] = ['status' => 'delivered', 'at' => $orderedAt, 'source' => 'phase06_demo_legacy'];
    if ($status === 'cancelled') $history[] = ['status' => 'cancelled', 'at' => $orderedAt, 'source' => 'phase06_demo'];
    $doc = ['_id' => $id, 'type' => 'order', 'schema_version' => 2, 'legacy_id' => 'P06-' . str_pad((string) $number, 4, '0', STR_PAD_LEFT),
        'customer' => ['customer_id' => $customerId, 'name' => $customerNames[$customerIndex]], 'guest_order' => false,
        'assigned_staff_id' => 'staff:P06-001', 'receiver' => ['name' => $customerNames[$customerIndex], 'phone' => '09000000' . str_pad((string) ($customerIndex + 1), 2, '0', STR_PAD_LEFT), 'address' => ($customerIndex + 1) . ' Đường Demo, Quận 1, TP. Hồ Chí Minh'],
        'items' => [['product_id' => (string) $product['_id'], 'product_name' => (string) $product['name'], 'variant_id' => (string) $variant['variant_id'], 'size' => (string) $variant['size'], 'quantity' => $qty, 'unit_price' => $price, 'line_total' => $subtotal, 'currency' => 'VND']],
        'shipping' => ['method_code' => 'BD', 'method_name' => 'Bưu điện', 'fee' => $shipping], 'discount' => ['amount' => $discount],
        'payment' => ['method' => 'cod', 'legacy_label' => 'Thanh toán khi nhận hàng', 'status' => 'unpaid', 'paid' => false],
        'totals' => ['total_quantity' => $qty, 'subtotal' => $subtotal, 'shipping_fee' => $shipping, 'discount_amount' => $discount, 'grand_total' => $subtotal + $shipping - $discount, 'currency' => 'VND'],
        'status' => $status, 'status_history' => $history, 'note' => '', 'ordered_at' => $orderedAt, 'active' => true];
    if ($status === 'delivered') {
        $doc['payment'] = ['method' => 'cod', 'legacy_label' => 'Thanh toán khi nhận hàng', 'status' => 'paid', 'paid' => true, 'paid_at' => $orderedAt];
        $doc['payment_history'] = [['status' => 'paid', 'at' => $orderedAt, 'source' => 'phase06_demo_legacy', 'customer_id' => $customerId, 'method' => 'cod']];
    }
    if ($status === 'shipping') {
        $trackingCode = 'P06-TRK-' . str_pad((string) $number, 4, '0', STR_PAD_LEFT);
        $doc['delivery_tracking'] = ['tracking_code' => $trackingCode, 'status' => 'created', 'estimated_delivery_date' => $now->add(new DateInterval('P4D'))->format('Y-m-d'), 'delivered_at' => null,
            'history' => [['status' => 'created', 'at' => $orderedAt, 'note' => 'Đã tạo mã vận đơn demo', 'updated_by' => 'staff:P06-001']]];
    }
    if ($status === 'delivered') $doc['meta'] = ['legacy_without_tracking' => true];
    $docs[] = marker($doc);
}

$bulk = requestOk($client, 'POST', $path . '/_bulk_docs', ['docs' => $docs]);
foreach ($bulk as $result) if (($result['ok'] ?? false) !== true) demoFail('CouchDB rejected document ' . ($result['id'] ?? 'unknown') . '.');
$indexScript = dirname(__DIR__) . '/scripts/create_catalog_indexes.php';
$env = getenv();
if (!is_array($env)) demoFail('Could not read process environment.');
$env['COUCHDB_DATABASE'] = $database;
$process = proc_open([PHP_BINARY, $indexScript], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $env);
if (!is_resource($process)) demoFail('Could not create demo indexes.');
fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
if (proc_close($process) !== 0) demoFail('Index setup failed: ' . trim((string) $stderr));

$workflow = new OrderWorkflowService(new CheckoutRepository($client, $database));
$completed = 14; // order:P06-0014 ... P06-0017
for ($number = $completed; $number <= 17; $number++) {
    $id = 'order:P06-' . str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    foreach (['picked_up', 'in_transit', 'out_for_delivery', 'delivered'] as $target) $workflow->updateDeliveryStatus($id, $target, 'staff:P06-001');
}
$targets = [19 => ['picked_up'], 20 => ['picked_up', 'in_transit'], 21 => ['picked_up', 'in_transit', 'out_for_delivery'], 22 => ['picked_up', 'in_transit', 'out_for_delivery', 'failed_delivery'], 23 => ['picked_up', 'in_transit', 'out_for_delivery', 'failed_delivery', 'in_transit']];
foreach ($targets as $number => $steps) foreach ($steps as $target) $workflow->updateDeliveryStatus('order:P06-' . str_pad((string) $number, 4, '0', STR_PAD_LEFT), $target, 'staff:P06-001');

$credentialDir = dirname(__DIR__) . '/var';
if (!is_dir($credentialDir) && !mkdir($credentialDir, 0770, true) && !is_dir($credentialDir)) demoFail('Could not create local credential directory.');
$lines = ['PHASE 06 DEMO ACCOUNTS (local only; not for Git)', 'Shared generated password: ' . $password, 'Customers: customer1@example.test through customer6@example.test', 'Staff: staff.demo@example.test', 'Manager: manager.demo@example.test', 'Database: ' . $database, ''];
file_put_contents($credentialDir . '/phase06_demo_credentials.txt', implode(PHP_EOL, $lines), LOCK_EX);
fwrite(STDOUT, 'PASS: isolated demo dataset created in ' . $database . ' (6 customers, 2 staff, 18 products, 27 orders). Credentials saved locally and ignored by Git.' . PHP_EOL);

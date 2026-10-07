<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Checkout\CheckoutRepository;
use App\Catalog\ProductRepository;
use App\Catalog\ProductAdminService;
use App\Catalog\ProductImageStorage;
use App\Reviews\ReviewRepository;
use App\Infrastructure\CouchDB\CouchDbClient;

$source = getenv('COUCHDB_DATABASE') ?: '';
if (!str_ends_with($source, '_test')) {
    fwrite(STDERR, "Refusing to run HTTP checkout smoke test unless COUCHDB_DATABASE ends in _test.\n");
    exit(2);
}
$client = new CouchDbClient(getenv('COUCHDB_URL') ?: '', getenv('COUCHDB_USER') ?: '', getenv('COUCHDB_PASSWORD') ?: '');
$database = 'retail_http_checkout_' . bin2hex(random_bytes(5));
$databasePath = rawurlencode($database);
$created = false;
$server = null;
$serverLog = '/tmp/http-checkout-smoke-' . bin2hex(random_bytes(6)) . '.log';
$uploadPath = '/tmp/product-upload-smoke-' . bin2hex(random_bytes(6)) . '.png';
$uploadedMediaFilename = null;

try {
    $response = $client->request('PUT', $databasePath);
    if ($response->statusCode < 200 || $response->statusCode >= 300) throw new RuntimeException('Could not create isolated HTTP smoke database.');
    $created = true;

    foreach (['_design/domain_validation'] as $id) {
        $document = $client->request('GET', rawurlencode($source) . '/' . $id)->json();
        unset($document['_rev']);
        $response = $client->request('PUT', $databasePath . '/' . $id, $document);
        if ($response->statusCode < 200 || $response->statusCode >= 300) throw new RuntimeException('Could not copy test design document.');
    }
    foreach ([
        ['active_products', ['type', 'active'], ['type' => 'product', 'active' => true]],
        ['admin_products', ['type'], ['type' => 'product']],
        ['active_reviews', ['type', 'active'], ['type' => 'review', 'active' => true]],
        ['admin_reviews', ['type'], ['type' => 'review']],
        ['account_usernames', ['type', 'auth.username'], ['type' => ['$in' => ['customer', 'staff']]]],
        ['customer_orders', ['type', 'customer.customer_id', 'ordered_at'], ['type' => 'order']],
        ['admin_orders', ['type', 'status', 'ordered_at'], ['type' => 'order']],
        ['admin_orders_by_date', ['type', 'ordered_at'], ['type' => 'order']],
        ['delivery_tracking_code', ['type', 'delivery_tracking.tracking_code'], ['type' => 'order']],
    ] as [$name, $fields, $partial]) {
        $response = $client->request('POST', $databasePath . '/_index', [
            'index' => ['fields' => $fields, 'partial_filter_selector' => $partial], 'ddoc' => '_design/catalog_indexes', 'name' => $name, 'type' => 'json',
        ]);
        if ($response->statusCode < 200 || $response->statusCode >= 300) throw new RuntimeException('Could not create HTTP test Mango index.');
    }
    $manager = [
        '_id' => 'staff:HTTP-SMOKE-MANAGER', 'type' => 'staff', 'schema_version' => 2,
        'legacy_id' => 'HTTP-SMOKE-MANAGER', 'role' => 'manager', 'active' => true,
        'auth' => ['username' => 'http-manager@example.test', 'password_hash' => password_hash('Smoke-Manager-2026!', PASSWORD_DEFAULT), 'requires_password_reset' => false],
        'profile' => ['name' => 'HTTP Smoke Manager', 'email' => 'http-manager@example.test', 'phone' => '0912345678'],
    ];
    $response = $client->request('PUT', $databasePath . '/' . rawurlencode($manager['_id']), $manager);
    if ($response->statusCode < 200 || $response->statusCode >= 300) throw new RuntimeException('Could not create isolated manager account.');
    $resetCustomer = [
        '_id' => 'customer:HTTP-RESET-CUSTOMER', 'type' => 'customer', 'schema_version' => 2,
        'legacy_id' => 'HTTP-RESET-CUSTOMER', 'active' => true,
        'auth' => ['username' => 'http-reset@example.test', 'password_hash' => password_hash('Temporary-Reset-2026!', PASSWORD_DEFAULT), 'requires_password_reset' => true],
        'profile' => ['name' => 'HTTP Reset Customer', 'email' => 'http-reset@example.test', 'phone' => '', 'addresses' => []],
        'meta' => [],
    ];
    $response = $client->request('PUT', $databasePath . '/' . rawurlencode($resetCustomer['_id']), $resetCustomer);
    if ($response->statusCode < 200 || $response->statusCode >= 300) throw new RuntimeException('Could not create isolated password-reset customer.');

    $sourceDocuments = new CheckoutRepository($client, $source);
    $product = $sourceDocuments->activeDocuments('product')[0] ?? null;
    if (!is_array($product)) throw new RuntimeException('No active fixture product is available.');
    $variant = null;
    foreach ($product['variants'] ?? [] as $candidate) {
        if (($candidate['active'] ?? false) === true && (int) ($candidate['stock'] ?? 0) > 0) { $variant = $candidate; break; }
    }
    if (!is_array($variant)) throw new RuntimeException('No in-stock fixture variant is available.');
    $shipping = $sourceDocuments->get('shipping_method:BD');
    if (!is_array($shipping)) throw new RuntimeException('Default shipping fixture is missing.');
    foreach ([$product, $shipping] as $document) {
        unset($document['_rev']);
        $response = $client->request('PUT', $databasePath . '/' . rawurlencode((string) $document['_id']), $document);
        if ($response->statusCode < 200 || $response->statusCode >= 300) throw new RuntimeException('Could not copy HTTP test commerce fixture.');
    }
    sleep(2);

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!is_resource($socket)) throw new RuntimeException('Could not reserve a local port for the HTTP smoke server.');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    if (!is_string($address) || !preg_match('/:(\d+)$/', $address, $portMatch)) throw new RuntimeException('Could not determine the local HTTP smoke port.');
    $port = (int) $portMatch[1];
    $environment = getenv();
    if (!is_array($environment)) $environment = [];
    $environment['COUCHDB_DATABASE'] = $database;
    $environment['ORDER_SSE_CHANGES_TIMEOUT_MS'] = '1000';
    $environment['PHP_CLI_SERVER_WORKERS'] = '4';
    $public = dirname(__DIR__) . '/public';
    $server = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $public, $public . '/index.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']],
        $pipes,
        dirname(__DIR__),
        $environment,
    );
    if (!is_resource($server)) throw new RuntimeException('Could not start isolated PHP HTTP smoke server.');
    $ready = false;
    for ($attempt = 0; $attempt < 50; ++$attempt) {
        $serverStatus = proc_get_status($server);
        if (!$serverStatus['running']) break;
        $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $probeError, $probeErrorNumber, 0.1);
        if (is_resource($probe)) {
            fclose($probe);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (!$ready) throw new RuntimeException('Isolated PHP HTTP server did not start: ' . (string) @file_get_contents($serverLog));

    $base = 'http://127.0.0.1:' . $port;
    $cookie = '';
    $http = static function (string $method, string $path, array $form = [], ?string $filePath = null) use ($base, &$cookie): array {
        $handle = curl_init($base . $path);
        $responseHeaders = [];
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Accept: text/html'],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders, &$cookie): int {
                $length = strlen($line);
                $trimmed = trim($line);
                if (stripos($trimmed, 'Set-Cookie: PHPSESSID=') === 0 && preg_match('/PHPSESSID=([^;]+)/', $trimmed, $matches)) $cookie = 'PHPSESSID=' . $matches[1];
                if (stripos($trimmed, 'Location:') === 0) $responseHeaders['location'] = trim(substr($trimmed, strlen('Location:')));
                if (stripos($trimmed, 'Content-Type:') === 0) $responseHeaders['content-type'] = trim(substr($trimmed, strlen('Content-Type:')));
                return $length;
            },
        ]);
        if ($cookie !== '') curl_setopt($handle, CURLOPT_COOKIE, $cookie);
        if ($method === 'POST') {
            if ($filePath !== null) {
                $form['image'] = new CURLFile($filePath, 'image/png', 'uploaded-product.png');
                curl_setopt($handle, CURLOPT_POSTFIELDS, $form);
                curl_setopt($handle, CURLOPT_HTTPHEADER, ['Accept: text/html']);
            } else {
                curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($form));
                curl_setopt($handle, CURLOPT_HTTPHEADER, ['Accept: text/html', 'Content-Type: application/x-www-form-urlencoded']);
            }
        }
        $body = curl_exec($handle);
        if (!is_string($body)) {
            $message = curl_error($handle);
            curl_close($handle);
            throw new RuntimeException('HTTP smoke request failed: ' . $message);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $body];
    };
    $assertCleanHtml = static function (array $response, string $pageName): void {
        if ($response['status'] !== 200 || stripos((string) ($response['headers']['content-type'] ?? ''), 'text/html') === false) {
            throw new RuntimeException($pageName . ' did not return an HTML page.');
        }
        if (preg_match('/(?:Warning|Notice|Deprecated|Fatal error|Undefined (?:variable|array key)|Array to string conversion):/i', $response['body'])) {
            throw new RuntimeException($pageName . ' rendered a PHP warning or undefined value.');
        }
    };
    $apiHttp = static function (string $method, string $path, ?string $token = null, ?array $jsonBody = null, array $extraHeaders = []) use ($base): array {
        $headers = ['Accept: application/json'];
        if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
        if ($jsonBody !== null) $headers[] = 'Content-Type: application/json';
        foreach ($extraHeaders as $name => $value) $headers[] = $name . ': ' . $value;
        $handle = curl_init($base . $path);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($jsonBody !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($jsonBody, JSON_THROW_ON_ERROR));
        $body = curl_exec($handle);
        if (!is_string($body)) {
            $message = curl_error($handle);
            curl_close($handle);
            throw new RuntimeException('JSON API smoke request failed: ' . $message);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        $decoded = json_decode($body, true);
        return ['status' => $status, 'body' => $body, 'json' => is_array($decoded) ? $decoded : null];
    };
    $verifyLiveSseUpdate = static function (string $path, string $sessionCookie, string $initialStatus, string $updatedStatus, callable $update) use ($base): array {
        $streamBody = '';
        $streamHeaders = [];
        $hasState = static function (string $body, string $status): bool {
            preg_match_all('/^data: (.+)$/m', $body, $matches);
            foreach (array_reverse($matches[1] ?? []) as $json) {
                $payload = json_decode((string) $json, true);
                if (is_array($payload) && (($payload['status'] ?? null) === $status || ($payload['delivery']['status'] ?? null) === $status)) return true;
            }
            return false;
        };
        $handle = curl_init($base . $path);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => ['Accept: text/event-stream', 'Cookie: ' . $sessionCookie],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$streamHeaders): int {
                $length = strlen($line);
                if (stripos(trim($line), 'Content-Type:') === 0) $streamHeaders['content-type'] = trim(substr(trim($line), strlen('Content-Type:')));
                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$streamBody): int {
                $streamBody .= $chunk;
                return strlen($chunk);
            },
        ]);
        $multi = curl_multi_init();
        curl_multi_add_handle($multi, $handle);
        $running = 0;
        $deadline = microtime(true) + 5;
        do {
            curl_multi_exec($multi, $running);
            if ($hasState($streamBody, $initialStatus)) break;
            if ($running > 0) {
                $selected = curl_multi_select($multi, 0.1);
                if ($selected === -1) usleep(10000);
            }
        } while ($running > 0 && microtime(true) < $deadline);
        if (!$hasState($streamBody, $initialStatus)) {
            $debugStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $debugError = curl_error($handle);
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
            curl_multi_close($multi);
            throw new RuntimeException('SSE stream did not flush its initial authorized snapshot (HTTP ' . $debugStatus . ', ' . $debugError . ', body ' . substr($streamBody, 0, 300) . ').');
        }

        $updateResponse = $update();
        $deadline = microtime(true) + 8;
        do {
            curl_multi_exec($multi, $running);
            if ($hasState($streamBody, $updatedStatus) && substr_count($streamBody, 'event: order_updated') >= 2) break;
            if ($running > 0) {
                $selected = curl_multi_select($multi, 0.1);
                if ($selected === -1) usleep(10000);
            }
        } while ($running > 0 && microtime(true) < $deadline);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
        curl_multi_close($multi);
        return ['status' => $status, 'headers' => $streamHeaders, 'body' => $streamBody, 'update' => $updateResponse];
    };

    $readiness = $http('GET', '/health/ready');
    $readinessBody = json_decode($readiness['body'], true);
    if ($readiness['status'] !== 200
        || !is_array($readinessBody)
        || ($readinessBody['status'] ?? null) !== 'ready'
        || ($readinessBody['dependency'] ?? null) !== 'couchdb') {
        throw new RuntimeException('CouchDB readiness endpoint did not report the configured test database as ready.');
    }
    $anonymousCartApi = $apiHttp('GET', '/api/v1/cart');
    if ($anonymousCartApi['status'] !== 401) throw new RuntimeException('Customer cart API did not require a Bearer token.');
    $unknownApi = $apiHttp('GET', '/api/v1/unknown-endpoint');
    if ($unknownApi['status'] !== 404 || !is_array($unknownApi['json']) || !isset($unknownApi['json']['error'])) {
        throw new RuntimeException('Unknown API route did not return a JSON 404 response.');
    }
    $aboutPage = $http('GET', '/about');
    if ($aboutPage['status'] !== 200 || !str_contains($aboutPage['body'], 'CÂU CHUYỆN CỦA CHÚNG TÔI')
        || !str_contains($aboutPage['body'], '10.8061539%2C106.6237947')) {
        throw new RuntimeException('About page did not render its migrated content and contact location.');
    }
    if (!str_contains($aboutPage['body'], '/assets/img/about/about-1-2.jpg')
        || !is_file(dirname(__DIR__) . '/public/assets/img/about/about-1-2.jpg')) {
        throw new RuntimeException('About page image reference or migrated image file is missing.');
    }

    $productPage = $http('GET', '/products/' . rawurlencode((string) $product['legacy_id']));
    if ($productPage['status'] !== 200
        || !preg_match('/name="csrf_token"\s+value="([^"]+)"/', $productPage['body'], $csrfMatch)
        || !preg_match('/<select[^>]*name="variant_id"[^>]*>(.*?)<\/select>/s', $productPage['body'], $selectMatch)
        || !preg_match('/<option value="([^"]+)"/', $selectMatch[1], $variantMatch)) {
        throw new RuntimeException('Could not load a product page or extract its cart form.');
    }
    $addResponse = $http('POST', '/cart/items/add', [
        'csrf_token' => html_entity_decode($csrfMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'product_id' => (string) $product['legacy_id'],
        'variant_id' => html_entity_decode($variantMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'quantity' => '1',
    ]);
    if (!in_array($addResponse['status'], [302, 303], true)) throw new RuntimeException('HTTP add-to-cart did not redirect after success.');

    $cartBeforeCheckout = $http('GET', '/cart');
    if ($cartBeforeCheckout['status'] !== 200 || !preg_match('/name="csrf_token"\s+value="([^"]+)"/', $cartBeforeCheckout['body'], $cartCsrf)) {
        throw new RuntimeException('Could not load the cart page after adding the product.');
    }
    $selectResponse = $http('POST', '/cart/items/select', [
        'csrf_token' => html_entity_decode($cartCsrf[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'variant_id' => html_entity_decode($variantMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'selected' => '1',
    ]);
    if (!in_array($selectResponse['status'], [302, 303], true)) throw new RuntimeException('HTTP cart selection did not save.');

    $bulkSelectResponse = $http('POST', '/cart/items/select-all', [
        'csrf_token' => html_entity_decode($cartCsrf[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'selected' => '1',
    ]);
    if (!in_array($bulkSelectResponse['status'], [302, 303], true)) throw new RuntimeException('HTTP select-all did not save.');
    $selectedCartPage = $http('GET', '/cart');
    if ($selectedCartPage['status'] !== 200 || !preg_match('/<input[^>]*checked[^>]*aria-label="Chọn tất cả sản phẩm"/', $selectedCartPage['body'])) {
        throw new RuntimeException('HTTP select-all did not mark every available cart item as selected.');
    }
    $bulkDeselectResponse = $http('POST', '/cart/items/select-all', [
        'csrf_token' => html_entity_decode($cartCsrf[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
    ]);
    if (!in_array($bulkDeselectResponse['status'], [302, 303], true)) throw new RuntimeException('HTTP deselect-all did not save.');
    $deselectedCartPage = $http('GET', '/cart');
    if ($deselectedCartPage['status'] !== 200 || preg_match('/<input[^>]*checked[^>]*aria-label="Chọn tất cả sản phẩm"/', $deselectedCartPage['body'])) {
        throw new RuntimeException('HTTP deselect-all left the cart header checkbox checked.');
    }
    $selectResponse = $http('POST', '/cart/items/select', [
        'csrf_token' => html_entity_decode($cartCsrf[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'variant_id' => html_entity_decode($variantMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'selected' => '1',
    ]);
    if (!in_array($selectResponse['status'], [302, 303], true)) throw new RuntimeException('HTTP cart selection did not save after bulk selection checks.');

    $checkoutPage = $http('GET', '/checkout');
    if ($checkoutPage['status'] !== 200
        || !preg_match('/name="csrf_token"\s+value="([^"]+)"/', $checkoutPage['body'], $checkoutCsrf)
        || !preg_match('/name="checkout_token"\s+value="([a-f0-9]{32})"/', $checkoutPage['body'], $checkoutToken)) {
        throw new RuntimeException('Could not load the checkout page or extract its protected form tokens.');
    }
    $submit = $http('POST', '/checkout', [
        'csrf_token' => html_entity_decode($checkoutCsrf[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'checkout_token' => $checkoutToken[1],
        'name' => 'HTTP Smoke Buyer',
        'phone' => '0912345678',
        'email' => 'http-smoke@example.test',
        'address' => 'Test Street',
        'note' => 'HTTP integration smoke',
        'shipping_code' => 'BD',
        'voucher_code' => '',
        'payment_method' => 'cod',
    ]);
    if ($submit['status'] !== 303 || ($submit['headers']['location'] ?? null) !== '/cart') {
        throw new RuntimeException('HTTP checkout POST did not complete and redirect to the cart.');
    }
    $cartPage = $http('GET', '/cart');
    if ($cartPage['status'] !== 200 || !str_contains($cartPage['body'], 'Đặt hàng thành công')) {
        throw new RuntimeException('HTTP cart response did not show the successful order flash.');
    }
    $guestOrdersPage = $http('GET', '/orders');
    $assertCleanHtml($guestOrdersPage, 'Guest order history');
    if ($guestOrdersPage['status'] !== 200 || !str_contains($guestOrdersPage['body'], 'Đơn đã đặt trên trình duyệt này')
        || !preg_match('/href="\/orders\/([^"?]+)"[^>]*>Xem chi tiết/', $guestOrdersPage['body'], $guestOrderLink)) {
        throw new RuntimeException('Guest order history did not show the order owned by the current session.');
    }
    $guestOrderDetail = $http('GET', '/orders/' . $guestOrderLink[1]);
    $assertCleanHtml($guestOrderDetail, 'Guest order detail');
    if ($guestOrderDetail['status'] !== 200 || !str_contains($guestOrderDetail['body'], 'http-smoke@example.test')
        || !str_contains($guestOrderDetail['body'], 'Đơn hàng chưa có thông tin theo dõi giao hàng.')) {
        throw new RuntimeException('Guest order detail did not render for the owning session.');
    }
    $guestOrderEvents = $http('GET', '/orders/' . $guestOrderLink[1] . '/events');
    if ($guestOrderEvents['status'] !== 200
        || stripos((string) ($guestOrderEvents['headers']['content-type'] ?? ''), 'text/event-stream') === false
        || !str_contains($guestOrderEvents['body'], 'event: order_updated')
        || !str_contains($guestOrderEvents['body'], 'event: heartbeat')
        || !str_contains($guestOrderEvents['body'], rawurldecode($guestOrderLink[1]))
        || str_contains($guestOrderEvents['body'], 'updated_by')
        || str_contains($guestOrderEvents['body'], 'assigned_staff_id')) {
        throw new RuntimeException('Owned guest SSE stream did not return a customer-safe snapshot and bounded heartbeat.');
    }
    $foreignGuestHandle = curl_init($base . '/orders/' . $guestOrderLink[1]);
    curl_setopt_array($foreignGuestHandle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10]);
    $foreignGuestBody = curl_exec($foreignGuestHandle);
    $foreignGuestStatus = (int) curl_getinfo($foreignGuestHandle, CURLINFO_RESPONSE_CODE);
    curl_close($foreignGuestHandle);
    if (!is_string($foreignGuestBody) || $foreignGuestStatus !== 404) {
        throw new RuntimeException('A separate browser session could access a guest order detail.');
    }
    $foreignGuestEventsHandle = curl_init($base . '/orders/' . $guestOrderLink[1] . '/events');
    curl_setopt_array($foreignGuestEventsHandle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10]);
    $foreignGuestEventsBody = curl_exec($foreignGuestEventsHandle);
    $foreignGuestEventsStatus = (int) curl_getinfo($foreignGuestEventsHandle, CURLINFO_RESPONSE_CODE);
    curl_close($foreignGuestEventsHandle);
    if (!is_string($foreignGuestEventsBody) || $foreignGuestEventsStatus !== 404) {
        throw new RuntimeException('A separate browser session could subscribe to a guest order SSE stream.');
    }

    $testDocuments = new CheckoutRepository($client, $database);
    $createdOrders = array_values(array_filter($testDocuments->ordersForStaff('pending'), static fn (array $doc): bool => ($doc['receiver']['email'] ?? null) === 'http-smoke@example.test'));
    if (count($createdOrders) !== 1 || ($createdOrders[0]['payment']['status'] ?? null) !== 'unpaid'
        || ($createdOrders[0]['meta']['checkout']['phase'] ?? null) !== 'completed') {
        throw new RuntimeException('HTTP checkout did not persist exactly one completed unpaid COD order.');
    }
    $guestOrderId = (string) $createdOrders[0]['_id'];
    $guestOrderForDelivery = $testDocuments->get($guestOrderId);
    if ($guestOrderForDelivery === null) throw new RuntimeException('Could not reload the owned guest order for delivery confirmation.');
    $guestOrderForDelivery['status'] = 'shipping';
    $guestOrderForDelivery['status_history'][] = ['status' => 'shipping', 'at' => gmdate('c'), 'source' => 'http_smoke_delivery'];
    $shippingWrite = $testDocuments->put($guestOrderForDelivery);
    if ($shippingWrite->statusCode < 200 || $shippingWrite->statusCode >= 300) throw new RuntimeException('Could not stage the guest order for delivery confirmation.');
    $guestDeliveryPath = '/orders/' . rawurlencode($guestOrderId);
    $guestDeliveryPage = $http('GET', $guestDeliveryPath);
    if ($guestDeliveryPage['status'] !== 200 || !str_contains($guestDeliveryPage['body'], 'Tôi đã nhận hàng')
        || !str_contains($guestDeliveryPage['body'], 'Đơn hàng chưa có thông tin theo dõi giao hàng.')
        || str_contains($guestDeliveryPage['body'], 'Đã tạo vận đơn')
        || !preg_match('/name="csrf_token"\s+value="([^"]+)"/', $guestDeliveryPage['body'], $guestDeliveryCsrf)) {
        throw new RuntimeException('Owned guest could not load the delivery confirmation action.');
    }
    $guestDeliveryToken = html_entity_decode($guestDeliveryCsrf[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $guestDeliveryAction = $guestDeliveryPath . '/confirm-received';
    $invalidGuestDelivery = $http('POST', $guestDeliveryAction, ['csrf_token' => 'invalid-token']);
    $stillShippingGuestOrder = $testDocuments->get($guestOrderId);
    if ($invalidGuestDelivery['status'] !== 303 || ($stillShippingGuestOrder['status'] ?? null) !== 'shipping') {
        throw new RuntimeException('Guest delivery confirmation accepted an invalid CSRF token.');
    }
    $confirmGuestDelivery = $http('POST', $guestDeliveryAction, ['csrf_token' => $guestDeliveryToken]);
    $deliveredGuestOrder = $testDocuments->get($guestOrderId);
    $guestDeliveryEvents = array_values(array_filter($deliveredGuestOrder['status_history'] ?? [], static fn (mixed $event): bool => is_array($event) && ($event['source'] ?? null) === 'php_guest_confirm_received'));
    $guestPaymentEvents = array_values(array_filter($deliveredGuestOrder['payment_history'] ?? [], static fn (mixed $event): bool => is_array($event) && ($event['source'] ?? null) === 'php_guest_confirm_received'));
    if ($confirmGuestDelivery['status'] !== 303 || ($deliveredGuestOrder['status'] ?? null) !== 'delivered'
        || ($deliveredGuestOrder['payment']['paid'] ?? false) !== true || count($guestDeliveryEvents) !== 1 || count($guestPaymentEvents) !== 1) {
        throw new RuntimeException('Guest delivery confirmation did not mark delivery/payment and audit the action.');
    }
    $repeatGuestDelivery = $http('POST', $guestDeliveryAction, ['csrf_token' => $guestDeliveryToken]);
    $deliveredGuestAgain = $testDocuments->get($guestOrderId);
    $guestEventsAgain = array_values(array_filter($deliveredGuestAgain['status_history'] ?? [], static fn (mixed $event): bool => is_array($event) && ($event['source'] ?? null) === 'php_guest_confirm_received'));
    if ($repeatGuestDelivery['status'] !== 303 || count($guestEventsAgain) !== 1) throw new RuntimeException('Repeated guest delivery confirmation duplicated the status audit.');
    $productAfter = $testDocuments->get((string) $product['_id']);
    $stockAfter = null;
    foreach ($productAfter['variants'] ?? [] as $candidate) if (($candidate['variant_id'] ?? null) === $variant['variant_id']) $stockAfter = (int) $candidate['stock'];
    if ($stockAfter !== (int) $variant['stock'] - 1) throw new RuntimeException('HTTP checkout did not decrement stock exactly once.');

    $reviewPage = $http('GET', '/products/' . rawurlencode((string) $product['legacy_id']));
    if ($reviewPage['status'] !== 200 || !preg_match_all('/name="csrf_token"\s+value="([^"]+)"/', $reviewPage['body'], $reviewCsrfMatches)
        || $reviewCsrfMatches[1] === []) throw new RuntimeException('Could not load the product review form.');
    $reviewToken = html_entity_decode((string) end($reviewCsrfMatches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $reviewText = 'Great fit <script>alert(1)</script> & comfortable';
    $invalidReview = $http('POST', '/products/' . rawurlencode((string) $product['legacy_id']) . '/reviews', [
        'csrf_token' => 'invalid-token', 'reviewer_name' => 'HTTP Reviewer', 'rating' => '4', 'content' => $reviewText,
    ]);
    if ($invalidReview['status'] !== 403) throw new RuntimeException('Review POST accepted an invalid CSRF token.');
    $reviewSubmit = $http('POST', '/products/' . rawurlencode((string) $product['legacy_id']) . '/reviews', [
        'csrf_token' => $reviewToken,
        'reviewer_name' => 'HTTP Reviewer',
        'rating' => '4',
        'content' => $reviewText,
    ]);
    if ($reviewSubmit['status'] !== 303) throw new RuntimeException('HTTP review POST did not redirect after submission.');
    $reviewPage = $http('GET', '/products/' . rawurlencode((string) $product['legacy_id']));
    if (!str_contains($reviewPage['body'], '&lt;script&gt;alert(1)&lt;/script&gt;') || str_contains($reviewPage['body'], $reviewText)) {
        throw new RuntimeException('Submitted review content was not escaped in the product page.');
    }
    $activeProductReviews = array_values(array_filter((new ProductRepository($client, $database))->activeReviews(), static fn (array $review): bool => ($review['product_id'] ?? null) === $product['legacy_id']));
    if (count($activeProductReviews) !== 1 || ($activeProductReviews[0]['content'] ?? null) !== $reviewText) {
        throw new RuntimeException('HTTP review POST did not persist exactly one review document.');
    }
    preg_match_all('/name="csrf_token"\s+value="([^"]+)"/', $reviewPage['body'], $reviewCsrfMatches);
    $duplicateSubmit = $http('POST', '/products/' . rawurlencode((string) $product['legacy_id']) . '/reviews', [
        'csrf_token' => html_entity_decode((string) end($reviewCsrfMatches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'reviewer_name' => 'HTTP Reviewer',
        'rating' => '4',
        'content' => $reviewText,
    ]);
    $duplicatePage = $http('GET', '/products/' . rawurlencode((string) $product['legacy_id']));
    $afterDuplicateReviews = array_values(array_filter((new ProductRepository($client, $database))->activeReviews(), static fn (array $review): bool => ($review['product_id'] ?? null) === $product['legacy_id']));
    if ($duplicateSubmit['status'] !== 303 || count($afterDuplicateReviews) !== 1 || !str_contains($duplicatePage['body'], 'đã gửi đánh giá')) {
        throw new RuntimeException('Duplicate review submission was not rejected idempotently.');
    }

    $resetLoginPage = $http('GET', '/login');
    if ($resetLoginPage['status'] !== 200 || !preg_match('/name="csrf_token"\s+value="([^"]+)"/', $resetLoginPage['body'], $resetLoginCsrf)) throw new RuntimeException('Could not load customer reset login form.');
    $resetLogin = $http('POST', '/login', [
        'csrf_token' => html_entity_decode($resetLoginCsrf[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'email' => 'http-reset@example.test', 'password' => 'Temporary-Reset-2026!',
    ]);
    if ($resetLogin['status'] !== 303 || ($resetLogin['headers']['location'] ?? null) !== '/account/password') throw new RuntimeException('Temporary customer password did not require an immediate password change.');
    $blockedPage = $http('GET', '/admin/reviews');
    if ($blockedPage['status'] !== 303 || ($blockedPage['headers']['location'] ?? null) !== '/account/password') throw new RuntimeException('Password-reset customer was not restricted to the password-change flow.');
    $changePage = $http('GET', '/account/password');
    if ($changePage['status'] !== 200 || !str_contains($changePage['body'], 'đổi mật khẩu trước')
        || !preg_match('/name="csrf_token"\s+value="([^"]+)"/', $changePage['body'], $changeCsrf)) throw new RuntimeException('Required password-change form did not load.');
    $changeToken = html_entity_decode($changeCsrf[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $wrongCurrent = $http('POST', '/account/password', [
        'csrf_token' => $changeToken, 'current_password' => 'Wrong-Temporary-2026!',
        'new_password' => 'Customer-Changed-2026!', 'confirm_password' => 'Customer-Changed-2026!',
    ]);
    if ($wrongCurrent['status'] !== 422 || !str_contains($wrongCurrent['body'], 'Mật khẩu hiện tại không chính xác')) throw new RuntimeException('Password change accepted an incorrect current password.');
    $changePassword = $http('POST', '/account/password', [
        'csrf_token' => $changeToken, 'current_password' => 'Temporary-Reset-2026!',
        'new_password' => 'Customer-Changed-2026!', 'confirm_password' => 'Customer-Changed-2026!',
    ]);
    if ($changePassword['status'] !== 303 || ($changePassword['headers']['location'] ?? null) !== '/account/orders') throw new RuntimeException('Customer password change did not complete.');
    $ordersAfterChange = $http('GET', '/account/orders');
    if ($ordersAfterChange['status'] !== 200) throw new RuntimeException('Customer remained blocked after changing the temporary password.');
    $storedResetCustomer = $client->request('GET', $databasePath . '/' . rawurlencode($resetCustomer['_id']))->json();
    if (($storedResetCustomer['auth']['requires_password_reset'] ?? true) !== false
        || !password_verify('Customer-Changed-2026!', (string) ($storedResetCustomer['auth']['password_hash'] ?? ''))) {
        throw new RuntimeException('Customer password change was not safely persisted.');
    }
    $customerRevenueApi = $http('GET', '/admin/revenue/stats');
    if ($customerRevenueApi['status'] !== 403) throw new RuntimeException('Customer could access the revenue API.');

    $loginPage = $http('GET', '/login');
    if ($loginPage['status'] !== 200 || !preg_match('/name="csrf_token"\s+value="([^"]+)"/', $loginPage['body'], $loginCsrf)) throw new RuntimeException('Could not load manager login form.');
    $login = $http('POST', '/login', [
        'csrf_token' => html_entity_decode($loginCsrf[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'email' => 'http-manager@example.test', 'password' => 'Smoke-Manager-2026!',
    ]);
    if ($login['status'] !== 303) throw new RuntimeException('Isolated manager login failed.');
    $revenuePage = $http('GET', '/admin/revenue');
    if ($revenuePage['status'] !== 200 || !str_contains($revenuePage['body'], 'Báo cáo doanh thu')
        || !str_contains($revenuePage['body'], 'Đơn đã giao gần đây')) throw new RuntimeException('Manager could not load the revenue report.');
    $revenueApi = $http('GET', '/admin/revenue/stats');
    $revenueStats = json_decode($revenueApi['body'], true);
    if ($revenueApi['status'] !== 200 || !is_array($revenueStats)
        || ($revenueStats['revenue_vnd'] ?? null) !== (int) $deliveredGuestOrder['totals']['grand_total']
        || ($revenueStats['delivered_orders'] ?? null) !== 1
        || ($revenueStats['processing_orders'] ?? null) !== 0
        || ($revenueStats['total_orders'] ?? null) !== 1) {
        throw new RuntimeException('Revenue API metrics were inconsistent with the confirmed guest delivery.');
    }
    $paginationOrders = [];
    for ($index = 1; $index <= 501; ++$index) {
        $paginationOrders[] = [
            '_id' => 'order:REPORT-PAGE-' . str_pad((string) $index, 4, '0', STR_PAD_LEFT),
            'type' => 'order', 'schema_version' => 2, 'legacy_id' => 'REPORT-PAGE-' . $index,
            'status' => 'pending', 'ordered_at' => gmdate('c', time() - $index),
            'receiver' => ['name' => 'Report Page Fixture', 'phone' => '0900000000', 'address' => 'Test'],
            'items' => [['product_id' => 'REPORT', 'variant_id' => 'REPORT-S', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]],
            'totals' => ['total_quantity' => 1, 'subtotal' => 100, 'shipping_fee' => 0, 'discount_amount' => 0, 'grand_total' => 100],
            'status_history' => [],
        ];
    }
    $bulkResponse = $client->request('POST', $databasePath . '/_bulk_docs', ['docs' => $paginationOrders]);
    $bulkRows = $bulkResponse->json();
    if ($bulkResponse->statusCode < 200 || $bulkResponse->statusCode >= 300 || !is_array($bulkRows)
        || count($bulkRows) !== 501 || array_filter($bulkRows, static fn (mixed $row): bool => is_array($row) && isset($row['error'])) !== []) {
        throw new RuntimeException('Could not create the revenue pagination fixture batch.');
    }
    $pagedRevenueApi = $http('GET', '/admin/revenue/stats');
    $pagedStats = json_decode($pagedRevenueApi['body'], true);
    if ($pagedRevenueApi['status'] !== 200 || !is_array($pagedStats)
        || ($pagedStats['total_orders'] ?? null) !== 502
        || ($pagedStats['processing_orders'] ?? null) !== 501) {
        throw new RuntimeException('Revenue report stopped before reading all Mango bookmark pages.');
    }
    $adminOrderPage = $http('GET', '/admin/orders');
    $assertCleanHtml($adminOrderPage, 'Admin order list');
    if ($adminOrderPage['status'] !== 200 || substr_count($adminOrderPage['body'], 'MÃ ĐƠN') !== 25
        || !preg_match('/href="([^"]*\/admin\/orders\?status=[^"]*cursor=[^"]+)"/', $adminOrderPage['body'], $adminNextMatch)) {
        throw new RuntimeException('Manager order list did not render a 25-order page and next cursor.');
    }
    $adminNextUrl = html_entity_decode($adminNextMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $adminNextParts = parse_url($adminNextUrl);
    if (!is_array($adminNextParts) || !is_string($adminNextParts['path'] ?? null) || !is_string($adminNextParts['query'] ?? null)) throw new RuntimeException('Could not parse the manager order cursor URL.');
    $adminNextPage = $http('GET', $adminNextParts['path'] . '?' . $adminNextParts['query']);
    if ($adminNextPage['status'] !== 200 || substr_count($adminNextPage['body'], 'MÃ ĐƠN') !== 25) throw new RuntimeException('Manager order cursor did not load the next page.');
    $filteredAdminPage = $http('GET', '/admin/orders?status=pending');
    if ($filteredAdminPage['status'] !== 200 || substr_count($filteredAdminPage['body'], 'MÃ ĐƠN') !== 25
        || !preg_match('/href="([^"]*\/admin\/orders\?status=pending[^"]*cursor=[^"]+)"/', $filteredAdminPage['body'], $filteredAdminNextMatch)) {
        throw new RuntimeException('Filtered manager order list did not preserve its status while paging.');
    }
    $filteredNextUrl = html_entity_decode($filteredAdminNextMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $filteredNextParts = parse_url($filteredNextUrl);
    if (!is_array($filteredNextParts) || !is_string($filteredNextParts['path'] ?? null) || !is_string($filteredNextParts['query'] ?? null)) throw new RuntimeException('Could not parse the filtered manager order cursor URL.');
    $filteredNextPage = $http('GET', $filteredNextParts['path'] . '?' . $filteredNextParts['query']);
    if ($filteredNextPage['status'] !== 200 || substr_count($filteredNextPage['body'], 'MÃ ĐƠN') !== 25) throw new RuntimeException('Filtered manager order cursor did not load the next status page.');

    $customerOrderFixtures = [];
    for ($index = 1; $index <= 30; ++$index) {
        $customerOrderFixtures[] = [
            '_id' => 'order:HTTP-CUSTOMER-PAGE-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            'type' => 'order', 'schema_version' => 2, 'legacy_id' => 'HTTP-CUSTOMER-PAGE-' . $index,
            'status' => 'delivered', 'ordered_at' => gmdate('c', time() - 1000 - $index),
            'customer' => ['customer_id' => 'HTTP-RESET-CUSTOMER', 'name' => 'HTTP Reset Customer'],
            'receiver' => ['name' => 'HTTP Reset Customer', 'phone' => '0900000000', 'address' => 'Test'],
            'items' => [['product_id' => 'REPORT', 'variant_id' => 'REPORT-S', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]],
            'totals' => ['total_quantity' => 1, 'subtotal' => 100, 'shipping_fee' => 0, 'discount_amount' => 0, 'grand_total' => 100],
            'status_history' => [],
        ];
    }
    $customerBulk = $client->request('POST', $databasePath . '/_bulk_docs', ['docs' => $customerOrderFixtures]);
    $customerBulkRows = $customerBulk->json();
    if ($customerBulk->statusCode < 200 || $customerBulk->statusCode >= 300 || !is_array($customerBulkRows)
        || count($customerBulkRows) !== 30 || array_filter($customerBulkRows, static fn (mixed $row): bool => is_array($row) && isset($row['error'])) !== []) {
        throw new RuntimeException('Could not create customer order pagination fixture batch.');
    }
    $customerLoginPage = $http('GET', '/login');
    if ($customerLoginPage['status'] !== 200 || !preg_match('/name="csrf_token"\s+value="([^"]+)"/', $customerLoginPage['body'], $customerLoginCsrf)) throw new RuntimeException('Could not load customer order-history login form.');
    $customerLogin = $http('POST', '/login', [
        'csrf_token' => html_entity_decode($customerLoginCsrf[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'email' => 'http-reset@example.test', 'password' => 'Customer-Changed-2026!',
    ]);
    if ($customerLogin['status'] !== 303) throw new RuntimeException('Customer could not log in for order pagination smoke.');
    $customerSessionCookie = $cookie;
    $customerOrderPage = $http('GET', '/account/orders');
    $assertCleanHtml($customerOrderPage, 'Customer order history');
    if ($customerOrderPage['status'] !== 200 || substr_count($customerOrderPage['body'], 'MÃ ĐƠN') !== 25
        || !str_contains($customerOrderPage['body'], 'HTTP-CUSTOMER-PAGE-001')
        || !preg_match('/href="([^"]*\/account\/orders\?cursor=[^"]+)"/', $customerOrderPage['body'], $customerNextMatch)) {
        throw new RuntimeException('Customer order history did not render its first scoped cursor page (status ' . $customerOrderPage['status']
            . ', cards ' . substr_count($customerOrderPage['body'], 'MÃ ĐƠN') . ', body ' . substr($customerOrderPage['body'], 0, 500) . ').');
    }
    $customerNextUrl = html_entity_decode($customerNextMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $customerNextParts = parse_url($customerNextUrl);
    if (!is_array($customerNextParts) || !is_string($customerNextParts['path'] ?? null) || !is_string($customerNextParts['query'] ?? null)) throw new RuntimeException('Could not parse the customer order cursor URL.');
    $customerNextPage = $http('GET', $customerNextParts['path'] . '?' . $customerNextParts['query']);
    if ($customerNextPage['status'] !== 200 || substr_count($customerNextPage['body'], 'MÃ ĐƠN') !== 5
        || !str_contains($customerNextPage['body'], 'HTTP-CUSTOMER-PAGE-026')) throw new RuntimeException('Customer cursor did not load the remaining owner-scoped orders.');
    $customerOrderDetailPage = $http('GET', '/account/orders/' . rawurlencode('order:HTTP-CUSTOMER-PAGE-001'));
    $assertCleanHtml($customerOrderDetailPage, 'Customer order detail');
    if ($customerOrderDetailPage['status'] !== 200 || !str_contains($customerOrderDetailPage['body'], 'Sản phẩm trong đơn')
        || !str_contains($customerOrderDetailPage['body'], 'Lịch sử đơn hàng')
        || !str_contains($customerOrderDetailPage['body'], 'Đơn hàng chưa có thông tin theo dõi giao hàng.')
        || str_contains($customerOrderDetailPage['body'], 'HTTP-SMOKE-MANAGER')) {
        throw new RuntimeException('Customer order detail did not render its legacy no-tracking state or exposed staff-only data.');
    }
    $customerOrderEvents = $http('GET', '/account/orders/' . rawurlencode('order:HTTP-CUSTOMER-PAGE-001') . '/events');
    if ($customerOrderEvents['status'] !== 200
        || stripos((string) ($customerOrderEvents['headers']['content-type'] ?? ''), 'text/event-stream') === false
        || !str_contains($customerOrderEvents['body'], 'event: order_updated')
        || !str_contains($customerOrderEvents['body'], '"delivery":null')
        || str_contains($customerOrderEvents['body'], 'staff_id')
        || str_contains($customerOrderEvents['body'], 'HTTP-SMOKE-MANAGER')) {
        throw new RuntimeException('Owned customer SSE did not return its initial legacy-order snapshot safely.');
    }

    $customerTokenResponse = $apiHttp('POST', '/api/auth/token', null, [
        'username' => 'http-reset@example.test', 'password' => 'Customer-Changed-2026!',
    ]);
    $customerToken = $customerTokenResponse['json']['access_token'] ?? null;
    if ($customerTokenResponse['status'] !== 200 || !is_string($customerToken) || $customerToken === '') {
        throw new RuntimeException('Customer JSON API token could not be issued after the required password change.');
    }
    $customerReviewPath = '/api/v1/products/' . rawurlencode((string) $product['legacy_id']) . '/reviews';
    if ($apiHttp('POST', $customerReviewPath, null, ['rating' => 5, 'content' => 'JWT review smoke'])['status'] !== 401) {
        throw new RuntimeException('JWT review API accepted a request without a bearer token.');
    }
    $customerReview = $apiHttp('POST', $customerReviewPath, $customerToken, ['rating' => 5, 'content' => 'JWT review smoke']);
    $customerReviewDoc = is_string($customerReview['json']['data']['id'] ?? null)
        ? $client->request('GET', $databasePath . '/' . rawurlencode($customerReview['json']['data']['id']))->json()
        : [];
    if ($customerReview['status'] !== 201 || ($customerReview['json']['data']['rating'] ?? null) !== 5
        || ($customerReviewDoc['customer_id'] ?? null) !== 'HTTP-RESET-CUSTOMER'
        || ($customerReviewDoc['product_id'] ?? null) !== $product['legacy_id']) {
        throw new RuntimeException('JWT review API did not create a review attributed to the authenticated customer.');
    }
    $duplicateCustomerReview = $apiHttp('POST', $customerReviewPath, $customerToken, ['rating' => 4, 'content' => 'Duplicate JWT review']);
    $invalidCustomerReview = $apiHttp('POST', $customerReviewPath, $customerToken, ['rating' => 6, 'content' => 'Invalid rating']);
    if ($duplicateCustomerReview['status'] !== 422 || $invalidCustomerReview['status'] !== 422) {
        throw new RuntimeException('JWT review API did not reject a duplicate or out-of-range rating.');
    }
    $jwtOrderPage = $apiHttp('GET', '/api/v1/orders', $customerToken);
    if ($jwtOrderPage['status'] !== 200 || count($jwtOrderPage['json']['data'] ?? []) !== 25
        || !is_string($jwtOrderPage['json']['next_cursor'] ?? null)) {
        throw new RuntimeException('JWT customer order API did not return the first scoped cursor page.');
    }
    if ($apiHttp('GET', '/api/admin/orders')['status'] !== 401
        || $apiHttp('GET', '/api/admin/orders', $customerToken)['status'] !== 403) {
        throw new RuntimeException('JWT staff order API did not require a staff bearer account.');
    }
    $jwtOrderDetail = $apiHttp('GET', '/api/v1/orders/' . rawurlencode('order:HTTP-CUSTOMER-PAGE-001'), $customerToken);
    if ($jwtOrderDetail['status'] !== 200
        || ($jwtOrderDetail['json']['data']['id'] ?? null) !== 'order:HTTP-CUSTOMER-PAGE-001'
        || isset($jwtOrderDetail['json']['data']['meta'], $jwtOrderDetail['json']['data']['payment_history'])) {
        throw new RuntimeException('JWT customer order detail was missing or exposed internal order metadata.');
    }
    $jwtOrderNext = $apiHttp('GET', '/api/v1/orders?' . http_build_query(['cursor' => $jwtOrderPage['json']['next_cursor']]), $customerToken);
    if ($jwtOrderNext['status'] !== 200 || count($jwtOrderNext['json']['data'] ?? []) !== 5) {
        throw new RuntimeException('JWT customer order API cursor did not return the remaining page.');
    }

    $confirmReceivedId = 'order:HTTP-RECEIVE-CONFIRM';
    $otherCustomerOrderId = 'order:HTTP-OTHER-RECEIVE';
    $transferOrderId = 'order:HTTP-TRANSFER-RECEIVE';
    $jwtConfirmOrderId = 'order:HTTP-JWT-RECEIVE';
    foreach ([
        [
            '_id' => $confirmReceivedId, 'type' => 'order', 'schema_version' => 2, 'legacy_id' => 'HTTP-RECEIVE-CONFIRM',
            'status' => 'shipping', 'ordered_at' => gmdate('c', time() - 5000),
            'customer' => ['customer_id' => 'HTTP-RESET-CUSTOMER', 'name' => 'HTTP Reset Customer'],
            'receiver' => ['name' => 'HTTP Reset Customer', 'phone' => '0900000000', 'address' => 'Test'],
            'items' => [['product_id' => 'REPORT', 'variant_id' => 'REPORT-S', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]],
            'totals' => ['total_quantity' => 1, 'subtotal' => 100, 'shipping_fee' => 0, 'discount_amount' => 0, 'grand_total' => 100],
            'payment' => ['method' => 'cod', 'legacy_label' => 'Thanh toán khi nhận hàng', 'status' => 'unpaid', 'paid' => false],
            'status_history' => [['status' => 'shipping', 'at' => gmdate('c', time() - 60), 'source' => 'http_smoke']],
        ],
        [
            '_id' => $otherCustomerOrderId, 'type' => 'order', 'schema_version' => 2, 'legacy_id' => 'HTTP-OTHER-RECEIVE',
            'status' => 'shipping', 'ordered_at' => gmdate('c', time() - 6000),
            'customer' => ['customer_id' => 'SOMEONE-ELSE', 'name' => 'Another Customer'],
            'receiver' => ['name' => 'Another Customer', 'phone' => '0900000001', 'address' => 'Other test'],
            'items' => [['product_id' => 'REPORT', 'variant_id' => 'REPORT-S', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]],
            'totals' => ['total_quantity' => 1, 'subtotal' => 100, 'shipping_fee' => 0, 'discount_amount' => 0, 'grand_total' => 100],
            'payment' => ['method' => 'cod', 'status' => 'unpaid', 'paid' => false],
            'status_history' => [['status' => 'shipping', 'at' => gmdate('c', time() - 60), 'source' => 'http_smoke']],
        ],
        [
            '_id' => $transferOrderId, 'type' => 'order', 'schema_version' => 2, 'legacy_id' => 'HTTP-TRANSFER-RECEIVE',
            'status' => 'shipping', 'ordered_at' => gmdate('c', time() - 7000),
            'customer' => ['customer_id' => 'HTTP-RESET-CUSTOMER', 'name' => 'HTTP Reset Customer'],
            'receiver' => ['name' => 'HTTP Reset Customer', 'phone' => '0900000002', 'address' => 'Transfer test'],
            'items' => [['product_id' => 'REPORT', 'variant_id' => 'REPORT-S', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]],
            'totals' => ['total_quantity' => 1, 'subtotal' => 100, 'shipping_fee' => 0, 'discount_amount' => 0, 'grand_total' => 100],
            'payment' => ['method' => 'bank_transfer', 'legacy_label' => 'Chuyển khoản', 'status' => 'unpaid', 'paid' => false],
            'status_history' => [['status' => 'shipping', 'at' => gmdate('c', time() - 60), 'source' => 'http_smoke']],
        ],
    ] as $receivedFixture) {
        $stored = $client->request('PUT', $databasePath . '/' . rawurlencode($receivedFixture['_id']), $receivedFixture);
        if ($stored->statusCode < 200 || $stored->statusCode >= 300) throw new RuntimeException('Could not create customer receipt fixtures.');
    }
    $jwtConfirmFixture = [
        '_id' => $jwtConfirmOrderId, 'type' => 'order', 'schema_version' => 2, 'legacy_id' => 'HTTP-JWT-RECEIVE',
        'status' => 'shipping', 'ordered_at' => gmdate('c'),
        'customer' => ['customer_id' => 'HTTP-RESET-CUSTOMER', 'name' => 'HTTP Reset Customer'],
        'receiver' => ['name' => 'HTTP Reset Customer', 'phone' => '0900000000', 'address' => 'API test'],
        'items' => [['product_id' => 'REPORT', 'variant_id' => 'REPORT-S', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]],
        'totals' => ['total_quantity' => 1, 'subtotal' => 100, 'shipping_fee' => 0, 'discount_amount' => 0, 'grand_total' => 100],
        'payment' => ['method' => 'cod', 'status' => 'unpaid', 'paid' => false],
        'meta' => ['checkout' => ['phase' => 'completed']],
        'status_history' => [['status' => 'shipping', 'at' => gmdate('c'), 'source' => 'http_smoke']],
    ];
    $jwtConfirmStored = $client->request('PUT', $databasePath . '/' . rawurlencode($jwtConfirmOrderId), $jwtConfirmFixture);
    if ($jwtConfirmStored->statusCode < 200 || $jwtConfirmStored->statusCode >= 300) throw new RuntimeException('Could not create JWT receipt fixture.');
    $jwtConfirmPath = '/api/v1/orders/' . rawurlencode($jwtConfirmOrderId) . '/confirm-received';
    if ($apiHttp('POST', $jwtConfirmPath)['status'] !== 401) throw new RuntimeException('JWT receipt confirmation accepted a request without a bearer token.');
    $jwtConfirm = $apiHttp('POST', $jwtConfirmPath, $customerToken);
    $jwtConfirmedDoc = $client->request('GET', $databasePath . '/' . rawurlencode($jwtConfirmOrderId))->json();
    $jwtReceiptEvents = array_values(array_filter($jwtConfirmedDoc['status_history'] ?? [], static fn (mixed $event): bool => is_array($event) && ($event['source'] ?? null) === 'php_customer_confirm_received'));
    $jwtPaymentEvents = array_values(array_filter($jwtConfirmedDoc['payment_history'] ?? [], static fn (mixed $event): bool => is_array($event) && ($event['source'] ?? null) === 'php_customer_confirm_received'));
    if ($jwtConfirm['status'] !== 200 || ($jwtConfirm['json']['data']['status'] ?? null) !== 'delivered'
        || ($jwtConfirm['json']['data']['payment']['paid'] ?? false) !== true
        || ($jwtConfirmedDoc['payment']['status'] ?? null) !== 'paid'
        || count($jwtReceiptEvents) !== 1 || count($jwtPaymentEvents) !== 1) {
        throw new RuntimeException('JWT receipt confirmation failed to deliver the order, collect COD and write one audit event.');
    }
    $jwtConfirmReplay = $apiHttp('POST', $jwtConfirmPath, $customerToken);
    $jwtConfirmedAgain = $client->request('GET', $databasePath . '/' . rawurlencode($jwtConfirmOrderId))->json();
    $jwtReplayEvents = array_values(array_filter($jwtConfirmedAgain['status_history'] ?? [], static fn (mixed $event): bool => is_array($event) && ($event['source'] ?? null) === 'php_customer_confirm_received'));
    if ($jwtConfirmReplay['status'] !== 200 || count($jwtReplayEvents) !== 1) throw new RuntimeException('Repeated JWT receipt confirmation duplicated the transition.');
    $jwtTransferFixture = $jwtConfirmFixture;
    $jwtTransferFixture['_id'] = 'order:HTTP-JWT-TRANSFER-RECEIVE';
    $jwtTransferFixture['legacy_id'] = 'HTTP-JWT-TRANSFER-RECEIVE';
    $jwtTransferFixture['payment'] = ['method' => 'bank_transfer', 'status' => 'unpaid', 'paid' => false];
    $jwtTransferStored = $client->request('PUT', $databasePath . '/' . rawurlencode($jwtTransferFixture['_id']), $jwtTransferFixture);
    if ($jwtTransferStored->statusCode < 200 || $jwtTransferStored->statusCode >= 300) throw new RuntimeException('Could not create JWT bank-transfer receipt fixture.');
    $jwtTransferConfirm = $apiHttp('POST', '/api/v1/orders/' . rawurlencode($jwtTransferFixture['_id']) . '/confirm-received', $customerToken);
    $jwtTransferConfirmed = $client->request('GET', $databasePath . '/' . rawurlencode($jwtTransferFixture['_id']))->json();
    if ($jwtTransferConfirm['status'] !== 200 || ($jwtTransferConfirmed['status'] ?? null) !== 'delivered'
        || ($jwtTransferConfirmed['payment']['status'] ?? null) !== 'unpaid' || ($jwtTransferConfirmed['payment']['paid'] ?? true) !== false) {
        throw new RuntimeException('JWT receipt confirmation incorrectly marked a bank transfer as paid.');
    }
    $jwtOtherCustomerOrder = $apiHttp('GET', '/api/v1/orders/' . rawurlencode($otherCustomerOrderId), $customerToken);
    $jwtOtherConfirm = $apiHttp('POST', '/api/v1/orders/' . rawurlencode($otherCustomerOrderId) . '/confirm-received', $customerToken);
    if ($jwtOtherCustomerOrder['status'] !== 404 || $jwtOtherConfirm['status'] !== 404
        || ($client->request('GET', $databasePath . '/' . rawurlencode($otherCustomerOrderId))->json()['status'] ?? null) !== 'shipping') {
        throw new RuntimeException('JWT customer order API exposed or modified another customer\'s order.');
    }
    $confirmPath = '/account/orders/' . rawurlencode($confirmReceivedId) . '/confirm-received';
    $confirmPage = $http('GET', '/account/orders/' . rawurlencode($confirmReceivedId));
    if ($confirmPage['status'] !== 200 || !str_contains($confirmPage['body'], 'Tôi đã nhận hàng')
        || !preg_match('/name="csrf_token"\s+value="([^"]+)"/', $confirmPage['body'], $confirmCsrf)) {
        throw new RuntimeException('Customer receipt confirmation form was not shown for an owned shipping order.');
    }
    $confirmToken = html_entity_decode($confirmCsrf[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $missingCsrf = $http('POST', $confirmPath);
    if ($missingCsrf['status'] !== 303
        || ($client->request('GET', $databasePath . '/' . rawurlencode($confirmReceivedId))->json()['status'] ?? null) !== 'shipping') {
        throw new RuntimeException('Receipt confirmation changed an order without a valid CSRF token.');
    }
    $confirmResponse = $http('POST', $confirmPath, ['csrf_token' => $confirmToken]);
    $confirmedOrder = $client->request('GET', $databasePath . '/' . rawurlencode($confirmReceivedId))->json();
    $receiptEvents = array_values(array_filter($confirmedOrder['status_history'] ?? [], static fn (mixed $event): bool => is_array($event) && ($event['source'] ?? null) === 'php_customer_confirm_received'));
    if ($confirmResponse['status'] !== 303 || ($confirmedOrder['status'] ?? null) !== 'delivered'
        || ($confirmedOrder['payment']['status'] ?? null) !== 'paid' || ($confirmedOrder['payment']['paid'] ?? false) !== true
        || count($receiptEvents) !== 1 || ($receiptEvents[0]['customer_id'] ?? null) !== 'HTTP-RESET-CUSTOMER') {
        throw new RuntimeException('Customer receipt confirmation did not atomically mark delivery, payment and audit history.');
    }
    $duplicateConfirm = $http('POST', $confirmPath, ['csrf_token' => $confirmToken]);
    $confirmedAgain = $client->request('GET', $databasePath . '/' . rawurlencode($confirmReceivedId))->json();
    $duplicateReceiptEvents = array_values(array_filter($confirmedAgain['status_history'] ?? [], static fn (mixed $event): bool => is_array($event) && ($event['source'] ?? null) === 'php_customer_confirm_received'));
    if ($duplicateConfirm['status'] !== 303 || count($duplicateReceiptEvents) !== 1) throw new RuntimeException('Repeated receipt confirmation duplicated the order transition.');
    $otherCustomerBefore = $client->request('GET', $databasePath . '/' . rawurlencode($otherCustomerOrderId))->json();
    $otherCustomerResponse = $http('POST', '/account/orders/' . rawurlencode($otherCustomerOrderId) . '/confirm-received', ['csrf_token' => $confirmToken]);
    $otherCustomerAfter = $client->request('GET', $databasePath . '/' . rawurlencode($otherCustomerOrderId))->json();
    if ($otherCustomerResponse['status'] !== 303 || ($otherCustomerAfter['_rev'] ?? null) !== ($otherCustomerBefore['_rev'] ?? null)
        || ($otherCustomerAfter['status'] ?? null) !== 'shipping' || ($otherCustomerAfter['payment']['paid'] ?? false) !== false) {
        throw new RuntimeException('Customer receipt confirmation modified another customer\'s order.');
    }
    $transferConfirmPage = $http('GET', '/account/orders/' . rawurlencode($transferOrderId));
    if ($transferConfirmPage['status'] !== 200 || !preg_match('/name="csrf_token"\s+value="([^"]+)"/', $transferConfirmPage['body'], $transferConfirmCsrf)) {
        throw new RuntimeException('Customer could not load the owned bank-transfer delivery order.');
    }
    $transferConfirm = $http('POST', '/account/orders/' . rawurlencode($transferOrderId) . '/confirm-received', [
        'csrf_token' => html_entity_decode($transferConfirmCsrf[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
    ]);
    $transferredOrderAfter = $client->request('GET', $databasePath . '/' . rawurlencode($transferOrderId))->json();
    if ($transferConfirm['status'] !== 303 || ($transferredOrderAfter['status'] ?? null) !== 'delivered'
        || ($transferredOrderAfter['payment']['status'] ?? null) !== 'unpaid' || ($transferredOrderAfter['payment']['paid'] ?? true) !== false
        || !empty($transferredOrderAfter['payment_history'])) {
        throw new RuntimeException('Delivery confirmation incorrectly marked an unverified bank transfer as paid.');
    }

    // Keep a second authenticated session so the customer SSE stream and staff updates can run concurrently.
    $customerSessionCookie = $cookie;
    $cookie = '';
    $managerLoginPage = $http('GET', '/login');
    if ($managerLoginPage['status'] !== 200 || !preg_match('/name="csrf_token"\s+value="([^"]+)"/', $managerLoginPage['body'], $managerLoginCsrf)) throw new RuntimeException('Could not reload manager login form after customer pagination.');
    $managerLogin = $http('POST', '/login', [
        'csrf_token' => html_entity_decode($managerLoginCsrf[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'email' => 'http-manager@example.test', 'password' => 'Smoke-Manager-2026!',
    ]);
    if ($managerLogin['status'] !== 303) throw new RuntimeException('Manager could not resume after customer pagination smoke.');
    $managerTokenResponse = $apiHttp('POST', '/api/auth/token', null, [
        'username' => 'http-manager@example.test', 'password' => 'Smoke-Manager-2026!',
    ]);
    $managerToken = $managerTokenResponse['json']['access_token'] ?? null;
    if ($managerTokenResponse['status'] !== 200 || !is_string($managerToken) || $managerToken === '') {
        throw new RuntimeException('Manager JSON API token could not be issued.');
    }
    $staffOrderApi = $apiHttp('GET', '/api/v1/orders', $managerToken);
    if ($staffOrderApi['status'] !== 403) throw new RuntimeException('JWT staff token could access the customer order API.');
    $staffCartApi = $apiHttp('GET', '/api/v1/cart', $managerToken);
    if ($staffCartApi['status'] !== 403) throw new RuntimeException('JWT staff token could access the customer cart API.');
    $staffReceiptApi = $apiHttp('POST', '/api/v1/orders/' . rawurlencode('order:HTTP-JWT-RECEIVE') . '/confirm-received', $managerToken);
    if ($staffReceiptApi['status'] !== 403) throw new RuntimeException('JWT staff token could use the customer receipt confirmation API.');
    $staffReviewApi = $apiHttp('POST', '/api/v1/products/' . rawurlencode((string) $product['legacy_id']) . '/reviews', $managerToken, ['rating' => 5, 'content' => 'Staff review must be rejected']);
    if ($staffReviewApi['status'] !== 403) throw new RuntimeException('JWT staff token could submit a customer product review.');
    $staffOrdersPage = $apiHttp('GET', '/api/admin/orders', $managerToken);
    if ($staffOrdersPage['status'] !== 200 || count($staffOrdersPage['json']['data'] ?? []) !== 25
        || !is_string($staffOrdersPage['json']['next_cursor'] ?? null)) {
        throw new RuntimeException('JWT staff order API did not return a cursor-paginated first page.');
    }
    $staffOrdersNext = $apiHttp('GET', '/api/admin/orders?' . http_build_query(['cursor' => $staffOrdersPage['json']['next_cursor']]), $managerToken);
    if ($staffOrdersNext['status'] !== 200 || count($staffOrdersNext['json']['data'] ?? []) < 1) {
        throw new RuntimeException('JWT staff order API cursor did not load the next page.');
    }
    $staffShippingOrders = $apiHttp('GET', '/api/admin/orders?status=shipping', $managerToken);
    $staffInvalidStatus = $apiHttp('GET', '/api/admin/orders?status=unknown', $managerToken);
    $staffOrderDetail = $apiHttp('GET', '/api/admin/orders/' . rawurlencode($otherCustomerOrderId), $managerToken);
    $staffMissingOrder = $apiHttp('GET', '/api/admin/orders/order%3AMISSING', $managerToken);
    if ($staffShippingOrders['status'] !== 200
        || array_filter($staffShippingOrders['json']['data'] ?? [], static fn (array $order): bool => ($order['status'] ?? null) !== 'shipping') !== []
        || $staffInvalidStatus['status'] !== 400
        || $staffOrderDetail['status'] !== 200 || ($staffOrderDetail['json']['data']['id'] ?? null) !== $otherCustomerOrderId
        || isset($staffOrderDetail['json']['data']['meta'], $staffOrderDetail['json']['data']['payment_history'])
        || $staffMissingOrder['status'] !== 404) {
        throw new RuntimeException('JWT staff order filters or allowlisted detail response failed.');
    }
    $staffWorkflowId = 'order:HTTP-JWT-STAFF-WORKFLOW';
    $staffCodPaymentId = 'order:HTTP-JWT-STAFF-COD';
    $staffTransferPaymentId = 'order:HTTP-JWT-STAFF-TRANSFER';
    $staffOrderFixtureBase = [
        'type' => 'order', 'schema_version' => 2, 'ordered_at' => gmdate('c'), 'guest_order' => false,
        'customer' => ['customer_id' => 'HTTP-RESET-CUSTOMER', 'name' => 'HTTP Reset Customer'],
        'receiver' => ['name' => 'HTTP Reset Customer', 'phone' => '0900000000', 'address' => 'API smoke test'],
        'items' => [['product_id' => 'REPORT', 'variant_id' => 'REPORT-S', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]],
        'totals' => ['total_quantity' => 1, 'subtotal' => 100, 'shipping_fee' => 0, 'discount_amount' => 0, 'grand_total' => 100],
        'meta' => ['checkout' => ['phase' => 'completed']], 'status_history' => [], 'payment_history' => [],
    ];
    $staffWorkflowFixtures = [
        array_replace($staffOrderFixtureBase, [
            '_id' => $staffWorkflowId, 'legacy_id' => 'HTTP-JWT-STAFF-WORKFLOW', 'status' => 'pending',
            'payment' => ['method' => 'cod', 'status' => 'unpaid', 'paid' => false],
        ]),
        array_replace($staffOrderFixtureBase, [
            '_id' => $staffCodPaymentId, 'legacy_id' => 'HTTP-JWT-STAFF-COD', 'status' => 'delivered',
            'payment' => ['method' => 'cod', 'status' => 'unpaid', 'paid' => false],
        ]),
        array_replace($staffOrderFixtureBase, [
            '_id' => $staffTransferPaymentId, 'legacy_id' => 'HTTP-JWT-STAFF-TRANSFER', 'status' => 'delivered',
            'payment' => ['method' => 'bank_transfer', 'status' => 'unpaid', 'paid' => false],
        ]),
    ];
    foreach ($staffWorkflowFixtures as $staffWorkflowFixture) {
        $stored = $client->request('PUT', $databasePath . '/' . rawurlencode($staffWorkflowFixture['_id']), $staffWorkflowFixture);
        if ($stored->statusCode < 200 || $stored->statusCode >= 300) throw new RuntimeException('Could not create staff API workflow fixtures.');
    }
    $foreignCustomerEventsHandle = curl_init($base . '/account/orders/' . rawurlencode($otherCustomerOrderId) . '/events');
    curl_setopt_array($foreignCustomerEventsHandle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Cookie: ' . $customerSessionCookie]]);
    $foreignCustomerEventsBody = curl_exec($foreignCustomerEventsHandle);
    $foreignCustomerEventsStatus = (int) curl_getinfo($foreignCustomerEventsHandle, CURLINFO_RESPONSE_CODE);
    curl_close($foreignCustomerEventsHandle);
    if (!is_string($foreignCustomerEventsBody) || $foreignCustomerEventsStatus !== 404) {
        throw new RuntimeException('Customer A could subscribe to Customer B order events.');
    }
    if ($apiHttp('POST', '/api/admin/orders/' . rawurlencode($staffWorkflowId) . '/status', $customerToken, ['status' => 'confirmed'])['status'] !== 403) {
        throw new RuntimeException('JWT customer could modify an order through the staff API.');
    }
    $liveStatusEvent = $verifyLiveSseUpdate(
        '/account/orders/' . rawurlencode($staffWorkflowId) . '/events',
        $customerSessionCookie,
        'pending',
        'confirmed',
        static function () use ($apiHttp, $staffWorkflowId, $managerToken): array {
            return $apiHttp('POST', '/api/admin/orders/' . rawurlencode($staffWorkflowId) . '/status', $managerToken, ['status' => 'confirmed']);
        },
    );
    $staffStatusChanged = $liveStatusEvent['update'];
    if ($liveStatusEvent['status'] !== 200
        || stripos((string) ($liveStatusEvent['headers']['content-type'] ?? ''), 'text/event-stream') === false
        || substr_count($liveStatusEvent['body'], 'event: order_updated') < 2
        || str_contains($liveStatusEvent['body'], 'staff_id')
        || str_contains($liveStatusEvent['body'], 'updated_by')
        || str_contains($liveStatusEvent['body'], 'HTTP-SMOKE-MANAGER')) {
        throw new RuntimeException('SSE order transition was not streamed live or exposed staff-only fields.');
    }
    $staffInvalidTransition = $apiHttp('POST', '/api/admin/orders/' . rawurlencode($staffWorkflowId) . '/status', $managerToken, ['status' => 'pending']);
    $staffStatusChangedAgain = $apiHttp('POST', '/api/admin/orders/' . rawurlencode($staffWorkflowId) . '/status', $managerToken, ['status' => 'packing']);
    $staffStatusReplay = $apiHttp('POST', '/api/admin/orders/' . rawurlencode($staffWorkflowId) . '/status', $managerToken, ['status' => 'packing']);
    $staffWorkflowDoc = $client->request('GET', $databasePath . '/' . rawurlencode($staffWorkflowId))->json();
    $staffStatusEvents = array_values(array_filter($staffWorkflowDoc['status_history'] ?? [], static fn (mixed $event): bool => is_array($event) && ($event['source'] ?? null) === 'php_admin'));
    if ($staffStatusChanged['status'] !== 200 || $staffInvalidTransition['status'] !== 422
        || $staffStatusChangedAgain['status'] !== 200 || $staffStatusReplay['status'] !== 200
        || ($staffWorkflowDoc['status'] ?? null) !== 'packing' || count($staffStatusEvents) !== 2) {
        throw new RuntimeException('JWT staff order transition validation, audit or idempotent replay failed.');
    }
    $staffShipping = $apiHttp('POST', '/api/admin/orders/' . rawurlencode($staffWorkflowId) . '/status', $managerToken, ['status' => 'shipping']);
    $customerDeliveryDenied = $apiHttp('POST', '/api/admin/orders/' . rawurlencode($staffWorkflowId) . '/delivery', $customerToken, ['status' => 'picked_up']);
    $deliverySkip = $apiHttp('POST', '/api/admin/orders/' . rawurlencode($staffWorkflowId) . '/delivery', $managerToken, ['status' => 'in_transit']);
    $staffWorkflowDoc = $client->request('GET', $databasePath . '/' . rawurlencode($staffWorkflowId))->json();
    if ($staffShipping['status'] !== 200 || $customerDeliveryDenied['status'] !== 403 || $deliverySkip['status'] !== 422
        || ($staffWorkflowDoc['delivery_tracking']['status'] ?? null) !== 'created') {
        throw new RuntimeException('Shipping transition did not initialize tracking or delivery update authorization/state validation failed.');
    }
    $staffDeliveryPage = $http('GET', '/admin/orders/' . rawurlencode($staffWorkflowId));
    $assertCleanHtml($staffDeliveryPage, 'Admin order detail');
    if ($staffDeliveryPage['status'] !== 200 || !str_contains($staffDeliveryPage['body'], 'Cập nhật giao hàng')
        || !str_contains($staffDeliveryPage['body'], 'Lịch sử trạng thái đơn')
        || !str_contains($staffDeliveryPage['body'], 'Sản phẩm trong đơn')
        || !str_contains($staffDeliveryPage['body'], (string) ($staffWorkflowDoc['delivery_tracking']['tracking_code'] ?? ''))
        || !preg_match('/name="csrf_token"\s+value="([^"]+)"/', $staffDeliveryPage['body'], $staffDeliveryCsrf)) {
        throw new RuntimeException('Manager order detail did not render its delivery information and CSRF form.');
    }
    if (substr_count($staffDeliveryPage['body'], 'name="delivery_status"') !== 1) {
        throw new RuntimeException('Admin order detail rendered duplicate delivery update forms.');
    }
    $staffShippingList = $http('GET', '/admin/orders?status=shipping');
    $trackingCodeForList = (string) ($staffWorkflowDoc['delivery_tracking']['tracking_code'] ?? '');
    if ($staffShippingList['status'] !== 200 || !str_contains($staffShippingList['body'], 'Thanh toán: Chưa thanh toán')
        || !str_contains($staffShippingList['body'], 'Giao hàng: Đã tạo vận đơn')
        || $trackingCodeForList === '' || !str_contains($staffShippingList['body'], $trackingCodeForList)) {
        throw new RuntimeException('Admin order list did not render payment/delivery status and tracking code.');
    }
    $deliveryPath = '/admin/orders/' . rawurlencode($staffWorkflowId) . '/delivery';
    $invalidDeliveryForm = $http('POST', $deliveryPath, ['csrf_token' => 'invalid-token', 'delivery_status' => 'picked_up']);
    $invalidDeliveryPage = $http('GET', '/admin/orders/' . rawurlencode($staffWorkflowId));
    $assertCleanHtml($invalidDeliveryPage, 'Admin order detail after an invalid delivery form');
    if ($invalidDeliveryForm['status'] !== 303 || !str_contains($invalidDeliveryPage['body'], 'Yêu cầu không hợp lệ. Hãy tải lại trang rồi thử lại.')) {
        throw new RuntimeException('Admin delivery CSRF failure did not render a clear error message.');
    }
    $afterInvalidDeliveryForm = $client->request('GET', $databasePath . '/' . rawurlencode($staffWorkflowId))->json();
    if ($invalidDeliveryForm['status'] !== 303 || ($afterInvalidDeliveryForm['delivery_tracking']['status'] ?? null) !== 'created') {
        throw new RuntimeException('Admin delivery form accepted an invalid CSRF token.');
    }
    $validDeliveryForm = $http('POST', $deliveryPath, [
        'csrf_token' => html_entity_decode($staffDeliveryCsrf[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'delivery_status' => 'picked_up', 'note' => 'Đã nhận tại kho',
    ]);
    $afterValidDeliveryForm = $client->request('GET', $databasePath . '/' . rawurlencode($staffWorkflowId))->json();
    if ($validDeliveryForm['status'] !== 303 || ($afterValidDeliveryForm['delivery_tracking']['status'] ?? null) !== 'picked_up') {
        throw new RuntimeException('CSRF-protected admin delivery form did not update the order.');
    }
    $deliveryEvents = [];
    $previousDeliveryStatus = 'picked_up';
    foreach (['in_transit', 'out_for_delivery', 'failed_delivery', 'out_for_delivery', 'delivered'] as $deliveryStatus) {
        $deliveryEvent = $verifyLiveSseUpdate(
            '/account/orders/' . rawurlencode($staffWorkflowId) . '/events',
            $customerSessionCookie,
            $previousDeliveryStatus,
            $deliveryStatus,
            static function () use ($apiHttp, $staffWorkflowId, $managerToken, $deliveryStatus): array {
                return $apiHttp('POST', '/api/admin/orders/' . rawurlencode($staffWorkflowId) . '/delivery', $managerToken, ['status' => $deliveryStatus]);
            },
        );
        $deliveryEvents[] = $deliveryEvent;
        if ($deliveryEvent['status'] !== 200 || ($deliveryEvent['update']['status'] ?? null) !== 200
            || substr_count($deliveryEvent['body'], 'event: order_updated') < 2
            || str_contains($deliveryEvent['body'], 'staff_id')
            || str_contains($deliveryEvent['body'], 'updated_by')
            || str_contains($deliveryEvent['body'], 'HTTP-SMOKE-MANAGER')) {
            throw new RuntimeException('SSE delivery transition was not streamed safely at ' . $deliveryStatus . '.');
        }
        if ($deliveryStatus === 'failed_delivery' && !str_contains($deliveryEvent['body'], 'failed_delivery')) {
            throw new RuntimeException('SSE failed-delivery update did not include its timeline entry.');
        }
        if ($previousDeliveryStatus === 'failed_delivery' && !str_contains($deliveryEvent['body'], 'failed_delivery')) {
            throw new RuntimeException('SSE delivery retry did not preserve the failed-delivery event in its timeline.');
        }
        $previousDeliveryStatus = $deliveryStatus;
    }
    $staffWorkflowDoc = $client->request('GET', $databasePath . '/' . rawurlencode($staffWorkflowId))->json();
    $customerDeliveryDetail = $apiHttp('GET', '/api/v1/orders/' . rawurlencode($staffWorkflowId), $customerToken);
    if (($staffWorkflowDoc['status'] ?? null) !== 'delivered'
        || ($staffWorkflowDoc['delivery_tracking']['status'] ?? null) !== 'delivered'
        || count($staffWorkflowDoc['delivery_tracking']['history'] ?? []) !== 7
        || ($customerDeliveryDetail['status'] ?? null) !== 200
        || ($customerDeliveryDetail['json']['data']['delivery_tracking']['status'] ?? null) !== 'delivered'
        || isset($customerDeliveryDetail['json']['data']['delivery_tracking']['history'][0]['updated_by'])) {
        throw new RuntimeException('Delivery history, order completion or customer-safe tracking API response failed.');
    }
    $staffCodCollected = $apiHttp('POST', '/api/admin/orders/' . rawurlencode($staffCodPaymentId) . '/payment/cod-collected', $managerToken);
    $staffCodCollectedAgain = $apiHttp('POST', '/api/admin/orders/' . rawurlencode($staffCodPaymentId) . '/payment/cod-collected', $managerToken);
    $staffCodDoc = $client->request('GET', $databasePath . '/' . rawurlencode($staffCodPaymentId))->json();
    $staffCodEvents = array_values(array_filter($staffCodDoc['payment_history'] ?? [], static fn (mixed $event): bool => is_array($event) && ($event['source'] ?? null) === 'php_admin_cod_collection'));
    $staffManualPayment = $apiHttp('POST', '/api/admin/orders/' . rawurlencode($staffTransferPaymentId) . '/payment/verify', $managerToken);
    $staffManualPaymentAgain = $apiHttp('POST', '/api/admin/orders/' . rawurlencode($staffTransferPaymentId) . '/payment/verify', $managerToken);
    $staffTransferDoc = $client->request('GET', $databasePath . '/' . rawurlencode($staffTransferPaymentId))->json();
    $staffManualEvents = array_values(array_filter($staffTransferDoc['payment_history'] ?? [], static fn (mixed $event): bool => is_array($event) && ($event['source'] ?? null) === 'php_admin_payment_verification'));
    if ($staffCodCollected['status'] !== 200 || $staffCodCollectedAgain['status'] !== 200
        || ($staffCodDoc['payment']['paid'] ?? false) !== true || count($staffCodEvents) !== 1
        || $staffManualPayment['status'] !== 200 || $staffManualPaymentAgain['status'] !== 200
        || ($staffTransferDoc['payment']['paid'] ?? false) !== true || count($staffManualEvents) !== 1
        || ($staffManualEvents[0]['method'] ?? null) !== 'bank_transfer'
        || ($staffManualEvents[0]['verification'] ?? null) !== 'manual') {
        throw new RuntimeException('JWT staff COD collection or manual payment verification failed or duplicated audit.');
    }

    $codCollectionId = 'order:HTTP-COD-COLLECTION';
    $codCollectionFixture = [
        '_id' => $codCollectionId, 'type' => 'order', 'schema_version' => 2, 'legacy_id' => 'HTTP-COD-COLLECTION',
        'status' => 'delivered', 'ordered_at' => gmdate('c', time() - 1000), 'guest_order' => false,
        'customer' => ['customer_id' => 'HTTP-RESET-CUSTOMER', 'name' => 'HTTP Reset Customer'],
        'receiver' => ['name' => 'HTTP Reset Customer', 'phone' => '0900000000', 'address' => 'Test'],
        'items' => [['product_id' => 'REPORT', 'variant_id' => 'REPORT-S', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]],
        'totals' => ['total_quantity' => 1, 'subtotal' => 100, 'shipping_fee' => 0, 'discount_amount' => 0, 'grand_total' => 100],
        'payment' => ['method' => 'cod', 'legacy_label' => 'Thanh toán khi nhận hàng', 'status' => 'unpaid', 'paid' => false],
        'status_history' => [['status' => 'delivered', 'at' => gmdate('c', time() - 60), 'source' => 'http_smoke']],
    ];
    $storedCodOrder = $client->request('PUT', $databasePath . '/' . rawurlencode($codCollectionId), $codCollectionFixture);
    if ($storedCodOrder->statusCode < 200 || $storedCodOrder->statusCode >= 300) throw new RuntimeException('Could not create COD collection fixture.');
    $codAdminPage = $http('GET', '/admin/orders/' . rawurlencode($codCollectionId));
    if ($codAdminPage['status'] !== 200 || !str_contains($codAdminPage['body'], 'Ghi nhận đã thu COD')
        || !preg_match('/name="csrf_token"\s+value="([^"]+)"/', $codAdminPage['body'], $codCollectionCsrf)) {
        throw new RuntimeException('Admin COD collection action was not shown for a delivered unpaid COD order.');
    }
    $codCollectionPath = '/admin/orders/' . rawurlencode($codCollectionId) . '/payment/collect';
    $codCollectionToken = html_entity_decode($codCollectionCsrf[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $invalidCodCollection = $http('POST', $codCollectionPath, ['csrf_token' => 'invalid-token']);
    $unpaidCodOrder = $client->request('GET', $databasePath . '/' . rawurlencode($codCollectionId))->json();
    if ($invalidCodCollection['status'] !== 303 || ($unpaidCodOrder['payment']['paid'] ?? true) !== false) {
        throw new RuntimeException('Admin COD collection accepted an invalid CSRF token.');
    }
    $recordCodPayment = $http('POST', $codCollectionPath, ['csrf_token' => $codCollectionToken]);
    $paidCodOrder = $client->request('GET', $databasePath . '/' . rawurlencode($codCollectionId))->json();
    $codPaymentEvents = array_values(array_filter($paidCodOrder['payment_history'] ?? [], static fn (mixed $event): bool => is_array($event) && ($event['source'] ?? null) === 'php_admin_cod_collection'));
    if ($recordCodPayment['status'] !== 303 || ($paidCodOrder['payment']['status'] ?? null) !== 'paid'
        || ($paidCodOrder['payment']['paid'] ?? false) !== true || count($codPaymentEvents) !== 1
        || ($codPaymentEvents[0]['staff_id'] ?? null) !== 'HTTP-SMOKE-MANAGER') {
        throw new RuntimeException('Admin COD collection did not persist paid status and staff audit.');
    }
    $repeatCodPayment = $http('POST', $codCollectionPath, ['csrf_token' => $codCollectionToken]);
    $paidCodOrderAgain = $client->request('GET', $databasePath . '/' . rawurlencode($codCollectionId))->json();
    $codPaymentEventsAgain = array_values(array_filter($paidCodOrderAgain['payment_history'] ?? [], static fn (mixed $event): bool => is_array($event) && ($event['source'] ?? null) === 'php_admin_cod_collection'));
    if ($repeatCodPayment['status'] !== 303 || count($codPaymentEventsAgain) !== 1) throw new RuntimeException('Repeated COD collection duplicated payment audit.');

    $manualPaymentPage = $http('GET', '/admin/orders/' . rawurlencode($transferOrderId));
    if ($manualPaymentPage['status'] !== 200 || !str_contains($manualPaymentPage['body'], 'Ghi nhận đã xác minh thanh toán')
        || !preg_match('/name="csrf_token"\s+value="([^"]+)"/', $manualPaymentPage['body'], $manualPaymentCsrf)) {
        throw new RuntimeException('Manual verification action was not shown for the unpaid bank-transfer order.');
    }
    $manualPaymentPath = '/admin/orders/' . rawurlencode($transferOrderId) . '/payment/verify';
    $manualPaymentToken = html_entity_decode($manualPaymentCsrf[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $invalidManualPayment = $http('POST', $manualPaymentPath, ['csrf_token' => 'invalid-token']);
    $transferStillUnpaid = $client->request('GET', $databasePath . '/' . rawurlencode($transferOrderId))->json();
    if ($invalidManualPayment['status'] !== 303 || ($transferStillUnpaid['payment']['paid'] ?? true) !== false) {
        throw new RuntimeException('Manual payment verification accepted an invalid CSRF token.');
    }
    $verifyManualPayment = $http('POST', $manualPaymentPath, ['csrf_token' => $manualPaymentToken]);
    $verifiedTransferOrder = $client->request('GET', $databasePath . '/' . rawurlencode($transferOrderId))->json();
    $manualPaymentEvents = array_values(array_filter($verifiedTransferOrder['payment_history'] ?? [], static fn (mixed $event): bool => is_array($event) && ($event['source'] ?? null) === 'php_admin_payment_verification'));
    if ($verifyManualPayment['status'] !== 303 || ($verifiedTransferOrder['payment']['status'] ?? null) !== 'paid'
        || ($verifiedTransferOrder['payment']['paid'] ?? false) !== true || count($manualPaymentEvents) !== 1
        || ($manualPaymentEvents[0]['staff_id'] ?? null) !== 'HTTP-SMOKE-MANAGER'
        || ($manualPaymentEvents[0]['method'] ?? null) !== 'bank_transfer'
        || ($manualPaymentEvents[0]['verification'] ?? null) !== 'manual') {
        throw new RuntimeException('Manual payment verification did not record status, method and staff audit.');
    }
    $repeatManualPayment = $http('POST', $manualPaymentPath, ['csrf_token' => $manualPaymentToken]);
    $verifiedTransferAgain = $client->request('GET', $databasePath . '/' . rawurlencode($transferOrderId))->json();
    $manualPaymentEventsAgain = array_values(array_filter($verifiedTransferAgain['payment_history'] ?? [], static fn (mixed $event): bool => is_array($event) && ($event['source'] ?? null) === 'php_admin_payment_verification'));
    if ($repeatManualPayment['status'] !== 303 || count($manualPaymentEventsAgain) !== 1) throw new RuntimeException('Repeated manual payment verification duplicated payment audit.');

    $reviewAdminRepository = new ReviewRepository($client, $database);
    $submittedReviews = array_values(array_filter($reviewAdminRepository->allForAdmin(), static fn (array $item): bool => ($item['reviewer_name'] ?? '') === 'HTTP Reviewer'));
    if (count($submittedReviews) !== 1) throw new RuntimeException('Could not find the review submitted by the HTTP smoke guest.');
    $submittedReview = $submittedReviews[0];
    $reviewAdminPage = $http('GET', '/admin/reviews');
    if ($reviewAdminPage['status'] !== 200 || !str_contains($reviewAdminPage['body'], 'HTTP Reviewer')
        || !str_contains($reviewAdminPage['body'], '&lt;script&gt;alert(1)&lt;/script&gt;')
        || !preg_match('/name="csrf_token"\s+value="([^"]+)"/', $reviewAdminPage['body'], $reviewAdminCsrf)) {
        throw new RuntimeException('Manager review moderation page did not show escaped review data.');
    }
    $reviewAdminToken = html_entity_decode($reviewAdminCsrf[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $moderationPath = '/admin/reviews/' . rawurlencode((string) $submittedReview['_id']) . '/visibility';
    $replyPath = '/admin/reviews/' . rawurlencode((string) $submittedReview['_id']) . '/reply';
    $replyText = 'Cảm ơn <script>alert(2)</script>!';
    $invalidReply = $http('POST', $replyPath, ['csrf_token' => 'invalid-token', 'content' => $replyText]);
    $afterInvalidReply = array_values(array_filter($reviewAdminRepository->allForAdmin(), static fn (array $item): bool => ($item['_id'] ?? '') === $submittedReview['_id']));
    if ($invalidReply['status'] !== 303 || count($afterInvalidReply) !== 1 || isset($afterInvalidReply[0]['admin_response'])) throw new RuntimeException('Review reply accepted an invalid CSRF token.');
    $sendReply = $http('POST', $replyPath, ['csrf_token' => $reviewAdminToken, 'content' => $replyText]);
    if ($sendReply['status'] !== 303) throw new RuntimeException('Manager could not save a review reply.');
    $repliedReviews = array_values(array_filter($reviewAdminRepository->allForAdmin(), static fn (array $item): bool => ($item['_id'] ?? '') === $submittedReview['_id']));
    if (count($repliedReviews) !== 1 || ($repliedReviews[0]['admin_response']['content'] ?? null) !== $replyText
        || ($repliedReviews[0]['admin_response']['staff_id'] ?? null) !== 'HTTP-SMOKE-MANAGER') throw new RuntimeException('Review reply was not persisted with staff attribution.');
    $publicRepliedProduct = $http('GET', '/products/' . rawurlencode((string) $product['legacy_id']));
    if ($publicRepliedProduct['status'] !== 200 || !str_contains($publicRepliedProduct['body'], 'Cảm ơn &lt;script&gt;alert(2)&lt;/script&gt;!')
        || str_contains($publicRepliedProduct['body'], 'Cảm ơn <script>alert(2)</script>!')) throw new RuntimeException('Public product page did not safely display the store reply.');
    $invalidModeration = $http('POST', $moderationPath, ['csrf_token' => 'invalid-token', 'active' => '0']);
    $afterInvalidModeration = array_values(array_filter($reviewAdminRepository->allForAdmin(), static fn (array $item): bool => ($item['_id'] ?? '') === $submittedReview['_id']));
    if ($invalidModeration['status'] !== 303 || count($afterInvalidModeration) !== 1 || ($afterInvalidModeration[0]['active'] ?? false) !== true) throw new RuntimeException('Review moderation accepted a bad CSRF token.');
    $hideReview = $http('POST', $moderationPath, ['csrf_token' => $reviewAdminToken, 'active' => '0']);
    $hiddenReviews = $reviewAdminRepository->allForAdmin();
    $hiddenReview = array_values(array_filter($hiddenReviews, static fn (array $item): bool => ($item['_id'] ?? '') === $submittedReview['_id']));
    if ($hideReview['status'] !== 303 || count($hiddenReview) !== 1 || ($hiddenReview[0]['active'] ?? true) !== false
        || count(array_filter((new ProductRepository($client, $database))->activeReviews(), static fn (array $item): bool => ($item['_id'] ?? '') === $submittedReview['_id'])) !== 0) {
        throw new RuntimeException('Manager could not hide a review or hidden review remained public.');
    }
    $restoreReview = $http('POST', $moderationPath, ['csrf_token' => $reviewAdminToken, 'active' => '1']);
    $restoredReviews = $reviewAdminRepository->allForAdmin();
    $restoredReview = array_values(array_filter($restoredReviews, static fn (array $item): bool => ($item['_id'] ?? '') === $submittedReview['_id']));
    if ($restoreReview['status'] !== 303 || count($restoredReview) !== 1 || ($restoredReview[0]['active'] ?? false) !== true) throw new RuntimeException('Manager could not restore a hidden review.');

    $adminDashboard = $http('GET', '/admin');
    if ($adminDashboard['status'] !== 200 || !str_contains($adminDashboard['body'], 'Quản lý sản phẩm')) {
        throw new RuntimeException('Legacy admin dashboard root did not show the PHP product management landing page.');
    }
    $adminForm = $http('GET', '/admin/products/new');
    if ($adminForm['status'] !== 200 || !preg_match('/name="csrf_token"\s+value="([^"]+)"/', $adminForm['body'], $adminCsrf)) throw new RuntimeException('Manager could not load product form.');
    $adminToken = html_entity_decode($adminCsrf[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jv9sAAAAASUVORK5CYII=', true);
    if (!is_string($png) || file_put_contents($uploadPath, $png) !== strlen($png)) throw new RuntimeException('Could not create valid HTTP upload fixture.');
    $uploadedProductResponse = $http('POST', '/admin/products/new', [
        'csrf_token' => $adminToken,
        'name' => 'HTTP Uploaded Photo Product', 'description' => 'Image upload integration smoke', 'category_code' => 'A',
        'variants_json' => json_encode([['variant_id' => '', 'size' => 'S', 'price' => 99000, 'stock' => 3, 'active' => true]], JSON_THROW_ON_ERROR),
    ], $uploadPath);
    if ($uploadedProductResponse['status'] !== 303 || ($uploadedProductResponse['headers']['location'] ?? null) !== '/admin/products') throw new RuntimeException('Manager product image upload did not save successfully.');
    $adminProducts = new ProductAdminService($client, $database, new ProductImageStorage(dirname(__DIR__) . '/var/uploads'));
    $uploadedProducts = array_values(array_filter($adminProducts->allProducts(), static fn (array $item): bool => ($item['name'] ?? '') === 'HTTP Uploaded Photo Product'));
    if (count($uploadedProducts) !== 1 || !str_starts_with((string) ($uploadedProducts[0]['images'][0]['path'] ?? ''), 'media/')) throw new RuntimeException('Uploaded image was not attached as product primary image.');
    $uploadedMediaFilename = basename((string) $uploadedProducts[0]['images'][0]['path']);
    $mediaResponse = $http('GET', '/' . $uploadedProducts[0]['images'][0]['path']);
    if ($mediaResponse['status'] !== 200 || !str_starts_with((string) ($mediaResponse['headers']['content-type'] ?? ''), 'image/png') || $mediaResponse['body'] !== $png) throw new RuntimeException('Stored product image was not safely served by the media endpoint.');

    $apiCartAdd = $apiHttp('POST', '/api/v1/cart/items', $customerToken, [
        'product_id' => $product['legacy_id'], 'variant_id' => $variant['variant_id'], 'quantity' => 1,
    ]);
    if ($apiCartAdd['status'] !== 200 || count($apiCartAdd['json']['data'] ?? []) !== 1) throw new RuntimeException('JWT cart API could not add an available product.');
    $apiCartSelect = $apiHttp('POST', '/api/v1/cart/items/select', $customerToken, [
        'variant_id' => $variant['variant_id'], 'selected' => true,
    ]);
    $cartVariants = array_values(array_filter($product['variants'] ?? [], static fn (array $candidate): bool => ($candidate['active'] ?? false) === true));
    $targetVariant = null;
    $extraVariant = null;
    foreach ($cartVariants as $candidate) {
        if (($candidate['variant_id'] ?? null) === $variant['variant_id']) continue;
        if ($targetVariant === null) $targetVariant = $candidate;
        elseif ($extraVariant === null) $extraVariant = $candidate;
    }
    if (!is_array($targetVariant) || !is_array($extraVariant)) throw new RuntimeException('Product fixture needs three active variants for cart API smoke.');
    $apiCartChangeSize = $apiHttp('POST', '/api/v1/cart/items/change-size', $customerToken, [
        'old_variant_id' => $variant['variant_id'], 'new_variant_id' => $targetVariant['variant_id'],
    ]);
    $apiCartAddExtra = $apiHttp('POST', '/api/v1/cart/items', $customerToken, [
        'product_id' => $product['legacy_id'], 'variant_id' => $extraVariant['variant_id'], 'quantity' => 1,
    ]);
    $apiCartSelectAll = $apiHttp('POST', '/api/v1/cart/items/select-all', $customerToken, ['selected' => true]);
    $apiCartRemoveExtra = $apiHttp('POST', '/api/v1/cart/items/remove', $customerToken, ['variant_id' => $extraVariant['variant_id']]);
    $apiCartDeselectAll = $apiHttp('POST', '/api/v1/cart/items/select-all', $customerToken, ['selected' => false]);
    $apiCartSelectTarget = $apiHttp('POST', '/api/v1/cart/items/select', $customerToken, [
        'variant_id' => $targetVariant['variant_id'], 'selected' => true,
    ]);
    $apiCartUpdate = $apiHttp('POST', '/api/v1/cart/items/update', $customerToken, [
        'variant_id' => $targetVariant['variant_id'], 'quantity' => 2,
    ]);
    if ($apiCartSelect['status'] !== 200 || $apiCartChangeSize['status'] !== 200
        || $apiCartAddExtra['status'] !== 200 || $apiCartSelectAll['status'] !== 200
        || $apiCartRemoveExtra['status'] !== 200 || $apiCartDeselectAll['status'] !== 200
        || $apiCartSelectTarget['status'] !== 200 || $apiCartUpdate['status'] !== 200
        || count($apiCartUpdate['json']['data'] ?? []) !== 1
        || ($apiCartUpdate['json']['data'][0]['variant_id'] ?? null) !== $targetVariant['variant_id']
        || ($apiCartUpdate['json']['data'][0]['quantity'] ?? null) !== 2
        || ($apiCartUpdate['json']['data'][0]['selected'] ?? false) !== true) {
        throw new RuntimeException('JWT cart selection, size change, removal or quantity update failed.');
    }
    $apiPreview = $apiHttp('POST', '/api/v1/checkout/preview', $customerToken, ['shipping_code' => 'BD']);
    if ($apiPreview['status'] !== 200 || ($apiPreview['json']['data']['grand_total'] ?? 0) <= 0) throw new RuntimeException('JWT checkout preview did not calculate a valid total.');
    $idempotencyKey = bin2hex(random_bytes(16));
    $checkoutBody = [
        'receiver' => ['name' => 'HTTP Reset Customer', 'phone' => '0900000000', 'email' => 'http-reset@example.test', 'address' => 'Test API address', 'note' => 'JWT checkout smoke'],
        'shipping_code' => 'BD', 'payment_method' => 'cod',
    ];
    $currentProductBeforeApiCheckout = $client->request('GET', $databasePath . '/' . rawurlencode((string) $product['_id']))->json();
    $stockBeforeApiCheckout = null;
    foreach ($currentProductBeforeApiCheckout['variants'] ?? [] as $currentVariant) {
        if (($currentVariant['variant_id'] ?? null) === $targetVariant['variant_id']) $stockBeforeApiCheckout = (int) $currentVariant['stock'];
    }
    if (!is_int($stockBeforeApiCheckout)) throw new RuntimeException('Could not read product stock before JWT checkout.');
    $apiCheckout = $apiHttp('POST', '/api/v1/checkout', $customerToken, $checkoutBody, ['Idempotency-Key' => $idempotencyKey]);
    $apiCheckoutId = $apiCheckout['json']['data']['id'] ?? null;
    if ($apiCheckout['status'] !== 201 || !is_string($apiCheckoutId) || $apiCheckoutId === '') throw new RuntimeException('JWT checkout did not create an order.');
    $storedApiOrder = $client->request('GET', $databasePath . '/' . rawurlencode($apiCheckoutId))->json();
    $emptyApiCart = $apiHttp('GET', '/api/v1/cart', $customerToken);
    if (($storedApiOrder['customer']['customer_id'] ?? null) !== 'HTTP-RESET-CUSTOMER'
        || ($storedApiOrder['meta']['checkout']['phase'] ?? null) !== 'completed'
        || (int) ($storedApiOrder['items'][0]['quantity'] ?? 0) !== 2
        || ($storedApiOrder['items'][0]['variant_id'] ?? null) !== $targetVariant['variant_id']
        || ($emptyApiCart['json']['data'] ?? null) !== []) {
        throw new RuntimeException('JWT checkout did not bind the order to the token customer and clear purchased cart items.');
    }
    $updatedApiProduct = $client->request('GET', $databasePath . '/' . rawurlencode((string) $product['_id']))->json();
    $remainingApiStock = null;
    foreach ($updatedApiProduct['variants'] ?? [] as $updatedVariant) {
        if (($updatedVariant['variant_id'] ?? null) === $targetVariant['variant_id']) $remainingApiStock = (int) $updatedVariant['stock'];
    }
    if ($remainingApiStock !== $stockBeforeApiCheckout - 2) throw new RuntimeException('JWT checkout did not reserve the expected stock quantity.');
    $apiCheckoutReplay = $apiHttp('POST', '/api/v1/checkout', $customerToken, $checkoutBody, ['Idempotency-Key' => $idempotencyKey]);
    if ($apiCheckoutReplay['status'] !== 201 || ($apiCheckoutReplay['json']['data']['id'] ?? null) !== $apiCheckoutId) {
        throw new RuntimeException('JWT checkout replay did not return the same idempotent order.');
    }
    $apiCheckoutConflict = $apiHttp('POST', '/api/v1/checkout', $customerToken, $checkoutBody + ['ignored' => 'different'], ['Idempotency-Key' => $idempotencyKey]);
    if ($apiCheckoutConflict['status'] !== 201 || ($apiCheckoutConflict['json']['data']['id'] ?? null) !== $apiCheckoutId) {
        throw new RuntimeException('Unknown extra request fields changed the idempotent checkout fingerprint.');
    }
    $apiCheckoutMismatch = $apiHttp('POST', '/api/v1/checkout', $customerToken, [
        ...$checkoutBody, 'receiver' => [...$checkoutBody['receiver'], 'address' => 'Different address'],
    ], ['Idempotency-Key' => $idempotencyKey]);
    if ($apiCheckoutMismatch['status'] !== 422) throw new RuntimeException('JWT checkout reused an idempotency key with different order data.');

    $invalidImagePath = '/tmp/product-upload-smoke-' . bin2hex(random_bytes(6)) . '.txt';
    $oversizedImagePath = '/tmp/product-upload-smoke-' . bin2hex(random_bytes(6)) . '.png';
    file_put_contents($invalidImagePath, '<?php echo "not an image";');
    try {
        $invalidUpload = $http('POST', '/admin/products/new', [
            'csrf_token' => $adminToken,
            'name' => 'Rejected Executable Upload', 'description' => '', 'category_code' => 'A',
            'variants_json' => json_encode([['variant_id' => '', 'size' => 'S', 'price' => 1, 'stock' => 1, 'active' => true]], JSON_THROW_ON_ERROR),
        ], $invalidImagePath);
        $afterInvalid = array_values(array_filter($adminProducts->allProducts(), static fn (array $item): bool => ($item['name'] ?? '') === 'Rejected Executable Upload'));
        if ($invalidUpload['status'] !== 303 || count($afterInvalid) !== 0) throw new RuntimeException('Non-image upload was not rejected.');
    } finally {
        if (is_file($invalidImagePath)) unlink($invalidImagePath);
    }
    file_put_contents($oversizedImagePath, str_repeat('A', 5_242_881));
    try {
        $oversizedUpload = $http('POST', '/admin/products/new', [
            'csrf_token' => $adminToken,
            'name' => 'Rejected Oversized Upload', 'description' => '', 'category_code' => 'A',
            'variants_json' => json_encode([['variant_id' => '', 'size' => 'S', 'price' => 1, 'stock' => 1, 'active' => true]], JSON_THROW_ON_ERROR),
        ], $oversizedImagePath);
        $afterOversized = array_values(array_filter($adminProducts->allProducts(), static fn (array $item): bool => ($item['name'] ?? '') === 'Rejected Oversized Upload'));
        if ($oversizedUpload['status'] !== 303 || count($afterOversized) !== 0) throw new RuntimeException('Oversized image upload was not rejected.');
    } finally {
        if (is_file($oversizedImagePath)) unlink($oversizedImagePath);
    }

    fwrite(STDOUT, "PASS: guest/customer delivery ownership and timelines, JWT staff delivery updates and customer tracking detail, checkout/order APIs, pagination/idempotency/stock, payment audits, review/password flows, admin orders/revenue/moderation, and safe image upload/media serving.\n");
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if ($created) {
        $response = $client->request('DELETE', $databasePath);
        if ($response->statusCode < 200 || $response->statusCode >= 300) throw new RuntimeException('Could not remove isolated HTTP smoke database.');
    }
    if (is_file($serverLog)) unlink($serverLog);
    if (is_file($uploadPath)) unlink($uploadPath);
    if (is_string($uploadedMediaFilename)) (new ProductImageStorage(dirname(__DIR__) . '/var/uploads'))->remove($uploadedMediaFilename);
}

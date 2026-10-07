<?php

declare(strict_types=1);

use App\Core\Router;
use App\Core\Response;
use App\Core\View;
use App\Catalog\CatalogController;
use App\Catalog\CatalogApiController;
use App\Catalog\ProductRepository;
use App\Catalog\ProductAdminController;
use App\Catalog\ProductAdminService;
use App\Catalog\ProductImageStorage;
use App\Catalog\ProductMediaController;
use App\Auth\AccountRepository;
use App\Auth\AccountService;
use App\Auth\AuthController;
use App\Auth\CustomerPasswordController;
use App\Auth\JwtApiController;
use App\Auth\JwtTokenService;
use App\Auth\JwtBearerAuthenticator;
use App\Cart\CustomerCommerceApiController;
use App\Cart\CartController;
use App\Cart\GuestCartService;
use App\Cart\MemberCartRepository;
use App\Cart\MemberCartService;
use App\Checkout\CheckoutController;
use App\Checkout\CheckoutRepository;
use App\Checkout\CheckoutService;
use App\Orders\OrderController;
use App\Orders\OrderAdminController;
use App\Orders\OrderRealtimeController;
use App\Orders\OrderWorkflowService;
use App\Reviews\ReviewController;
use App\Reviews\ReviewRepository;
use App\Reviews\ReviewService;
use App\Reviews\ReviewAdminController;
use App\Reporting\ReportingController;
use App\Reporting\RevenueReportService;
use App\Infrastructure\CouchDB\CouchDbClient;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/templates/helpers.php';

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_set_cookie_params([
    'httponly' => true,
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'samesite' => 'Lax',
    'path' => '/',
]);
session_start();

$router = new Router();
$router->get('/health', static fn (): array => ['status' => 'ok']);
$router->get('/about', static fn (): Response => new Response(View::render('pages/about', ['title' => 'Giới thiệu'])));

try {
    $client = new CouchDbClient(
        getenv('COUCHDB_URL') ?: 'http://couchdb:5984',
        getenv('COUCHDB_USER') ?: '',
        getenv('COUCHDB_PASSWORD') ?: '',
    );
    $database = getenv('COUCHDB_DATABASE') ?: 'retail_order_delivery_test';
    $router->get('/health/ready', static function () use ($client, $database): Response {
        try {
            $response = $client->request('GET', rawurlencode($database));
            if ($response->statusCode !== 200) {
                return new Response(
                    json_encode(['status' => 'not_ready', 'dependency' => 'couchdb'], JSON_THROW_ON_ERROR),
                    503,
                    'application/json; charset=utf-8',
                );
            }

            return new Response(
                json_encode(['status' => 'ready', 'dependency' => 'couchdb'], JSON_THROW_ON_ERROR),
                200,
                'application/json; charset=utf-8',
            );
        } catch (Throwable $exception) {
            error_log('CouchDB readiness check failed: ' . $exception->getMessage());
            return new Response(
                json_encode(['status' => 'not_ready', 'dependency' => 'couchdb'], JSON_THROW_ON_ERROR),
                503,
                'application/json; charset=utf-8',
            );
        }
    });
    $products = new ProductRepository($client, $database);
    $catalog = new CatalogController($products);
    $catalogApi = new CatalogApiController($products);
    $reviewRepository = new ReviewRepository($client, $database);
    $reviewService = new ReviewService($reviewRepository, $products);
    $reviews = new ReviewController($reviewService);
    $adminReviews = new ReviewAdminController($reviewRepository);
    $memberCarts = new MemberCartRepository($client, $database);
    $accountRepository = new AccountRepository($client, $database);
    $accountService = new AccountService($accountRepository);
    $jwtTokens = new JwtTokenService(
        getenv('JWT_SECRET') ?: '',
        getenv('JWT_ISSUER') ?: '',
        getenv('JWT_AUDIENCE') ?: '',
        (int) (getenv('JWT_TTL_SECONDS') ?: 900),
    );
    $auth = new AuthController($accountService, $products, $memberCarts);
    $customerPassword = new CustomerPasswordController($accountService, $products, $memberCarts);
    $cartService = (currentUser()['type'] ?? null) === 'customer'
        ? new MemberCartService($memberCarts, $products, (string) (currentUser()['legacy_id'] ?? ''))
        : new GuestCartService($products);
    $cart = new CartController($cartService);
    $user = currentUser();
    $customerId = ($user['type'] ?? null) === 'customer' ? (string) $user['legacy_id'] : null;
    $scope = $customerId !== null ? 'customer:' . $customerId : 'guest:' . session_id();
    $checkout = new CheckoutController(new CheckoutService(new CheckoutRepository($client, $database), $cartService, $customerId, $scope), $cartService);
    $orderRepository = new CheckoutRepository($client, $database);
    $orderWorkflow = new OrderWorkflowService($orderRepository);
    $orders = new OrderController($orderRepository, $orderWorkflow);
    $adminOrders = new OrderAdminController($orderRepository, $orderWorkflow);
    $realtimeWaitMs = (int) (getenv('ORDER_SSE_CHANGES_TIMEOUT_MS') ?: 8000);
    $realtimeOrders = new OrderRealtimeController($orderRepository, $client, $database, $realtimeWaitMs);
    $reporting = new ReportingController(new RevenueReportService($orderRepository));
    $jwtBearer = new JwtBearerAuthenticator($jwtTokens, $accountRepository);
    $jwtApi = new JwtApiController($accountService, $jwtTokens, $jwtBearer, $orderRepository, $orderWorkflow, $reviewService, new RevenueReportService($orderRepository));
    $commerceApi = new CustomerCommerceApiController($jwtBearer, $memberCarts, $products, $orderRepository);
    $imageStorage = new ProductImageStorage(dirname(__DIR__) . '/var/uploads');
    $adminProducts = new ProductAdminController(new ProductAdminService($client, $database, $imageStorage));
    $productMedia = new ProductMediaController($imageStorage);
    $router->get('/login', [$auth, 'loginForm']);
    $router->post('/login', [$auth, 'login']);
    $router->post('/api/auth/token', [$jwtApi, 'issueToken']);
    $router->get('/api/v1/me', [$jwtApi, 'me']);
    $router->get('/api/admin/revenue/stats', [$jwtApi, 'revenueStats']);
    $router->get('/api/admin/orders', [$jwtApi, 'staffOrders']);
    $router->get('/api/admin/orders/{id}', [$jwtApi, 'staffOrderDetail']);
    $router->post('/api/admin/orders/{id}/status', [$jwtApi, 'staffUpdateOrderStatus']);
    $router->post('/api/admin/orders/{id}/delivery', [$jwtApi, 'staffUpdateDeliveryStatus']);
    $router->post('/api/admin/orders/{id}/payment/cod-collected', [$jwtApi, 'staffRecordCodPayment']);
    $router->post('/api/admin/orders/{id}/payment/verify', [$jwtApi, 'staffVerifyManualPayment']);
    $router->get('/api/v1/orders', [$jwtApi, 'customerOrders']);
    $router->get('/api/v1/orders/{id}', [$jwtApi, 'customerOrderDetail']);
    $router->post('/api/v1/orders/{id}/confirm-received', [$jwtApi, 'customerConfirmReceived']);
    $router->get('/api/v1/cart', [$commerceApi, 'cart']);
    $router->post('/api/v1/cart/items', [$commerceApi, 'add']);
    $router->post('/api/v1/cart/items/update', [$commerceApi, 'update']);
    $router->post('/api/v1/cart/items/select', [$commerceApi, 'select']);
    $router->post('/api/v1/cart/items/select-all', [$commerceApi, 'selectAll']);
    $router->post('/api/v1/cart/items/change-size', [$commerceApi, 'changeVariant']);
    $router->post('/api/v1/cart/items/remove', [$commerceApi, 'remove']);
    $router->post('/api/v1/checkout/preview', [$commerceApi, 'checkoutPreview']);
    $router->post('/api/v1/checkout', [$commerceApi, 'checkout']);
    $router->get('/api/v1/products', [$catalogApi, 'index']);
    $router->get('/api/v1/products/{id}', [$catalogApi, 'detail']);
    $router->post('/api/v1/products/{id}/reviews', [$jwtApi, 'submitCustomerReview']);
    $router->get('/register', [$auth, 'registerForm']);
    $router->post('/register', [$auth, 'register']);
    $router->post('/logout', [$auth, 'logout']);
    $router->get('/account/password', [$customerPassword, 'form']);
    $router->post('/account/password', [$customerPassword, 'change']);
    $router->get('/password-help', [$customerPassword, 'help']);
    $router->get('/', [$catalog, 'index']);
    $router->get('/products/{id}', [$catalog, 'detail']);
    $router->get('/media/{file}', [$productMedia, 'show']);
    $router->post('/products/{id}/reviews', [$reviews, 'submit']);
    $router->get('/admin/reviews', [$adminReviews, 'index']);
    $router->post('/admin/reviews/{id}/visibility', [$adminReviews, 'setVisibility']);
    $router->post('/admin/reviews/{id}/reply', [$adminReviews, 'reply']);
    $router->get('/cart', [$cart, 'index']);
    $router->post('/cart/items/add', [$cart, 'add']);
    $router->post('/cart/items/update', [$cart, 'update']);
    $router->post('/cart/items/change-size', [$cart, 'changeVariant']);
    $router->post('/cart/items/select', [$cart, 'select']);
    $router->post('/cart/items/select-all', [$cart, 'selectAll']);
    $router->post('/cart/items/remove', [$cart, 'remove']);
    $router->get('/checkout', [$checkout, 'index']);
    $router->post('/checkout', [$checkout, 'submit']);
    $router->get('/account/orders', [$orders, 'index']);
    $router->get('/account/orders/{id}/events', [$realtimeOrders, 'customerEvents']);
    $router->get('/account/orders/{id}', [$orders, 'detail']);
    $router->post('/account/orders/{id}/confirm-received', [$orders, 'confirmReceived']);
    $router->get('/orders', [$orders, 'guestIndex']);
    $router->get('/orders/{id}/events', [$realtimeOrders, 'guestEvents']);
    $router->get('/orders/{id}', [$orders, 'guestDetail']);
    $router->post('/orders/{id}/confirm-received', [$orders, 'confirmGuestReceived']);
    $router->get('/admin/orders', [$adminOrders, 'index']);
    $router->get('/admin/orders/{id}', [$adminOrders, 'detail']);
    $router->post('/admin/orders/{id}/status', [$adminOrders, 'updateStatus']);
    $router->post('/admin/orders/{id}/delivery', [$adminOrders, 'updateDeliveryStatus']);
    $router->post('/admin/orders/{id}/payment/collect', [$adminOrders, 'recordCodCollected']);
    $router->post('/admin/orders/{id}/payment/verify', [$adminOrders, 'verifyManualPayment']);
    $router->get('/admin/revenue', [$reporting, 'revenue']);
    $router->get('/admin/revenue/stats', [$reporting, 'stats']);
    // Legacy Dashboard.Index rendered the admin product list; keep its area root as an alias.
    $router->get('/admin', [$adminProducts, 'index']);
    $router->get('/admin/products', [$adminProducts, 'index']);
    $router->get('/admin/products/new', [$adminProducts, 'form']);
    $router->post('/admin/products/new', [$adminProducts, 'save']);
    $router->get('/admin/products/{id}/edit', [$adminProducts, 'form']);
    $router->post('/admin/products/{id}/edit', [$adminProducts, 'save']);
    $router->post('/admin/products/{id}/delete', [$adminProducts, 'delete']);
    $router->post('/admin/products/{id}/restore', [$adminProducts, 'restore']);
} catch (Throwable $exception) {
    error_log('Application bootstrap failed: ' . $exception->getMessage());
    $apiUnavailable = static fn (): Response => new Response(
        json_encode(['error' => 'Service temporarily unavailable.'], JSON_THROW_ON_ERROR),
        503,
        'application/json; charset=utf-8',
    );
    $router->post('/api/auth/token', $apiUnavailable);
    $router->get('/api/v1/me', $apiUnavailable);
    $router->get('/api/admin/revenue/stats', $apiUnavailable);
    $router->get('/api/admin/orders', $apiUnavailable);
    $router->get('/api/admin/orders/{id}', $apiUnavailable);
    $router->post('/api/admin/orders/{id}/status', $apiUnavailable);
    $router->post('/api/admin/orders/{id}/delivery', $apiUnavailable);
    $router->post('/api/admin/orders/{id}/payment/cod-collected', $apiUnavailable);
    $router->post('/api/admin/orders/{id}/payment/verify', $apiUnavailable);
    $router->get('/api/v1/orders', $apiUnavailable);
    $router->get('/api/v1/orders/{id}', $apiUnavailable);
    $router->post('/api/v1/orders/{id}/confirm-received', $apiUnavailable);
    $router->get('/api/v1/cart', $apiUnavailable);
    $router->post('/api/v1/cart/items', $apiUnavailable);
    $router->post('/api/v1/cart/items/update', $apiUnavailable);
    $router->post('/api/v1/cart/items/select', $apiUnavailable);
    $router->post('/api/v1/cart/items/select-all', $apiUnavailable);
    $router->post('/api/v1/cart/items/change-size', $apiUnavailable);
    $router->post('/api/v1/cart/items/remove', $apiUnavailable);
    $router->post('/api/v1/checkout/preview', $apiUnavailable);
    $router->post('/api/v1/checkout', $apiUnavailable);
    $router->get('/api/v1/products', $apiUnavailable);
    $router->get('/api/v1/products/{id}', $apiUnavailable);
    $router->post('/api/v1/products/{id}/reviews', $apiUnavailable);
    $router->get('/health/ready', static fn (): Response => new Response(
        json_encode(['status' => 'not_ready', 'dependency' => 'couchdb'], JSON_THROW_ON_ERROR),
        503,
        'application/json; charset=utf-8',
    ));
    $router->get('/', static fn (): Response => new Response(
        '<!doctype html><html lang="vi"><meta charset="utf-8"><title>Lỗi dịch vụ</title><body><h1>Chưa thể tải danh mục</h1><p>Vui lòng kiểm tra trạng thái CouchDB và thử tải lại.</p></body></html>',
        503,
    ));
    $router->get('/products/{id}', static fn (): Response => new Response(
        '<!doctype html><html lang="vi"><meta charset="utf-8"><title>Lỗi dịch vụ</title><body><h1>Chưa thể tải sản phẩm</h1><p>Vui lòng thử lại sau.</p></body></html>',
        503,
    ));
    $router->get('/media/{file}', static fn (): Response => new Response('', 404, 'text/plain; charset=utf-8'));
    $router->post('/products/{id}/reviews', static fn (): Response => new Response(
        '<!doctype html><html lang="vi"><meta charset="utf-8"><title>Lỗi dịch vụ</title><body><h1>Chưa thể gửi đánh giá</h1><p>Vui lòng thử lại sau.</p></body></html>',
        503,
    ));
    $router->get('/admin/reviews', $unavailable);
    $router->post('/admin/reviews/{id}/visibility', $unavailable);
    $router->post('/admin/reviews/{id}/reply', $unavailable);
    $router->get('/admin', $unavailable);
    $router->get('/cart', static fn (): Response => new Response(
        '<!doctype html><html lang="vi"><meta charset="utf-8"><title>Lỗi dịch vụ</title><body><h1>Chưa thể tải giỏ hàng</h1><p>Vui lòng thử lại sau.</p></body></html>',
        503,
    ));
    $router->post('/cart/items/select-all', static fn (): Response => new Response('Dịch vụ giỏ hàng tạm thời chưa sẵn sàng.', 503));
    $unavailable = static fn (): Response => new Response(
        '<!doctype html><html lang="vi"><meta charset="utf-8"><title>Lỗi dịch vụ</title><body><h1>Dịch vụ tài khoản tạm thời chưa sẵn sàng</h1><p>Vui lòng thử lại sau.</p></body></html>',
        503,
    );
    $router->get('/login', $unavailable);
    $router->post('/login', $unavailable);
    $router->get('/register', $unavailable);
    $router->post('/register', $unavailable);
    $router->post('/logout', $unavailable);
    $router->get('/account/password', $unavailable);
    $router->post('/account/password', $unavailable);
    $router->get('/password-help', $unavailable);
    $router->get('/checkout', $unavailable);
    $router->post('/checkout', $unavailable);
    $router->get('/account/orders', $unavailable);
    $router->get('/account/orders/{id}/events', $unavailable);
    $router->get('/account/orders/{id}', $unavailable);
    $router->post('/account/orders/{id}/confirm-received', $unavailable);
    $router->get('/orders', $unavailable);
    $router->get('/orders/{id}/events', $unavailable);
    $router->get('/orders/{id}', $unavailable);
    $router->post('/orders/{id}/confirm-received', $unavailable);
    $router->get('/admin/orders', $unavailable);
    $router->get('/admin/orders/{id}', $unavailable);
    $router->post('/admin/orders/{id}/status', $unavailable);
    $router->post('/admin/orders/{id}/delivery', $unavailable);
    $router->post('/admin/orders/{id}/payment/collect', $unavailable);
    $router->post('/admin/orders/{id}/payment/verify', $unavailable);
    $router->get('/admin/revenue', $unavailable);
    $router->get('/admin/revenue/stats', $unavailable);
    $router->get('/admin/products', $unavailable);
    $router->get('/admin/products/new', $unavailable);
    $router->post('/admin/products/new', $unavailable);
    $router->get('/admin/products/{id}/edit', $unavailable);
    $router->post('/admin/products/{id}/edit', $unavailable);
    $router->post('/admin/products/{id}/delete', $unavailable);
    $router->post('/admin/products/{id}/restore', $unavailable);
}
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$requestPath = is_string($requestPath) ? $requestPath : '/';
$currentUser = currentUser();
if (($currentUser['password_reset_required'] ?? false) === true
    && !str_starts_with($requestPath, '/api/')
    && !in_array($requestPath, ['/account/password', '/logout', '/health', '/health/ready'], true)) {
    http_response_code(303);
    header('Location: /account/password');
    exit;
}
$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');

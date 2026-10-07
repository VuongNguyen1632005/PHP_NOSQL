<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Response;
use App\Checkout\CheckoutRepository;
use App\Orders\OrderWorkflowException;
use App\Orders\OrderWorkflowService;
use App\Reporting\RevenueReportService;
use App\Reviews\ReviewException;
use App\Reviews\ReviewService;

final class JwtApiController
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly JwtTokenService $tokens,
        private readonly JwtBearerAuthenticator $bearer,
        private readonly CheckoutRepository $orders,
        private readonly OrderWorkflowService $orderWorkflow,
        private readonly ReviewService $reviews,
        private readonly RevenueReportService $reports,
    ) {
    }

    /** @param array<string, string> $params
     *  @param array<string, mixed> $query
     */
    public function issueToken(array $params, array $query): Response
    {
        $input = $this->jsonInput();
        if ($input instanceof Response) return $input;
        $username = trim((string) ($input['username'] ?? $input['email'] ?? ''));
        $password = $input['password'] ?? null;
        if ($username === '' || !is_string($password) || $password === '') {
            return $this->json(['error' => 'username/email and password are required.'], 400);
        }

        try {
            $account = $this->accounts->authenticate($username, $password);
        } catch (AuthException) {
            return $this->json(['error' => 'Invalid username or password.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        if (($account['type'] ?? null) === 'customer'
            && (($account['auth']['requires_password_reset'] ?? false) === true)) {
            return $this->json(['error' => 'Change the temporary password through the web app before using the API.'], 403);
        }

        return $this->json($this->tokens->issue($account));
    }

    /** @param array<string, string> $params
     *  @param array<string, mixed> $query
     */
    public function me(array $params, array $query): Response
    {
        $account = $this->authenticatedAccount();
        if ($account instanceof Response) return $account;

        return $this->json([
            'id' => (string) ($account['_id'] ?? ''),
            'type' => (string) ($account['type'] ?? ''),
            'username' => (string) ($account['auth']['username'] ?? ''),
            'name' => (string) ($account['profile']['name'] ?? ''),
            'role' => ($account['type'] ?? null) === 'staff' ? (string) ($account['role'] ?? '') : 'customer',
        ]);
    }

    /** @param array<string, string> $params
     *  @param array<string, mixed> $query
     */
    public function revenueStats(array $params, array $query): Response
    {
        $account = $this->authenticatedAccount();
        if ($account instanceof Response) return $account;
        if (($account['type'] ?? null) !== 'staff'
            || !in_array(($account['role'] ?? null), ['staff', 'manager'], true)) {
            return $this->json(['error' => 'Staff access is required.'], 403);
        }

        return $this->json($this->reports->report());
    }

    /** @param array<string, string> $params
     *  @param array<string, mixed> $query
     */
    public function staffOrders(array $params, array $query): Response
    {
        $account = $this->authenticatedAccount();
        if ($account instanceof Response) return $account;
        if (($account['type'] ?? null) !== 'staff'
            || !in_array(($account['role'] ?? null), ['staff', 'manager'], true)) {
            return $this->json(['error' => 'Staff access is required.'], 403);
        }

        $status = $query['status'] ?? null;
        $knownStatuses = ['pending', 'confirmed', 'packing', 'shipping', 'delivered', 'cancelled'];
        if ($status !== null && (!is_string($status) || !in_array($status, $knownStatuses, true))) {
            return $this->json(['error' => 'status is not a supported order status.'], 400);
        }
        $cursor = $query['cursor'] ?? null;
        if ($cursor !== null && (!is_string($cursor) || $cursor === '' || strlen($cursor) > 4096
            || preg_match('/[\x00-\x1F\x7F]/', $cursor) === 1)) {
            return $this->json(['error' => 'cursor must be a valid page cursor.'], 400);
        }

        $page = $this->orders->ordersForStaffPage($status, $cursor);
        return $this->json([
            'data' => array_map(fn (array $order): array => $this->publicOrderSummary($order), $page['orders']),
            'next_cursor' => $page['next_bookmark'],
        ]);
    }

    /** @param array<string, string> $params
     *  @param array<string, mixed> $query
     */
    public function staffOrderDetail(array $params, array $query): Response
    {
        $account = $this->authenticatedAccount();
        if ($account instanceof Response) return $account;
        if (($account['type'] ?? null) !== 'staff'
            || !in_array(($account['role'] ?? null), ['staff', 'manager'], true)) {
            return $this->json(['error' => 'Staff access is required.'], 403);
        }

        $id = $params['id'] ?? '';
        $order = $id === '' || strlen($id) > 240 ? null : $this->orders->get($id);
        if ($order === null || ($order['type'] ?? null) !== 'order'
            || (isset($order['meta']['checkout']['phase']) && $order['meta']['checkout']['phase'] !== 'completed')) {
            return $this->notFound();
        }

        return $this->json(['data' => $this->publicOrderDetail($order)]);
    }

    /** @param array<string, string> $params
     *  @param array<string, mixed> $query
     */
    public function staffUpdateOrderStatus(array $params, array $query): Response
    {
        $account = $this->authenticatedAccount();
        if ($account instanceof Response) return $account;
        if (!$this->isStaffAccount($account)) return $this->json(['error' => 'Staff access is required.'], 403);

        $input = $this->jsonInput();
        if ($input instanceof Response) return $input;
        $id = $params['id'] ?? '';
        $order = $this->staffOrderForMutation($id);
        if ($order instanceof Response) return $order;

        try {
            $updated = $this->orderWorkflow->transition($id, is_string($input['status'] ?? null) ? $input['status'] : '', (string) ($account['legacy_id'] ?? ''));
        } catch (OrderWorkflowException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        }

        return $this->json(['data' => $this->publicOrderSummary($updated)]);
    }

    /** @param array<string, string> $params
     *  @param array<string, mixed> $query
     */
    public function staffUpdateDeliveryStatus(array $params, array $query): Response
    {
        $account = $this->authenticatedAccount();
        if ($account instanceof Response) return $account;
        if (!$this->isStaffAccount($account)) return $this->json(['error' => 'Staff access is required.'], 403);

        $input = $this->jsonInput();
        if ($input instanceof Response) return $input;
        if (!is_string($input['status'] ?? null)
            || (array_key_exists('note', $input) && !is_string($input['note']))) {
            return $this->json(['error' => 'status must be a delivery status and note must be text.'], 422);
        }
        $id = $params['id'] ?? '';
        $order = $this->staffOrderForMutation($id);
        if ($order instanceof Response) return $order;
        try {
            $updated = $this->orderWorkflow->updateDeliveryStatus(
                $id,
                $input['status'],
                (string) ($account['legacy_id'] ?? ''),
                (string) ($input['note'] ?? ''),
            );
        } catch (OrderWorkflowException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        }
        return $this->json(['data' => $this->publicOrderDetail($updated)]);
    }

    /** @param array<string, string> $params
     *  @param array<string, mixed> $query
     */
    public function staffRecordCodPayment(array $params, array $query): Response
    {
        return $this->staffRecordOrderPayment($params, true);
    }

    /** @param array<string, string> $params
     *  @param array<string, mixed> $query
     */
    public function staffVerifyManualPayment(array $params, array $query): Response
    {
        return $this->staffRecordOrderPayment($params, false);
    }

    /** @param array<string, string> $params */
    private function staffRecordOrderPayment(array $params, bool $cod): Response
    {
        $account = $this->authenticatedAccount();
        if ($account instanceof Response) return $account;
        if (!$this->isStaffAccount($account)) return $this->json(['error' => 'Staff access is required.'], 403);

        $id = $params['id'] ?? '';
        $order = $this->staffOrderForMutation($id);
        if ($order instanceof Response) return $order;
        try {
            $updated = $cod
                ? $this->orderWorkflow->recordCodCollected($id, (string) ($account['legacy_id'] ?? ''))
                : $this->orderWorkflow->verifyManualPayment($id, (string) ($account['legacy_id'] ?? ''));
        } catch (OrderWorkflowException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        }

        return $this->json(['data' => $this->publicOrderSummary($updated)]);
    }

    /** @return array<string, mixed>|Response */
    private function staffOrderForMutation(string $id): array|Response
    {
        $order = $id === '' || strlen($id) > 240 ? null : $this->orders->get($id);
        if ($order === null || ($order['type'] ?? null) !== 'order'
            || (isset($order['meta']['checkout']['phase']) && $order['meta']['checkout']['phase'] !== 'completed')) {
            return $this->notFound();
        }
        return $order;
    }

    /** @param array<string, mixed> $account */
    private function isStaffAccount(array $account): bool
    {
        return ($account['type'] ?? null) === 'staff'
            && in_array(($account['role'] ?? null), ['staff', 'manager'], true)
            && trim((string) ($account['legacy_id'] ?? '')) !== '';
    }

    /** @param array<string, string> $params
     *  @param array<string, mixed> $query
     */
    public function customerOrders(array $params, array $query): Response
    {
        $account = $this->authenticatedAccount();
        if ($account instanceof Response) return $account;
        if (($account['type'] ?? null) !== 'customer') {
            return $this->json(['error' => 'Customer access is required.'], 403);
        }

        $customerId = (string) ($account['legacy_id'] ?? '');
        if ($customerId === '') return $this->json(['error' => 'Customer account is invalid.'], 403);
        $cursor = $query['cursor'] ?? null;
        if ($cursor !== null && (!is_string($cursor) || $cursor === '' || strlen($cursor) > 4096
            || preg_match('/[\x00-\x1F\x7F]/', $cursor) === 1)) {
            return $this->json(['error' => 'cursor must be a valid page cursor.'], 400);
        }

        $page = $this->orders->ordersForCustomerPage($customerId, $cursor);
        return $this->json([
            'data' => array_map(fn (array $order): array => $this->publicOrderSummary($order), $page['orders']),
            'next_cursor' => $page['next_bookmark'],
        ]);
    }

    /** @param array<string, string> $params
     *  @param array<string, mixed> $query
     */
    public function customerOrderDetail(array $params, array $query): Response
    {
        $account = $this->authenticatedAccount();
        if ($account instanceof Response) return $account;
        if (($account['type'] ?? null) !== 'customer') {
            return $this->json(['error' => 'Customer access is required.'], 403);
        }

        $customerId = (string) ($account['legacy_id'] ?? '');
        $id = $params['id'] ?? '';
        if ($customerId === '' || $id === '' || strlen($id) > 240) return $this->notFound();
        $order = $this->orders->get($id);
        if ($order === null || ($order['type'] ?? null) !== 'order'
            || (string) ($order['customer']['customer_id'] ?? '') !== $customerId
            || ($order['guest_order'] ?? false) === true
            || (isset($order['meta']['checkout']['phase']) && $order['meta']['checkout']['phase'] !== 'completed')) {
            return $this->notFound();
        }

        return $this->json(['data' => $this->publicOrderDetail($order)]);
    }

    /** @param array<string, string> $params
     *  @param array<string, mixed> $query
     */
    public function customerConfirmReceived(array $params, array $query): Response
    {
        $account = $this->authenticatedAccount();
        if ($account instanceof Response) return $account;
        if (($account['type'] ?? null) !== 'customer') {
            return $this->json(['error' => 'Customer access is required.'], 403);
        }

        $customerId = (string) ($account['legacy_id'] ?? '');
        $id = $params['id'] ?? '';
        if ($customerId === '' || $id === '' || strlen($id) > 240) return $this->notFound();

        $order = $this->orders->get($id);
        if ($order === null || ($order['type'] ?? null) !== 'order'
            || (string) ($order['customer']['customer_id'] ?? '') !== $customerId
            || ($order['guest_order'] ?? false) === true
            || (isset($order['meta']['checkout']['phase']) && $order['meta']['checkout']['phase'] !== 'completed')) {
            return $this->notFound();
        }

        try {
            $confirmed = $this->orderWorkflow->confirmReceived($id, $customerId);
        } catch (OrderWorkflowException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        }

        $data = $this->publicOrderSummary($confirmed);
        $data['payment']['paid'] = (bool) ($confirmed['payment']['paid'] ?? false);
        return $this->json(['data' => $data]);
    }

    /** @param array<string, string> $params
     *  @param array<string, mixed> $query
     */
    public function submitCustomerReview(array $params, array $query): Response
    {
        $account = $this->authenticatedAccount();
        if ($account instanceof Response) return $account;
        if (($account['type'] ?? null) !== 'customer') {
            return $this->json(['error' => 'Customer access is required.'], 403);
        }

        $input = $this->jsonInput();
        if ($input instanceof Response) return $input;
        $reviewerName = trim((string) ($account['profile']['name'] ?? ''));
        $reviewUser = [
            'type' => 'customer',
            'legacy_id' => (string) ($account['legacy_id'] ?? ''),
            'name' => $reviewerName,
            'username' => (string) ($account['auth']['username'] ?? ''),
        ];
        try {
            $review = $this->reviews->submit(
                $params['id'] ?? '',
                '',
                $input['rating'] ?? null,
                is_string($input['content'] ?? null) ? $input['content'] : '',
                $reviewUser,
                '',
            );
        } catch (ReviewException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        }

        return $this->json(['data' => [
            'id' => (string) ($review['_id'] ?? ''),
            'product_id' => (string) ($review['product_id'] ?? ''),
            'reviewer_name' => (string) ($review['reviewer_name'] ?? ''),
            'rating' => (int) ($review['rating'] ?? 0),
            'content' => (string) ($review['content'] ?? ''),
            'created_at' => $review['created_at'] ?? null,
        ]], 201);
    }

    /** @return array<string, mixed>|Response */
    private function authenticatedAccount(): array|Response
    {
        return $this->bearer->account();
    }

    /** @param array<string, mixed> $order
     *  @return array<string, mixed>
     */
    private function publicOrderSummary(array $order): array
    {
        return [
            'id' => (string) ($order['_id'] ?? ''),
            'number' => (string) ($order['legacy_id'] ?? $order['_id'] ?? ''),
            'ordered_at' => $order['ordered_at'] ?? null,
            'status' => (string) ($order['status'] ?? ''),
            'item_count' => (int) ($order['totals']['total_quantity'] ?? 0),
            'grand_total' => (float) ($order['totals']['grand_total'] ?? 0),
            'payment' => [
                'method' => (string) ($order['payment']['method'] ?? ''),
                'status' => (string) ($order['payment']['status'] ?? ''),
            ],
        ];
    }

    /** @param array<string, mixed> $order
     *  @return array<string, mixed>
     */
    private function publicOrderDetail(array $order): array
    {
        $summary = $this->publicOrderSummary($order);
        $summary['items'] = array_map(static fn (array $item): array => [
            'product_id' => (string) ($item['product_id'] ?? ''),
            'product_name' => (string) ($item['product_name'] ?? ''),
            'variant_id' => (string) ($item['variant_id'] ?? ''),
            'size' => (string) ($item['size'] ?? ''),
            'quantity' => (int) ($item['quantity'] ?? 0),
            'unit_price' => (float) ($item['unit_price'] ?? 0),
            'line_total' => (float) ($item['line_total'] ?? 0),
        ], is_array($order['items'] ?? null) ? $order['items'] : []);
        $summary['totals'] = [
            'subtotal' => (float) ($order['totals']['subtotal'] ?? 0),
            'shipping_fee' => (float) ($order['totals']['shipping_fee'] ?? 0),
            'discount_amount' => (float) ($order['totals']['discount_amount'] ?? 0),
            'grand_total' => (float) ($order['totals']['grand_total'] ?? 0),
            'currency' => (string) ($order['totals']['currency'] ?? 'VND'),
        ];
        $tracking = is_array($order['delivery_tracking'] ?? null) ? $order['delivery_tracking'] : null;
        $summary['shipping'] = [
            'method_name' => (string) ($order['shipping']['method_name'] ?? ''),
            'tracking_code' => $tracking['tracking_code'] ?? ($order['shipping']['tracking_code'] ?? null),
            'estimated_delivery_date' => $tracking['estimated_delivery_date'] ?? ($order['shipping']['estimated_delivery_date'] ?? null),
        ];
        $summary['delivery_tracking'] = $tracking === null ? null : [
            'tracking_code' => (string) ($tracking['tracking_code'] ?? ''),
            'status' => (string) ($tracking['status'] ?? ''),
            'estimated_delivery_date' => $tracking['estimated_delivery_date'] ?? null,
            'delivered_at' => $tracking['delivered_at'] ?? null,
            'history' => array_map(static fn (array $event): array => [
                'status' => (string) ($event['status'] ?? ''),
                'at' => $event['at'] ?? null,
                'note' => (string) ($event['note'] ?? ''),
            ], is_array($tracking['history'] ?? null) ? $tracking['history'] : []),
        ];
        $summary['discount'] = [
            'code' => $order['discount']['code'] ?? null,
            'amount' => (float) ($order['discount']['amount'] ?? 0),
        ];
        $summary['receiver'] = [
            'name' => (string) ($order['receiver']['name'] ?? ''),
            'phone' => (string) ($order['receiver']['phone'] ?? ''),
            'email' => (string) ($order['receiver']['email'] ?? ''),
            'address' => (string) ($order['receiver']['address'] ?? ''),
        ];
        $summary['status_history'] = array_map(static fn (array $event): array => [
            'status' => (string) ($event['status'] ?? ''),
            'at' => $event['at'] ?? null,
        ], is_array($order['status_history'] ?? null) ? $order['status_history'] : []);
        $summary['note'] = (string) ($order['note'] ?? '');
        $summary['payment']['paid'] = (bool) ($order['payment']['paid'] ?? false);
        return $summary;
    }

    private function notFound(): Response
    {
        return $this->json(['error' => 'Order not found.'], 404);
    }

    /** @return array<string, mixed>|Response */
    private function jsonInput(): array|Response
    {
        $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
        if ($contentType !== 'application/json') return $this->json(['error' => 'Content-Type must be application/json.'], 415);
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > 8192) return $this->json(['error' => 'Request body is too large.'], 413);
        $body = file_get_contents('php://input');
        if (!is_string($body) || $body === '') return $this->json(['error' => 'A JSON request body is required.'], 400);
        try {
            $input = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'Request body must be valid JSON.'], 400);
        }
        if (!is_array($input) || array_is_list($input)) return $this->json(['error' => 'Request body must be a JSON object.'], 400);
        return $input;
    }

    /** @param array<string, mixed> $body
     *  @param array<string, string> $headers
     */
    private function json(array $body, int $status = 200, array $headers = []): Response
    {
        return new Response(
            json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $status,
            'application/json; charset=utf-8',
            $headers,
        );
    }
}

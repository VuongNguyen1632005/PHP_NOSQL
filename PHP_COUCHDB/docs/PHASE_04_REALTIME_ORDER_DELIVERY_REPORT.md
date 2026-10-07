# PHASE 04 – REALTIME ORDER & DELIVERY

## 1. Architecture

Staff cập nhật đơn qua luồng PHP hiện có. PHP ghi document Order vào CouchDB; endpoint SSE phía PHP theo dõi đúng document bằng `_changes`, rồi gửi snapshot đã lọc tới EventSource trong trang chi tiết đơn của customer. Browser không kết nối trực tiếp tới CouchDB.

Không thêm WebSocket, Node.js, Redis, worker nền, Fauxton hay dịch vụ mới.

## 2. SSE feasibility

Ứng dụng chạy PHP 8.3 trên Apache `mpm_prefork` với `mod_php`. SSE vô hạn sẽ giữ worker Apache; vì vậy endpoint dùng một lượt long-poll hữu hạn tối đa 8 giây rồi đóng response. EventSource tự kết nối lại sau 5 giây. PHP `max_execution_time` trong container là `0` (không giới hạn), nhưng thời gian chờ `_changes` được giới hạn riêng.

## 3. CouchDB `_changes` implementation

- Chỉ quan sát Order đang mở bằng `_doc_ids`; không poll toàn database.
- Tạo checkpoint bằng `since=now`, sau đó đọc lại document để tránh khe hở giữa checkpoint và snapshot ban đầu.
- Dùng `feed=longpoll` với timeout tối đa 8 giây. Khi document đổi, đọc snapshot mới và chỉ phát nếu payload khác snapshot ban đầu; khi không đổi, phát heartbeat cùng `last_seq`.
- Dùng sequence trong `Last-Event-ID` khi EventSource kết nối lại. Sequence không còn hợp lệ được đặt lại về checkpoint hiện tại và snapshot mới.
- Kiểm tra `connection_aborted()` sau lần flush đầu để dừng sớm khi browser đóng kết nối.

## 4. SSE endpoint

- Customer: `GET /account/orders/{id}/events`
- Guest: `GET /orders/{id}/events`
- Response dùng `text/event-stream`, `Cache-Control: no-cache, no-store, private`, `X-Accel-Buffering: no`; response có event ban đầu, heartbeat hoặc `order_updated` rồi đóng.
- Session được đóng trước khi stream bắt đầu để request SSE không giữ khóa session.

## 5. Authentication and ownership

- Customer phải đăng nhập bằng tài khoản customer, sở hữu `customer_id` khớp Order và Order phải ở checkout phase hoàn tất.
- Guest phải có Order ID trong `$_SESSION['_guest_order_ids']`, document phải được đánh dấu guest, không gắn customer và checkout đã hoàn tất.
- Order không tồn tại hoặc không thuộc quyền được trả 404. HTTP smoke xác nhận customer A không subscribe được Order B và guest không truy cập được Order guest khác.

## 6. Customer realtime UI

Order detail cập nhật order/payment/delivery badge, mã vận đơn, ngày dự kiến/đã giao, order timeline, delivery timeline và trạng thái nút xác nhận đã nhận. Timeline được dựng bằng DOM node và `textContent`; customer chỉ nhận dữ liệu hiển thị cho họ. Trang có snapshot server-rendered để tiếp tục dùng được khi JavaScript/SSE không hoạt động.

## 7. Event payload

`order_updated` chỉ gồm `order_id`, order `status`, `payment_status`, `delivery` (tracking code, delivery status, estimated date, delivered time, lịch sử gồm status/time/note) và order `status_history` (status/time). Payload không chứa phân công nhân viên, ghi chú nội bộ, thông tin xác thực CouchDB, URL cơ sở dữ liệu hay raw exception.

## 8. Reconnect/fallback

EventSource nhận `retry: 5000` và browser tự kết nối lại bằng `Last-Event-ID`. Nếu stream gặp lỗi, trang không hiện popup; nội dung vẫn có snapshot HTML và người dùng có thể tải lại để lấy dữ liệu hiện tại. Khi trình duyệt không hỗ trợ EventSource, chỉ báo nhắc tải lại trang. Chỉ báo trạng thái phân biệt đang theo dõi với thời gian chờ lượt đồng bộ kế tiếp.

Đã tải lại trang sau khi đơn hoàn tất và xác nhận trạng thái delivered, tracking cùng cả hai timeline được khôi phục từ snapshot server; EventSource mở lại. Không mô phỏng ngắt/kết nối lại mạng ở tầng browser: **MANUAL/UNVERIFIED**.

## 9. Resource considerations

Apache cấu hình `MaxRequestWorkers 150`, `mpm_prefork`, `mod_php`. Mỗi tab đang chờ giữ một worker trong tối đa 8 giây cho một lượt `_changes`, sau đó EventSource đợi 5 giây trước lượt tiếp theo. Đây là thời gian hữu hạn, không phải connection vô hạn; tải đồng thời cao vẫn có thể chiếm nhiều worker. CouchDbClient timeout request là 30 giây, lớn hơn timeout `_changes` 8 giây. Không có busy-wait hay vòng lặp poll liên tục trong một request.

## 10. Tests

- `php -l` — PASS cho mọi PHP file đã thay đổi.
- `node --check public/assets/js/order-realtime.js` — PASS.
- `composer checkout:smoke` — PASS.
- `composer checkout:http-smoke` — PASS; gồm SSE content type, guest/customer ownership, customer A → Order B bị từ chối, payload không lộ staff/internal fields, initial snapshot/heartbeat, live order update, live delivery update, failed delivery/retry và delivered.
- `composer catalog:admin-smoke` — PASS.
- `composer auth:jwt-smoke` — PASS.
- `composer couchdb:diagnostics` — PASS (read-only).

Các browser fixture được tạo trong database `_test`, sau đó đã xóa customer, manager và order fixture. Script fixture tạm đã được gỡ khỏi project.

## 11. Browser verification

Dùng hai phiên trình duyệt riêng: customer tại `localhost:8081`, manager tại `127.0.0.1:8081`.

- Manager chuyển order `packing → shipping`; customer đang mở detail tự thấy badge, order timeline, mã vận đơn và ngày dự kiến mà không reload.
- Manager cập nhật `picked_up → in_transit → out_for_delivery`; customer nhận từng trạng thái và dòng lịch sử ngay.
- Manager ghi `failed_delivery` cùng ghi chú “Khách chưa nghe máy”; customer thấy badge cảnh báo, ghi chú và event thất bại realtime.
- Manager retry về `in_transit`, tiếp tục `out_for_delivery`, rồi `delivered`; customer thấy đầy đủ các event retry, giao thành công, delivered time và trạng thái COD vẫn chưa thanh toán.
- Success message hiện ở form manager. Error ownership, guest ownership và payload privacy được kiểm tra trong HTTP smoke.
- Refresh customer sau khi hoàn tất khôi phục dữ liệu và mở lại EventSource. Offline/online reconnect chưa mô phỏng.

## 12. Files modified

- `src/Core/StreamedResponse.php` (mới)
- `src/Core/Router.php`
- `src/Orders/OrderRealtimeController.php` (mới)
- `public/index.php`
- `templates/orders/detail.php`
- `templates/orders/delivery_timeline.php`
- `templates/layout.php`
- `public/assets/js/order-realtime.js` (mới)
- `scripts/http_smoke_checkout.php`
- `docs/PHASE_04_REALTIME_ORDER_DELIVERY_REPORT.md` (mới)

## 13. Remaining issues

- Ngắt/kết nối lại mạng thực chưa được mô phỏng trong browser; refresh và khởi tạo lại EventSource đã được xác minh.
- Mỗi kết nối tạm thời chiếm một Apache prefork worker; cần đánh giá riêng nếu lượng tab realtime đồng thời tăng cao.
- Order snapshot hiện không lưu ảnh sản phẩm; UI tiếp tục dùng placeholder hiện có, không đổi schema.

## 14. Ready for Phase 5?

**YES.** Các kịch bản realtime order/delivery, failed delivery, retry, delivered, ownership và customer 390px đều được xác minh; toàn bộ smoke/regression tests yêu cầu đều PASS. Network offline/online là giới hạn kiểm tra thủ công đã ghi nhận. Không triển khai Phase 5 trong công việc này.

# PHASE 01 – PHP PROJECT STABILIZATION

Ngày kiểm tra: 2026-10-07  
Project: `D:\PHP_NOSQL\PHP_COUCHDB`

## 1. Project status before changes

- Đây là ứng dụng PHP 8.3 thuần, không dùng framework; CouchDB là database chính và `CouchDbClient` gọi REST bằng PHP cURL.
- Luồng phụ thuộc được xác minh trong `public/index.php`: `Browser/API → Router → Controller → Service → Repository → CouchDbClient → CouchDB`. Repository và service được khởi tạo rồi truyền vào controller; `Router::dispatch()` xử lý request. Catalog/API có repository; checkout và order dùng `CheckoutRepository`; giỏ guest dựa trên session, giỏ member lưu CouchDB.
- Stack Docker đã chạy trước khi kiểm tra; không phát hiện lỗi chức năng có thể tái hiện cần sửa. Không thay đổi business workflow hoặc kiến trúc.
- Project không có thư mục metadata `.git`; báo cáo không thể dùng diff Git để liệt kê thay đổi.

## 2. Environment

- Docker Engine 29.7.2; Docker Compose v5.4.0.
- Container `app`: PHP 8.3.35, trạng thái `healthy`, cổng host `127.0.0.1:8081`.
- Container CouchDB: image 3.5.0, trạng thái `healthy`, cổng host `127.0.0.1:5984`.
- `APP_ENV=development`; ứng dụng kết nối DB `shopquan_ao_sql_migration_test`. DB có hậu tố `_test`, readiness endpoint trả 200.
- `.env.example` dùng tên DB `retail_order_delivery_test`, khác DB thật đang cấu hình. Không in user/password/JWT secret và không thay đổi cấu hình.
- `composer validate --no-check-publish` hợp lệ; Composer cảnh báo thiếu trường `license` trong metadata. Đây là cảnh báo metadata P3, không ảnh hưởng runtime.

## 3. Routes checked

Các nhóm route được đọc từ `public/index.php`; không tạo route mới.

- **Public:** `GET /`, `/about`, `/health`, `/health/ready`, `/products/{id}`, `/media/{file}`, `GET /api/v1/products`, `GET /api/v1/products/{id}`.
- **Authentication:** `GET/POST /login`, `GET/POST /register`, `POST /logout`, `GET/POST /account/password`, `GET /password-help`, `POST /api/auth/token`, `GET /api/v1/me`.
- **Customer:** `/cart` và các POST `/cart/items/*`; `/checkout`; `/account/orders`, `/account/orders/{id}` và confirm received; guest order routes `/orders*`; review submit; API `/api/v1/cart*`, `/api/v1/checkout*`, `/api/v1/orders*`, review submit.
- **Admin:** `/admin/products*`, `/admin/orders*`, `/admin/reviews*`, `/admin/revenue` và `/admin/revenue/stats`; role guards được kiểm tra qua smoke suite.
- **API staff/admin:** `/api/admin/orders*`, status/payment actions và `/api/admin/revenue/stats`.
- Request guest tới `GET /admin/orders` được chuyển hướng 302 tới luồng đăng nhập; đây là kết quả dự kiến.

## 4. Functions tested

- Health/readiness, trang chủ và API catalog trả HTTP 200; truy cập admin khi chưa đăng nhập bị redirect.
- JWT cấp/xác minh token, từ chối token bị sửa và issuer không đúng.
- Catalog: tạo/sửa sản phẩm, giữ variant ID, validation size/giá, ẩn/khôi phục và lọc public catalog.
- Checkout: recovery/idempotency, bảo vệ tồn kho khi cạnh tranh, ownership lịch sử đơn, staff-only status management, hủy và phục hồi, payment audit và compact marker.
- HTTP integration: guest và customer checkout/history/ownership, session/password flows, API JWT cart/order/review/checkout, staff order APIs, phân trang, idempotency, tồn kho, COD/chuyển khoản, admin orders/revenue/review moderation, upload ảnh và phục vụ media an toàn.
- CouchDB Mango diagnostics chạy `_explain`/truy vấn chỉ đọc cho active products, active reviews, pending orders.
- Smoke scripts tạo DB ngẫu nhiên riêng và có cleanup trong `finally`; ba smoke test chính kết thúc thành công.

## 5. Bugs found

- Không xác nhận được bug P0/P1 trong các luồng đã kiểm tra.
- `seed:verify` trả mã lỗi vì DB đang cấu hình có document ngoài bộ seed (script nêu một customer ID phát sinh). Đây là điều kiện bảo vệ của verifier, không phải lỗi runtime; lệnh không ghi seed hay xóa dữ liệu.
- Composer cảnh báo `composer.json` thiếu trường `license`; không ảnh hưởng chức năng.

## 6. Bugs fixed

- Không sửa code nghiệp vụ vì không có lỗi ứng dụng được tái hiện; tránh thay đổi không cần thiết.

## 7. Files modified

- `docs/PHASE_01_STABILIZATION_REPORT.md` — báo cáo giai đoạn này.
- Không sửa mã nguồn, cấu hình, dữ liệu CouchDB hoặc giao diện.

## 8. Tests executed

- `php -l` cho toàn bộ PHP trong `src`, `public`, `scripts` — PASS.
- `composer validate --no-check-publish` — PASS, có cảnh báo thiếu `license`.
- GET `/health`, `/health/ready`, `/`, `/api/v1/products` — PASS (200); GET `/admin/orders` — redirect dự kiến (302).
- `composer couchdb:diagnostics` — PASS; ba truy vấn dùng index trả kết quả.
- `composer auth:jwt-smoke` — PASS.
- `composer catalog:admin-smoke` — PASS.
- `composer checkout:smoke` — PASS.
- `composer checkout:http-smoke` — PASS.
- `composer seed:verify` — FAIL/không áp dụng trên DB hiện tại vì có document ngoài seed; script từ chối ghi dữ liệu. Không thử chạy `seed:import`.

## 9. PASS / FAIL / MANUAL TEST REQUIRED

- **PASS:** syntax, cấu hình Composer, health/readiness, JWT, catalog admin, checkout/service, HTTP integration và CouchDB query diagnostics.
- **FAIL:** `seed:verify` không đạt trên database hiện tại do database không còn chỉ chứa seed. Không có ghi/xóa dữ liệu do lần chạy này.
- **CẦN TEST THỦ CÔNG:** kiểm tra giao diện trình duyệt và thao tác thực tế với tài khoản customer/staff/manager trước khi demo. HTTP smoke đã kiểm tra endpoint và quyền ở mức tích hợp, nhưng không xác nhận trải nghiệm trực quan, nội dung form hay thao tác bàn phím trên trình duyệt.
- Chưa mô phỏng cố ý CouchDB unavailable hoặc lỗi HTTP 404/409 tại runtime đang chạy; không coi các tình huống này là PASS.

## 10. Remaining problems

- `seed:verify` cần database `_test` riêng, sạch theo đúng tập seed; không chạy seed import lên database đang chứa dữ liệu hiện tại.
- Tên DB trong `.env` khác template `.env.example`; runtime/readiness hiện tốt, nhưng cần chọn tên nhất quán khi chuẩn bị môi trường mới.
- UI cần được xác nhận thủ công trên trình duyệt; kiểm tra lỗi CouchDB unavailable/404/409 chưa có kết quả trong lượt này.

## 11. P2/P3 improvements intentionally postponed

- Bổ sung quy trình seed verification trên DB cô lập và thống nhất cách ghi tên database mẫu.
- Thêm kiểm tra tự động riêng cho các lỗi CouchDB 404/409/unavailable nếu cần baseline phủ lỗi sâu hơn.
- Bổ sung `license` trong metadata Composer nếu chủ project xác định giấy phép phân phối.
- Không triển khai delivery tracking, realtime/SSE/WebSocket, shipper, payment gateway, dashboard mới, Fauxton/NoSQL demo hoặc thay đổi workflow trong giai đoạn này.

## 12. Ready for Phase 2?

**YES, có điều kiện kiểm thử trình duyệt trước demo.** Các smoke test nghiệp vụ/API chính đều PASS và không cần bugfix để tiếp tục. Nên xử lý database riêng cho `seed:verify` và kiểm tra UI thủ công trước khi chốt demo. Báo cáo này không triển khai Phase 2.

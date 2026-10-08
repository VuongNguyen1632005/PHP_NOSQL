# TÓM TẮT BÀN GIAO PROJECT

Tài liệu này tóm tắt cách tiếp nhận và kiểm tra project. Kết quả test bên dưới là từ Phase reports đã lưu, không phải lần chạy mới trong ngày bàn giao.

**PROJECT NAME:** WEBTHOITRANG PHP + CouchDB (`composer.json`: `webthoitrang/php-couchdb`)

**PROJECT PATH:** `D:\PHP_NOSQL\PHP_COUCHDB`

**FINAL STATUS:** **PARTIAL** cho bàn giao tổng thể. Local/demo và Fauxton đã có browser/test evidence ở Phase 03B/06/07; runtime hiện tại chưa được kiểm tra lại. Render **NOT DEPLOYED**.

**TECH STACK:** PHP 8.3 thuần, Apache, HTML/CSS/JavaScript, Bootstrap local, Composer, Docker Compose, PHP cURL, CouchDB 3.5.0, session cho website, JWT HS256 cho API, SSE cho order detail realtime.

**DATABASE:** CouchDB; schema v2 JSON documents, Mango Query/index, design documents. Fauxton dùng local khi CouchDB Compose đang chạy.

**ROLES:** Guest, customer, staff, manager. Không có role shipper riêng.

**CORE MODULES:** Authentication/account; catalog/product admin/media; guest/member cart; pricing/checkout/COD/voucher/shipping; order/history/admin; payment audit/manual verification; delivery tracking; review/moderation; revenue; JSON API; SSE; CouchDB import/index/validator/demo scripts.

**ORDER WORKFLOW:** `pending → confirmed → packing → shipping → delivered`. Hủy chỉ từ pending/confirmed/packing; order đã paid không hủy nếu chưa có refund flow. Với order có tracking, delivery phải hoàn tất trước khi order thành delivered.

**DELIVERY WORKFLOW:** `created → picked_up → in_transit → out_for_delivery → delivered`; hỗ trợ `failed_delivery` và retry về `in_transit` hoặc `out_for_delivery`. Delivery fail không đổi order khỏi `shipping`.

**REALTIME:** Có PHP SSE sử dụng CouchDB `_changes`/`_doc_ids`, quyền customer/guest được kiểm tra trước khi subscribe, payload đã lọc và có server-rendered fallback. Browser network offline/online reconnect chưa xác minh; không dùng WebSocket.

**NOSQL FEATURES:** Schema v2, tám business document types, Mango `_find`, 9 Mango indexes cộng `_all_docs`, 3 `_explain` checks, `_id`/`_rev`, fixture conflict HTTP 409, validator reject HTTP 403. Replication và attachments chỉ **RESEARCHED**, chưa triển khai.

**FAUXTON STATUS:** Local Fauxton đã được Phase 07 mở và kiểm tra database demo, All Documents, order document, design docs, Mango Query và Manage Indexes. Dữ liệu demo tổng hợp; không public credentials/Fauxton.

**DEPLOYMENT STATUS:** Phase 08 **BLOCKED / NOT DEPLOYED**. Chưa có URL public, Render services, persistent disks, production env hoặc online validation. Xem `docs/PHASE_08_RENDER_DEPLOYMENT_REPORT.md`.

**TEST STATUS:** Phase 06 report ghi PASS cho PHP lint 89 files, Composer validate (warning thiếu `license`), JWT/catalog/checkout/HTTP/CouchDB smoke, failure smoke, isolated seed verification và demo integrity. Phase 03B/06 ghi browser desktop/mobile 390px PASS; Phase 07 ghi Fauxton/Mango/_explain/revision 409/validator 403 PASS. Không test lại trong tài liệu bàn giao này. Render build/health/readiness là UNVERIFIED.

**KNOWN LIMITATIONS:** Không có payment gateway/refund; không có email/SMS, carrier integration, GPS/map hay shipper app; order snapshot không giữ ảnh nên UI dùng placeholder; catalog filtering cần tối ưu nếu dữ liệu lớn; SSE chưa đánh giá tải đồng thời lớn và offline reconnect chưa test; delivery status query demo fallback không có index chuyên biệt; cloud deployment chưa hoàn thành.

**DEFERRED FEATURES:** Payment/refund, notification, object storage/image snapshot, carrier/shipper/mobile app, recommendation, catalog scaling, SSE load architecture, CouchDB replication/cluster. Tất cả đều là hướng phát triển, chưa triển khai.

## IMPORTANT FILES

- Entry point/router wiring: `public/index.php`
- CouchDB HTTP client: `src/Infrastructure/CouchDB/CouchDbClient.php`
- Checkout: `src/Checkout/CheckoutService.php`, `src/Checkout/PricingService.php`
- Order/delivery workflow: `src/Orders/OrderWorkflowService.php`
- Realtime: `src/Orders/OrderRealtimeController.php`, `src/Core/StreamedResponse.php`, `public/assets/js/order-realtime.js`
- Order screens: `templates/orders/`, `templates/admin/orders/`
- CouchDB validator: `database/couchdb/design-docs/domain_validation.json`
- Mango indexes: `database/couchdb/indexes/catalog_indexes.json`
- Demo queries: `database/couchdb/demo_queries/`
- Demo seed/verify: `scripts/seed_phase06_demo.php`, `scripts/verify_phase06_demo.php`
- API contract: `docs/openapi.yaml`

## IMPORTANT DOCS

- Phase reports 01–08: `docs/PHASE_01_STABILIZATION_REPORT.md` through `docs/PHASE_08_RENDER_DEPLOYMENT_REPORT.md`
- Current CouchDB schema: `database/couchdb/docs/schema-v2-and-mapping.md`
- CouchDB operating notes: `database/couchdb/README.md`
- Render blockers: `docs/PHASE_08_RENDER_DEPLOYMENT_REPORT.md`
- Render runbook: `docs/RENDER_DEPLOYMENT_GUIDE.md`
- PHP/NoSQL report drafts and screenshot/source maps: `docs/final_report/`
- This handover report does not replace the individual Phase reports.

## HOW TO RUN LOCAL

Từ PowerShell, mở thư mục project và bảo đảm `.env` được tạo riêng từ `.env.example`; tự đặt các secret local, không gửi chúng qua chat hoặc đưa vào tài liệu:

```powershell
Set-Location D:\PHP_NOSQL\PHP_COUCHDB
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
# Mở .env và đặt các giá trị local cần thiết trước khi chạy stack.
docker compose up --build -d
docker compose ps
Invoke-WebRequest http://127.0.0.1:8081/health
Invoke-WebRequest http://127.0.0.1:8081/health/ready
```

Nếu demo database Phase 06 **chưa tồn tại**, có thể tạo bằng `docker compose exec -T app composer demo:seed`; sau đó xác minh bằng `docker compose exec -T app composer demo:verify`. Không chạy reset/xóa database trong quy trình bàn giao. `demo:seed` chỉ tạo database mới và sẽ từ chối overwrite database tồn tại.

## HOW TO RUN TESTS

Các lệnh bên dưới được khai báo trong `composer.json`. Smoke tests dùng database test cô lập theo thiết kế; trước khi chạy, xem `.env` đang trỏ tới database có hậu tố `_test` và đọc các guard trong README.

```powershell
docker compose exec -T app composer validate --no-check-publish
docker compose exec -T app composer auth:jwt-smoke
docker compose exec -T app composer catalog:admin-smoke
docker compose exec -T app composer checkout:smoke
docker compose exec -T app composer checkout:http-smoke
docker compose exec -T app composer couchdb:diagnostics
docker compose exec -T app composer couchdb:failure-smoke
docker compose exec -T app composer seed:verify-isolated
docker compose exec -T app composer demo:verify
```

`demo:verify` cần Phase 06 demo database đã có. `seed:verify-isolated` tạo/xóa database tạm của chính script. Không dùng `demo:reset`, `docker compose down -v` hoặc importer lên database có dữ liệu hiện hành như một bước kiểm tra thông thường.

## HOW TO ACCESS FAUXTON

Khi CouchDB container local healthy, mở `http://127.0.0.1:5984/_utils/` và đăng nhập bằng credentials đã cấu hình trong `.env` tại máy vận hành. Không ghi/chụp credentials. Database minh họa Phase 06 là `shopquan_ao_phase06_demo_test`; xác nhận đúng database trước khi thao tác. Dùng Fauxton ở chế độ quan sát; query mẫu nằm trong `database/couchdb/demo_queries/`.

## HOW TO DEPLOY

**Chưa deploy.** Không có URL hoặc service hiện hành để bàn giao. Trước khi bắt đầu deployment, xử lý các blocker trong `docs/PHASE_08_RENDER_DEPLOYMENT_REPORT.md`: Git remote/source publishing, local build/test gates, lựa chọn paid persistent disk, secure-cookie qua TLS proxy và cấu hình production PHP error logging. Sau đó dùng `docs/RENDER_DEPLOYMENT_GUIDE.md` và `docs/RENDER_ENV_CHECKLIST.md`; không public CouchDB/Fauxton. Không coi runbook là deployment đã hoàn tất.

## FINAL DEMO FLOW

1. Chạy local stack và xác nhận `/health/ready` trả kết quả sẵn sàng; chạy `demo:verify` nếu demo DB đã được seed.
2. Mở catalog, product detail, cart và checkout; dùng account/demo data tổng hợp.
3. Mở customer Order History/Detail để xem item snapshot, payment state, order timeline và delivery timeline.
4. Dùng staff/manager demo vào Admin Orders, áp filter và mở một order demo phù hợp.
5. Trình bày transition delivery, failed delivery/retry và delivered bằng order demo được chuẩn bị cho mục đích trình diễn; tránh làm thay đổi fixture chuẩn nếu cần giữ trạng thái ban đầu.
6. Mở Fauxton local để xem order document, `_id`/`_rev`, design docs, indexes và chạy query demo.
7. Không trình bày online deployment như kết quả đã đạt; Phase 08 vẫn NOT DEPLOYED.

Thông tin tài khoản demo nếu cần được lưu cục bộ trong file credentials được `.gitignore` bảo vệ. Không chép mật khẩu vào biên bản này, ảnh chụp, log hoặc repository công khai.

## CONSISTENCY ISSUE

- Phase 01 ghi unauthenticated request tới `/admin/orders` trả 302; Phase 06 dùng từ “Guest” và ghi 403. Source hiện tại phân biệt: chưa đăng nhập → 302 login; account đã có session nhưng không phải staff/manager → 403. Cần làm rõ “Guest” trong Phase 06 nếu chỉnh report đó.
- Phase 06 demo 56 business documents; Phase 07 examined 58 documents khi fallback query gồm cả hai design docs. 347 seed fixture và 348 business docs thuộc các database khác.
- Phase 06/07 từng xác nhận local demo; Phase 08 không truy cập được local endpoint trong audit shell. Runtime hôm nay chưa được chạy lại ở lần tổng kết này.
- Phase 08 deployment guide là kế hoạch; trạng thái chính thức trong Phase 08 report là BLOCKED/NOT DEPLOYED.

# BIÊN BẢN HOÀN TẤT DỰ ÁN

> Báo cáo tổng hợp tình trạng project theo source hiện tại và các báo cáo Phase đã lưu. Các kết quả kiểm thử/browser được ghi theo thời điểm trong từng báo cáo; đây không phải lần chạy test hay kiểm tra runtime mới.

## 1. Thông tin chung

| Thuộc tính | Thông tin |
|---|---|
| Tên project | WEBTHOITRANG PHP + CouchDB (`composer.json`: `webthoitrang/php-couchdb`) |
| Đường dẫn | `D:\PHP_NOSQL\PHP_COUCHDB` |
| Ngôn ngữ chính | PHP 8.3; giao diện dùng HTML, CSS và JavaScript |
| Framework | Không dùng PHP framework; Composer cung cấp autoload và dependency |
| Database | CouchDB 3.5.0, document JSON, truy cập qua HTTP REST/cURL |
| Môi trường chạy | Docker Compose local; PHP/Apache và CouchDB ở các service/container riêng |
| Kiến trúc chính | Browser/API → Router → Controller → Service → Repository → CouchDbClient → CouchDB |
| Ngày tổng kết | 2026-10-08 |
| Trạng thái | **PARTIAL** cho trạng thái bàn giao tổng thể: luồng local/demo đã được kiểm chứng trong Phase 03B/06/07, nhưng runtime hiện tại chưa được kiểm tra lại và Phase 08 chưa triển khai online. |

Không có thông tin cá nhân người làm/giảng viên trong source được dùng để điền vào biên bản.

**Nguồn:** `README.md`, `composer.json`, `Dockerfile`, `docker-compose.yml`, `public/index.php`, `docs/PHASE_01_STABILIZATION_REPORT.md`, `docs/PHASE_08_RENDER_DEPLOYMENT_REPORT.md`.

## 2. Mục tiêu dự án

Project xây dựng website bán hàng thời trang trực tuyến để minh họa các luồng xem sản phẩm, giỏ hàng, checkout, quản trị đơn và giao hàng. PHP application là lớp nghiệp vụ và giao diện người dùng; CouchDB là database; Fauxton là giao diện quản trị/quan sát CouchDB, không phải trang bán hàng.

Phạm vi kiểm chứng gồm catalog, tài khoản, cart, checkout COD, order/delivery workflow, review, doanh thu và JSON API. Phần NoSQL tập trung vào document model, Mango Query/index, revision/conflict, validation và thao tác Fauxton. Render mới có hướng dẫn triển khai; chưa có dịch vụ production.

**Nguồn:** `README.md`; `docs/PHASE_02_ORDER_DELIVERY_REPORT.md`; `docs/PHASE_07_NOSQL_FAUXTON_REPORT.md`; `docs/PHASE_08_RENDER_DEPLOYMENT_REPORT.md`.

## 3. Công nghệ cuối cùng

**Bảng 3.1. Công nghệ và trạng thái**

| Thành phần | Công nghệ | Trạng thái |
|---|---|---|
| Backend | PHP 8.3, không framework | Đang dùng; code trong `src/`, entry point `public/index.php`. |
| Web server | Apache từ `php:8.3-apache` | Cấu hình cho Docker/local; chưa có Apache service trên Render. |
| Giao diện | HTML, CSS, JavaScript | Đang dùng trong PHP templates và `public/assets`. |
| UI toolkit | Bootstrap cục bộ | Có CSS/JS vendor trong `public/assets/vendors/bootstrap`. |
| Dependency/autoload | Composer, PSR-4 | Đang dùng; `firebase/php-jwt` phục vụ JWT API. |
| Database | CouchDB 3.5.0 | Dùng trong Compose/local và làm database nghiệp vụ. |
| Database transport | PHP cURL, CouchDB REST | Đang dùng qua `CouchDbClient`. |
| API authentication | JWT HS256; session cho website | Đã có trong source và smoke tests. Không đưa secret vào tài liệu. |
| Container | Dockerfile, Docker Compose | Cấu hình local có app, CouchDB, healthcheck và named volumes. |
| Realtime | PHP SSE + CouchDB `_changes` | Đã triển khai và có browser/regression evidence local; reconnect khi mất mạng chưa kiểm chứng. |
| Deployment cloud | Render | Chưa triển khai. Runbook và env checklist chỉ là tài liệu chuẩn bị. |

**Nguồn:** `composer.json`, `Dockerfile`, `docker-compose.yml`, `public/index.php`, `src/Infrastructure/CouchDB/CouchDbClient.php`, `src/Orders/OrderRealtimeController.php`, `docs/PHASE_04_REALTIME_ORDER_DELIVERY_REPORT.md`, `docs/PHASE_08_RENDER_DEPLOYMENT_REPORT.md`.

## 4. Kiến trúc hệ thống

Request trang web và API đi theo đường xử lý:

```text
Browser / API
      ↓
    Router
      ↓
  Controller
      ↓
    Service
      ↓
  Repository
      ↓
 CouchDbClient
      ↓
   CouchDB
```

Các object được wiring trong `public/index.php`. Không phải mọi module đều có Repository riêng; checkout/order dùng `CheckoutRepository`, còn guest cart được giữ trong PHP session.

Luồng realtime hiện có:

```text
CouchDB _changes → PHP OrderRealtimeController → SSE response → Browser EventSource
```

Browser không kết nối trực tiếp CouchDB. Endpoint theo dõi order cụ thể bằng `_doc_ids` và longpoll có giới hạn, gửi payload đã lọc rồi kết thúc response. Trình duyệt tự reconnect sau thời gian cấu hình; trạng thái offline/online chưa được mô phỏng.

Môi trường triển khai đã kiểm tra là Docker Compose local. Kiến trúc Render trong guide là đích đề xuất, chưa phải hệ thống đang chạy.

**Nguồn:** `public/index.php`, `src/Core/Router.php`, `src/Infrastructure/CouchDB/CouchDbClient.php`, `src/Orders/OrderRealtimeController.php`, `src/Core/StreamedResponse.php`, `public/assets/js/order-realtime.js`, `docs/PHASE_04_REALTIME_ORDER_DELIVERY_REPORT.md`, `docs/RENDER_DEPLOYMENT_GUIDE.md`.

## 5. Vai trò người dùng

| Role | Chức năng chính đã được xác nhận |
|---|---|
| Guest | Xem catalog, dùng cart trong session, checkout theo luồng hỗ trợ; xem order guest được gắn với session hiện tại và đọc delivery timeline. |
| Customer | Đăng ký/đăng nhập, dùng cart thành viên, checkout, xem order thuộc mình, xác nhận nhận hàng, gửi review và xem realtime order detail. |
| Staff | Truy cập admin order/review theo quyền; cập nhật order/delivery và xử lý xác minh thanh toán theo rule. |
| Manager | Có quyền staff và thao tác quản trị bổ sung như tạo/sửa/ẩn mềm/khôi phục sản phẩm, ẩn/khôi phục review. |

Không có role shipper riêng trong source được kiểm tra. `assigned_staff_id` là thông tin nhân viên ở cấp order, không phải role giao nhận độc lập.

**Nguồn:** `src/Auth/`, `src/Orders/OrderAdminController.php`, `src/Catalog/ProductAdminController.php`, `src/Reviews/ReviewAdminController.php`, `README.md`, `docs/PHASE_06_FINAL_TEST_DEMO_DATA_REPORT.md`.

## 6. Các module và trạng thái

Trạng thái dưới đây phản ánh phạm vi đã triển khai; `PARTIAL` nêu phần còn thiếu so với hệ thống thương mại điện tử đầy đủ.

**Bảng 6.1. Module nghiệp vụ**

| Module | Chức năng | Trạng thái |
|---|---|---|
| Authentication/account | Customer register/login/logout, session, password hash/change; staff login khi có hash; JWT API | **DONE trong phạm vi source**; email quên mật khẩu tự động chưa có. |
| Catalog/product detail | Danh sách, chi tiết, tìm kiếm, lọc, sắp xếp, phân trang, size/variant và ảnh | **DONE trong phạm vi demo**; catalog filter hiện chạy phần lớn ở PHP sau Mango query, cần thiết kế lại cho dataset lớn. |
| Product administration | Staff xem; manager tạo/sửa/ẩn mềm/khôi phục và upload ảnh có kiểm tra | **DONE** theo `catalog:admin-smoke`/HTTP tests. |
| Cart | Guest session cart; member CouchDB cart; merge khi đăng nhập; cập nhật size/số lượng | **DONE** theo README và checkout tests. |
| Checkout/pricing | Preview và tính lại giá/stock/voucher/shipping, journal/idempotency, reservation/recovery | **DONE với COD và phạm vi hiện có**; online payment provider chưa có. |
| Voucher/shipping method | Áp voucher theo loại hỗ trợ; đọc shipping method đang bật; snapshot phí vào order | **DONE** theo CheckoutService và test. |
| Order/history/admin | Order history/detail, ownership, cursor paging, filter/status transition, cancellation có điều kiện | **DONE** theo Phase 02/03/06. |
| Payment | COD collection, audit, manual verification cho bank/card sau đối soát | **PARTIAL**; checkout mới bật COD; không có gateway tự động/refund. |
| Delivery tracking | Tracking code, ETA, transition/history, failed delivery, retry, delivered sync | **DONE** theo Phase 02/03/06. Không có shipper provider/GPS. |
| Review | Gửi review, một review theo account/session và product, staff reply, manager moderation | **DONE** theo README và HTTP smoke. |
| Revenue | Tổng `grand_total` của đơn delivered trên web/API | **DONE** theo README và regression test; không phải phân tích tài chính nâng cao. |
| JSON API | Catalog/cart/checkout/order/review/revenue/admin API với JWT/allowlist | **DONE trong contract hiện tại**, được mô tả tại `docs/openapi.yaml`. |
| Realtime | SSE order detail dựa trên CouchDB `_changes` | **DONE local theo kịch bản đã test**; browser network reconnect chưa verify, production chưa triển khai. |
| CouchDB/Fauxton support | Schema v2, seed/index/validator và Fauxton local cho demo | **DONE cho local demo**; Fauxton không public và replication chưa chạy. |
| Cloud deployment | Render PHP/CouchDB, persistence và kiểm tra online | **PARTIAL / NOT DEPLOYED**; Phase 08 BLOCKED. |

**Nguồn:** `README.md`, `composer.json`, các module tương ứng trong `src/`, `docs/openapi.yaml`, `docs/PHASE_01_STABILIZATION_REPORT.md` đến `docs/PHASE_07_NOSQL_FAUXTON_REPORT.md`, `docs/PHASE_08_RENDER_DEPLOYMENT_REPORT.md`.

## 7. Workflow nghiệp vụ cuối cùng

### 7.1 Mua hàng

```text
Product → Cart → Checkout preview/validation → Order
```

Checkout đọc lại thông tin giá, tồn kho, voucher và shipping method từ CouchDB. Order lưu snapshot item/tổng tiền/phí giao. Cơ chế idempotency và journal hỗ trợ nhận diện lần gửi lặp, recovery và bù trừ stock/voucher. Checkout mới hiện chỉ bật COD.

### 7.2 Order

```text
pending → confirmed → packing → shipping → delivered
```

Hủy chỉ bắt đầu từ `pending`, `confirmed` hoặc `packing`. Khi order đã bắt đầu `shipping`, hủy bị chặn. Đơn đã thanh toán không được hủy nếu không có refund flow. Với tracked order, delivery phải hoàn tất trước khi order được coi delivered; xác nhận đã nhận của chủ order cũng cập nhật theo rule hiện có. `status_history[]` giữ lịch sử trạng thái.

### 7.3 Delivery

```text
created → picked_up → in_transit → out_for_delivery → delivered
```

`failed_delivery` có thể phát sinh trong các bước đang giao; có thể retry từ lỗi sang `in_transit` hoặc `out_for_delivery`. Delivery thất bại không tự đổi order khỏi `shipping`. Khi giao hoàn tất, order và tracking đồng thời thành `delivered`. Gửi lại đúng status không thêm event trùng.

### 7.4 Payment

Payment status độc lập với order/delivery status. COD ban đầu `unpaid`; xác nhận nhận hàng ghi nhận COD theo workflow tương ứng. Staff có thể ghi nhận COD đã thu sau khi order delivered; bank transfer/card chỉ được xác minh thủ công sau đối soát bên ngoài. Hệ thống ghi `payment_history[]` và không hỗ trợ hoàn tiền. Không có payment gateway tự động.

**Nguồn:** `src/Checkout/CheckoutService.php`, `src/Orders/OrderWorkflowService.php`, `docs/PHASE_02_ORDER_DELIVERY_REPORT.md`, `database/couchdb/docs/schema-v2-and-mapping.md`.

## 8. Thiết kế CouchDB

### 8.1 Document types

Các business document được schema v2/validator xác nhận gồm: `customer`, `staff`, `product`, `cart`, `order`, `voucher`, `shipping_method`, `review`. Hai design document chính là `_design/catalog_indexes` và `_design/domain_validation`. `delivery_tracking` là object lồng trong order, không phải type riêng.

### 8.2 Order document

```text
order
├── customer / guest marker
├── receiver
├── items[]                 (item snapshot)
├── shipping                (method/fee snapshot)
├── payment / payment_history[]
├── totals
├── status
├── status_history[]
└── delivery_tracking       (optional, nested)
    └── history[]
```

### 8.3 Cách tổ chức

- **Embedding:** `items[]` và order/delivery/payment histories đặt trong order để lấy aggregate chi tiết đơn thuận tiện.
- **Denormalization:** tên/size/giá sản phẩm và phí shipping được lưu snapshot. Có dữ liệu lặp nhưng lịch sử order không phụ thuộc giá hiện tại của catalog.
- **Snapshot:** order giữ thông tin cần thiết tại thời điểm mua; ảnh sản phẩm không được snapshot.
- **Nested history:** history được nối thêm trong document; validator bảo vệ một số ràng buộc append-only và trạng thái.

Review/catalog/account/voucher/cart/shipping method có vòng đời/truy vấn riêng. Validator CouchDB chỉ bảo đảm ràng buộc document-level; service PHP đảm nhiệm workflow, quyền, stock và quan hệ giữa nhiều document.

**Nguồn:** `database/couchdb/docs/schema-v2-and-mapping.md`, `database/couchdb/design-docs/domain_validation.json`, `database/couchdb/indexes/catalog_indexes.json`, `docs/PHASE_07_NOSQL_FAUXTON_REPORT.md`.

## 9. Order và Delivery Tracking

Order chuyển vào `shipping` thì service khởi tạo `delivery_tracking` với mã tracking được kiểm tra trùng bằng Mango query. Object tracking lưu:

- `tracking_code`;
- `estimated_delivery_date`;
- `status`;
- `delivered_at` khi giao hoàn tất;
- `history[]` gồm event status, timestamp UTC, note và actor.

Staff/manager cập nhật qua form có CSRF hoặc API dùng JWT. Customer chỉ xem order mình sở hữu; guest chỉ xem ID được lưu trong session. Trang detail hiển thị delivery timeline; thông tin nội bộ như phân công staff không đưa vào payload SSE customer.

Với order cũ chưa có tracking, UI vẫn hiển thị thông báo không có thông tin giao hàng và không tạo history giả. Legacy tracking fields được đọc làm fallback và được đưa sang field canonical khi staff khởi tạo tracking theo migration path đã mô tả. Tracking code uniqueness được kiểm tra ở ứng dụng/index; validator không bảo đảm uniqueness xuyên nhiều documents.

`failed_delivery` và retry là trạng thái/event riêng. Order vẫn `shipping` trong lúc giao thất bại. `delivered` là trạng thái cuối của delivery; khi tracking hoàn tất, order cũng hoàn tất.

**Nguồn:** `src/Orders/OrderWorkflowService.php`, `src/Orders/OrderController.php`, `src/Orders/OrderAdminController.php`, `src/Auth/JwtApiController.php`, `templates/orders/delivery_timeline.php`, `docs/PHASE_02_ORDER_DELIVERY_REPORT.md`, `docs/PHASE_03_ORDER_UI_UX_REPORT.md`.

## 10. Giao diện đã hoàn thiện

Các trang đã được báo cáo kiểm tra gồm customer Order History/Detail, Order Timeline, Delivery Timeline, Admin Order List/Detail, filter và form transition. UI dùng nhãn tiếng Việt, trạng thái có text, empty/missing states và form labels; product table có thể cuộn trong wrapper ở mobile.

Phase 03B ghi nhận kiểm tra desktop khoảng 1280px và mobile 390px; không có document-level horizontal overflow, nút/form không chồng nhau, thông tin order/delivery còn đọc được. Accessibility adjustments gồm heading/caption/label, alt text cho placeholder, `aria-label`, focus outline và không chỉ dựa vào màu sắc. Phase 06 cũng ghi nhận customer/admin detail tại 390px.

Order snapshot không có ảnh sản phẩm. Placeholder hiện tại nói rõ order không lưu ảnh; không đổi schema chỉ để thêm ảnh trong các Phase trước.

UI bug có bằng chứng: header đăng nhập bị xếp cao ở 390px, đã sửa CSS compact header và kiểm tra lại.

**Nguồn:** `templates/orders/`, `templates/admin/orders/`, `public/assets/css/catalog.css`, `docs/PHASE_03_ORDER_UI_UX_REPORT.md`, `docs/PHASE_06_FINAL_TEST_DEMO_DATA_REPORT.md`.

## 11. Realtime

**Trạng thái: IMPLEMENTED và DEMOED local.** PHP cung cấp SSE cho customer/guest order detail. Endpoint kiểm tra ownership trước khi subscribe, theo dõi một order bằng CouchDB `_changes`/`_doc_ids` với longpoll hữu hạn tối đa 8 giây. Event payload chỉ chứa các trường hiển thị; trang vẫn có server-rendered snapshot nếu JavaScript/SSE lỗi. `EventSource` reconnect được cấu hình 5 giây.

Phase 04/06 báo cáo browser thấy cập nhật trạng thái order/delivery, failed, retry và delivered không cần reload. HTTP smoke kiểm tra quyền ownership, content type và payload. Offline/online reconnect thực tế chưa được mô phỏng. Không có WebSocket, Redis hay worker realtime riêng; Render/proxy behavior chưa kiểm chứng.

**Nguồn:** `src/Orders/OrderRealtimeController.php`, `src/Core/StreamedResponse.php`, `public/assets/js/order-realtime.js`, `docs/PHASE_04_REALTIME_ORDER_DELIVERY_REPORT.md`, `docs/PHASE_06_FINAL_TEST_DEMO_DATA_REPORT.md`.

## 12. NoSQL / Fauxton

| Chủ đề | Trạng thái | Bằng chứng |
|---|---|---|
| Document model schema v2 | **IMPLEMENTED** | Seed/schema và validator cho 8 business types. |
| Mango `_find` query | **IMPLEMENTED + DEMOED** | 12 query JSON; Phase 07 ghi 12/12 HTTP 200 trên demo DB. |
| Mango indexes | **IMPLEMENTED + DEMOED** | Chín JSON Mango indexes và primary `_all_docs`; index list được mở trên Fauxton. |
| `_explain` | **DEMOED** | Ba query chọn `admin_orders`, `customer_orders`, `delivery_tracking_code`. |
| `_id`/`_rev` và conflict 409 | **DEMOED** | Fixture riêng update revision 1→2; stale PUT revision 1 nhận 409. |
| Domain validator/403 | **IMPLEMENTED + DEMOED** | `_design/domain_validation`; invalid order status trong fixture bị từ chối 403. |
| Design documents | **IMPLEMENTED + DEMOED** | `_design/catalog_indexes`, `_design/domain_validation` được xem trên Fauxton. |
| `_changes` | **IMPLEMENTED** trong PHP realtime | Dùng cho SSE; không phải chức năng NoSQL mới của Phase 07. |
| Replication | **RESEARCHED, NOT IMPLEMENTED** | Phase 07 ghi rõ không cấu hình/chạy replication. |
| CouchDB attachments | **RESEARCHED, NOT IMPLEMENTED** | Ảnh app ở filesystem/media path, không nhúng attachment vào order. |
| Fauxton public/production | **NOT IMPLEMENTED** | Chỉ xác minh Fauxton local; Render CouchDB/Fauxton chưa deploy và không dự định public trong guide. |

Phase 07 ghi nhận mở database demo, All Documents, document order tổng hợp, design docs, Mango Query và Manage Indexes trên Fauxton. Query shipping trả 6/6 examined; failed delivery trả 1/58 examined và có cảnh báo thiếu index phù hợp; tracking code trả 1/1. Đây là số đo trên fixture nhỏ, không phải benchmark hiệu năng.

**Nguồn:** `database/couchdb/demo_queries/`, `database/couchdb/indexes/catalog_indexes.json`, `database/couchdb/design-docs/domain_validation.json`, `scripts/phase07_nosql_fixture_demo.php`, `docs/PHASE_07_NOSQL_FAUXTON_REPORT.md`.

## 13. Deployment

**Trạng thái Phase 08: BLOCKED / NOT DEPLOYED.** Không có URL public, Render service, CouchDB service, persistent disk, production environment configuration hoặc online health/readiness result.

Các blocker được ghi trong Phase 08:

1. `PHP_COUCHDB` không có Git repository/remote để Render clone và build.
2. Docker Engine không truy cập được trong audit shell; PHP/Composer/Render CLI và các local endpoint không khả dụng ở lượt đó.
3. Cần quyết định chi phí dịch vụ/disk bền vững cho CouchDB và upload.
4. Cần xác minh secure cookie khi TLS kết thúc ở proxy.
5. Cần thiết lập và test rõ PHP error display/logging trong môi trường production.

`RENDER_DEPLOYMENT_GUIDE.md` và `RENDER_ENV_CHECKLIST.md` là kế hoạch/runbook, không phải bằng chứng triển khai. Cấu hình Compose local có health `/health`, readiness `/health/ready`, CouchDB `/_up`, và named volumes; các kết quả local này chỉ được xem là PASS tại các lượt test Phase có ghi lại.

**Nguồn:** `docs/PHASE_08_RENDER_DEPLOYMENT_REPORT.md`, `docs/RENDER_DEPLOYMENT_GUIDE.md`, `docs/RENDER_ENV_CHECKLIST.md`, `docker-compose.yml`.

## 14. Kiểm thử

Kết quả dưới đây là tổng hợp từ Phase reports, không phải kết quả vừa chạy trong ngày lập biên bản.

**Bảng 14.1. Kiểm thử được báo cáo**

| Test | Kết quả | Ghi chú / nguồn |
|---|---|---|
| `php -l` trên source/templates/scripts | **PASS** | Phase 06 ghi 89 file; các Phase khác cũng lint file sửa đổi. |
| `composer validate --no-check-publish` | **PASS, có warning** | Thiếu field `license` trong package metadata. |
| `auth:jwt-smoke` | **PASS** | Cấp/verify token; reject tamper và issuer sai. |
| `catalog:admin-smoke` | **PASS** | Staff/manager, product create/edit/validation/soft delete/restore. |
| `checkout:smoke` | **PASS** | Checkout/order/delivery, ownership/CSRF, cancellation/recovery, stale `_rev`/409. |
| `checkout:http-smoke` | **PASS** | Guest/customer/API/admin, payment/review/revenue/upload/media/idempotency, SSE assertions. |
| `couchdb:diagnostics` | **PASS** | Mango/index diagnostics read-only. |
| `couchdb:failure-smoke` | **PASS** | Closed loopback port cho generic 503; không dừng CouchDB dùng chung. |
| `seed:verify-isolated` | **PASS** | 347 fixture documents được import/verify trên DB tạm rồi xóa. |
| Demo integrity `demo:verify` | **PASS** | Phase 06 ghi counts, status/history, tracking/retry và totals hợp lệ. |
| Browser customer/admin flow | **PASS theo Phase 03B/06** | Local desktop/customer/admin flows và thông báo được kiểm tra. |
| Mobile 390px | **PASS theo Phase 03B/06** | Order/detail responsive; không overflow toàn trang. |
| Realtime browser flow | **PASS theo Phase 04/06** | SSE cập nhật order/delivery; offline/online reconnect chưa xác minh. |
| Fauxton Mango/revision/validator | **PASS theo Phase 07** | Query demo; fixture revision 409, invalid validator 403, cleanup. |
| Render build/health/readiness/online flow | **UNVERIFIED / NOT RUN** | Phase 08 BLOCKED; không được tính PASS. |

Phase 01 từng ghi `seed:verify` trên database đang cấu hình từ chối vì có document ngoài bộ seed; đó là guard mong đợi, không phải lỗi app. Phase 05/06 dùng `seed:verify-isolated` để xác minh fixture trong database riêng.

**Nguồn:** `docs/PHASE_01_STABILIZATION_REPORT.md` đến `docs/PHASE_08_RENDER_DEPLOYMENT_REPORT.md`.

## 15. Bugs đã phát hiện và xử lý

| Bug/issue | Phase | Cách xử lý | Trạng thái |
|---|---|---|---|
| Guest receipt flow trong HTTP smoke gọi helper từ static closure không phù hợp | 02 | Sửa closure; chạy lại HTTP smoke | Đã xử lý, smoke PASS. |
| Header đăng nhập chiếm chiều cao lớn ở viewport 390px | 03/03B | Thêm compact mobile header rule trong CSS và kiểm tra lại | Đã xử lý; không còn tràn trang trong kiểm tra ghi nhận. |
| Demo customer liên kết `customer_id` theo CouchDB document ID nên Order History rỗng | 06 | Sửa generator sang legacy ID mà app dùng | Đã xử lý; browser history hiển thị đúng. |
| Shipping method trong demo không khớp code app mong đợi; checkout trả 503 | 06 | Sửa demo IDs/codes/fees theo phương thức `BD`, `HT` | Đã xử lý; browser checkout PASS. |
| Demo reset guard không nhận diện cart/order do demo app sinh ra | 06 | Sửa guard để chỉ nhận diện đúng dữ liệu thuộc demo; document lạ vẫn bị từ chối | Đã xử lý theo report; guard an toàn vẫn từ chối dữ liệu không nhận diện. |
| So sánh status counts của demo phụ thuộc thứ tự document | 06 | So sánh counts theo từng status | Đã xử lý; verifier PASS. |

Các lỗi Phase 06 là lỗi seeder/verifier/demo setup, không phải lỗi runtime nghiệp vụ còn mở. Không có source code được sửa trong bước tổng kết này.

## 16. Những điểm chưa hoàn thiện

- Phase 08 chưa deploy; không có persistence/restart, online security, online mobile hoặc online realtime evidence.
- PHP runtime local không được kiểm tra lại trong công việc tổng kết này; Phase 08 trước đó ghi không truy cập được local endpoints trong audit shell.
- Không có cổng thanh toán tự động hoặc refund; bank transfer/card chỉ xác minh thủ công sau đối soát.
- Không có email/SMS tự động cho quên mật khẩu hoặc trạng thái order.
- Không có tích hợp đơn vị vận chuyển, GPS/map, shipper role/app riêng.
- Order snapshot không giữ ảnh sản phẩm lịch sử; trang detail dùng placeholder rõ ràng.
- Catalog filter hiện xử lý ở PHP sau Mango query và được README giới hạn cho quy mô fixture; dataset lớn cần pagination/index/materialized summary phù hợp hơn.
- Query delivery status trong demo quét `_all_docs`; chưa tạo delivery status index do workload/demo nhỏ.
- SSE giữ Apache prefork worker trong mỗi lượt longpoll hữu hạn; tải đồng thời cao chưa được đánh giá.
- SSE offline/online reconnect chưa được mô phỏng trong browser.
- Replication và CouchDB attachments mới ở mức nghiên cứu, chưa demo triển khai.
- Composer metadata chưa khai báo license; chưa chọn license phân phối.

## 17. Hướng phát triển

Các mục sau là **HƯỚNG PHÁT TRIỂN, CHƯA TRIỂN KHAI**:

- Tích hợp payment gateway và quy trình refund có đối soát.
- Notification email/SMS.
- Tích hợp nhà vận chuyển, tracking provider, GPS/map và role/app shipper nếu yêu cầu nghiệp vụ.
- Lưu image identifier ổn định hoặc snapshot ảnh cho đơn cũ; cân nhắc object storage.
- Tối ưu catalog query/pagination và index theo workload thực tế.
- Đánh giá tải SSE hoặc thay đổi kiến trúc nếu lượng kết nối đồng thời tăng.
- Nghiên cứu replication/cluster và xử lý conflict trên môi trường test cô lập.
- Hoàn thành các prerequisite rồi mới triển khai Render, persistent storage, health/restart/security checks.

## 18. Tổng kết các Phase

| Phase | Nội dung | Trạng thái theo báo cáo |
|---|---|---|
| Phase 01 – Stabilization | Baseline source, route, health, JWT, catalog, checkout và smoke | **Hoàn thành baseline**; browser khi đó còn yêu cầu kiểm tra thủ công, được thực hiện ở Phase 03B/06. |
| Phase 02 – Order + Delivery | Delivery tracking, history, transitions, API/UI, validator/index | **Hoàn thành**; các workflow/test được báo cáo PASS. |
| Phase 03 – Order UI/UX | Customer/admin order view, timeline, empty states, responsive/accessibility | **Hoàn thành**. |
| Phase 03B – Browser Verification | Desktop/mobile 390px, customer/admin forms và delivery flow | **PASS**, được ghi trong phần manual browser checks của `PHASE_03_ORDER_UI_UX_REPORT.md`; không có file Phase 03B riêng. |
| Phase 04 – Realtime | SSE qua PHP và CouchDB `_changes` | **Hoàn thành**; network offline/online reconnect chưa verify. |
| Phase 05 – Reliability | Isolated seed verification, failure smoke, test isolation | **DONE**. |
| Phase 06 – Final Testing & Demo Data | Regression, browser demo, responsive, fixture integrity | **PASS/DONE theo report**; demo database synthetic riêng. |
| Phase 07 – NoSQL/Fauxton | Mango, indexes, `_explain`, revisions, validator, Fauxton | **DONE**; replication/attachments không triển khai. |
| Phase 08 – Render Deployment | Pre-deployment audit và runbook | **NOT DONE / BLOCKED / NOT DEPLOYED**. |
| Phase 09 – Reporting | Hai bản nháp PHP/NoSQL, screenshot checklist, source map, summary trong `docs/final_report/` | **Đã có deliverables báo cáo**; **CHƯA CÓ BÁO CÁO PHASE NÀY** dạng `docs/PHASE_09_*.md`. |

**Nguồn:** từng `docs/PHASE_01_...md` đến `docs/PHASE_08_...md`; các deliverable Phase 09 trong `docs/final_report/`.

## 19. Trạng thái bàn giao

**PROJECT STATUS: PARTIAL** cho bàn giao tổng thể.

Các report trước ghi nhận hệ thống local/demo và Fauxton đã hoạt động trong các lượt kiểm tra. Tuy nhiên, ngày tổng kết này chưa có kiểm tra runtime mới; Phase 08 xác nhận chưa deploy online. Vì vậy kết luận phù hợp là **local demo đã có bằng chứng trước đó; runtime hiện tại cần xác nhận lại; deployment online chưa sẵn sàng**.

**Bảng 19.1. Checklist bàn giao theo bằng chứng đã lưu**

| Checklist | Kết quả | Ghi chú |
|---|---|---|
| Source chạy được | [x] PASS trong lượt Phase trước | Phase 06 containers healthy; runtime hiện tại chưa được chạy lại trong báo cáo này. |
| Database hoạt động | [x] PASS trong lượt Phase trước | CouchDB/Fauxton và query demo được Phase 06/07 kiểm tra. |
| Core PHP functions hoạt động | [x] PASS | Regression suites chính PASS theo Phase 06. |
| Order hoạt động | [x] PASS | Browser + smoke tests ở Phase 03/06. |
| Delivery hoạt động | [x] PASS | Failed/retry/delivered được kiểm thử. |
| Tests PASS | [x] PASS theo Phase 06/07 | Một số checks deployment không chạy; xem mục 14. |
| Mobile PASS | [x] PASS theo Phase 03B/06 | Viewport 390px. |
| NoSQL/Fauxton demo ready | [x] PASS theo Phase 07 | Fauxton local, synthetic demo data. |
| Deployment ready | [ ] NO | Phase 08 BLOCKED / NOT DEPLOYED. |
| Documentation ready | [x] PARTIAL | Tài liệu Phase và final report drafts có; cần xác nhận runtime và bổ sung ảnh/cover details trước khi nộp. |

Để tổ chức demo cuối, chạy lại local health/readiness và `composer demo:verify` trước khi trình bày; không reset database demo đang dùng. Nếu cần nộp public URL, hoàn tất Phase 08 riêng sau khi giải quyết blockers.

## 20. Kết luận

Project đã hình thành ứng dụng bán hàng thời trang bằng PHP, sử dụng CouchDB làm database và Fauxton để quan sát/quản trị database. Các chức năng cốt lõi được báo cáo gồm catalog, cart, checkout, order history/admin, payment state, delivery tracking, review, revenue và JSON API. Order và delivery có workflow/history riêng; các smoke tests cũng kiểm tra ownership, CSRF, idempotency, revision conflict và một số failure paths.

Phần CouchDB minh họa document model schema v2, Mango query/index, `_explain`, `_id`/`_rev`, conflict 409 và validator 403. Fauxton local cùng synthetic Phase 06 dataset đã được kiểm tra. SSE order detail là tính năng PHP đã triển khai và demo local; mất/kết nối lại mạng chưa xác minh.

Kết quả cần được giới hạn đúng bằng chứng: Phase 01–07 có các kết quả local/browser/test được lưu; Phase 08 chưa triển khai, không có URL public và chưa xác minh persistence/security/restart trên cloud. Do chưa kiểm tra runtime hiện tại trong lượt tổng kết, trạng thái bàn giao chung là PARTIAL; cần chạy lại readiness trên máy demo trước khi xác nhận READY FOR DEMO.

## Nguồn chính

- `README.md`, `composer.json`, `Dockerfile`, `docker-compose.yml`, `.env.example`.
- `src/`, `public/`, `templates/`, `scripts/`.
- `database/couchdb/README.md`, `database/couchdb/docs/schema-v2-and-mapping.md`, `database/couchdb/design-docs/domain_validation.json`, `database/couchdb/indexes/catalog_indexes.json`, `database/couchdb/demo_queries/`.
- `docs/PHASE_01_STABILIZATION_REPORT.md` đến `docs/PHASE_08_RENDER_DEPLOYMENT_REPORT.md`.
- `docs/RENDER_DEPLOYMENT_GUIDE.md`, `docs/RENDER_ENV_CHECKLIST.md`, `docs/openapi.yaml`.
- `docs/final_report/01_PHP_REPORT_DRAFT.md`, `02_NOSQL_FAUXTON_REPORT_DRAFT.md`, `03_SCREENSHOT_CHECKLIST.md`, `04_REPORT_SOURCE_MAP.md`, `05_FINAL_PROJECT_SUMMARY.md`.

## CONSISTENCY ISSUE

1. **Unauthenticated admin request:** Phase 01 nói khách chưa đăng nhập vào `GET /admin/orders` được redirect 302; Phase 06 ghi “Guest truy cập Admin Orders nhận 403.” Source hiện tại trong `OrderAdminController::staffGuard()` phân biệt: chưa có `auth_user` thì trả 302 tới `/login`; có session nhưng account không phải staff/manager thì trả 403. Có thể cụm “Guest” ở Phase 06 chỉ khách đã đăng nhập, nhưng report không nói rõ. Biên bản này ưu tiên source và ghi nhận sự mơ hồ giữa hai report.
2. **Local runtime theo thời điểm:** Phase 01/06/07 có health/browser/Fauxton success tại thời điểm chạy. Phase 08 sau đó không truy cập Docker/local endpoint trong audit shell. Đây là những lượt khác thời điểm; báo cáo này không xem kết quả cũ là xác nhận runtime hôm nay.
3. **Demo/test document counts:** Phase 06 demo có 56 business docs; Phase 07 fallback query có thể examine 58 docs gồm hai design docs; số 347 là fixture seed riêng; 348 là business document count trên database application khác. Không cộng hoặc gán các con số này cho cùng database.
4. **Deployment documentation:** Render guide mô tả target architecture; Phase 08 kết luận NOT DEPLOYED. Trạng thái kết luận dùng Phase 08 report.
5. **Phase 09 report file:** Deliverables final report có trong `docs/final_report/`, nhưng không có `docs/PHASE_09_*.md`; bảng Phase 09 ghi rõ thiếu report phase riêng.

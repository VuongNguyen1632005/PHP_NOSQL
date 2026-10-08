# BẢN ĐỒ NGUỒN CHO HAI BÁO CÁO

Mục đích của bảng là cho phép truy ngược mỗi nhận định về tài liệu dự án. Nội dung tính năng được ưu tiên đối chiếu với source/config/schema hiện có; kết quả test và browser chỉ được khẳng định theo đúng Phase report đã lưu. Đường dẫn tương đối đều tính từ `PHP_COUCHDB/`.

| Nội dung báo cáo | Source / tài liệu chứng minh | Phạm vi bằng chứng và lưu ý |
|---|---|---|
| Tên dự án, cách chạy local, module/giới hạn | `README.md` | README chính thức của project; gọi project là `WEBTHOITRANG PHP + CouchDB`. |
| PHP version, dependencies, command test | `composer.json`, `composer.lock` | PHP `^8.3`, cURL/JSON/mbstring, `firebase/php-jwt`; tên các script được đăng ký. |
| Apache/PHP image | `Dockerfile`, `docker/apache-vhost.conf`, `docker/php/uploads.ini` | PHP 8.3 Apache, cài curl/mbstring, web root và giới hạn upload. |
| Local PHP/CouchDB services, volumes, healthcheck | `docker-compose.yml`, `.env.example` | Cấu hình môi trường mẫu; không lấy secret từ `.env` vào báo cáo. |
| Đường đi request và route registry | `public/index.php`, `src/Core/Router.php` | Wiring Browser/API → Router → controller/service/repository/client. |
| CouchDB REST/cURL | `src/Infrastructure/CouchDB/CouchDbClient.php` | Transport HTTP và response/status handling; không mô tả như ORM. |
| Authentication/session/hash | `src/Auth/AccountService.php`, `src/Auth/AuthController.php`, `src/Auth/JwtTokenService.php`, `src/Auth/JwtBearerAuthenticator.php` | Website dùng session; API dùng JWT. Secret/value không được trích vào báo cáo. |
| Guest/member cart | `src/Cart/GuestCartService.php`, `src/Cart/MemberCartService.php`, `src/Cart/MemberCartRepository.php`, `src/Cart/CartController.php` | Session cho guest; CouchDB document cho member; merge khi đăng nhập. |
| Catalog/search/admin product/media | `src/Catalog/`, `templates/catalog/`, `templates/admin/products/`, `public/assets/js/catalog.js` | Product listing/detail, admin product actions, upload/media. |
| Checkout/pricing/idempotency/recovery | `src/Checkout/CheckoutService.php`, `src/Checkout/PricingService.php`, `src/Checkout/CheckoutRepository.php`, `scripts/recover_checkout.php`, `scripts/compact_order_markers.php` | Checkout, stock/voucher journal, retry/recovery và marker compaction. |
| Order lifecycle, cancellation, payment, delivery state machine | `src/Orders/OrderWorkflowService.php`, `src/Orders/OrderAdminController.php`, `src/Orders/OrderController.php` | Allowed transition, 409 retry, order/delivery/payment histories. |
| Customer/Admin Order UI | `templates/orders/index.php`, `templates/orders/detail.php`, `templates/orders/delivery_timeline.php`, `templates/admin/orders/index.php`, `templates/admin/orders/detail.php` | Ownership-specific view, timeline, admin forms, fallback/no-tracking states. |
| Responsive styling/Bootstrap | `public/assets/css/catalog.css`, `templates/layout.php`, `public/assets/vendors/bootstrap/` | CSS/responsive rules and bundled Bootstrap. Browser results lấy từ Phase 03B/06 reports. |
| Realtime SSE and `_changes` | `src/Orders/OrderRealtimeController.php`, `src/Core/StreamedResponse.php`, `public/assets/js/order-realtime.js` | SSE qua PHP, long-poll `_changes`, payload lọc. Không phải WebSocket. |
| Reviews/revenue | `src/Reviews/`, `src/Reporting/`, `templates/admin/reviews/`, `templates/admin/revenue/` | Review submit/moderate/reply, revenue theo delivered orders. |
| CouchDB schema v2/document mapping | `database/couchdb/docs/schema-v2-and-mapping.md`, `database/couchdb/seeds/retail_order_delivery.json` | Document types, field mapping và SQL mẫu; seed không phải export database live. |
| Validator | `database/couchdb/design-docs/domain_validation.json`, `scripts/update_domain_validator.php` | `validate_doc_update`, các ràng buộc document-level. |
| Mango indexes | `database/couchdb/indexes/catalog_indexes.json`, `scripts/create_catalog_indexes.php` | Index definitions; runtime selection evidence additionally in Phase 07. |
| Query examples | `database/couchdb/demo_queries/01_*.json` … `12_*.json`, `database/couchdb/demo_queries/README.md` | Selector JSON thật, index pinning, query mục đích. Query guest và delivery status không chỉ định index. |
| Fixture, import and DB safety | `database/couchdb/README.md`, `scripts/import_seed.php`, `scripts/verify_seed_isolated.php`, `scripts/seed_phase06_demo.php`, `scripts/verify_phase06_demo.php` | Fixture/test guard, synthetic demo seed/verify/reset. |
| SQL Server comparison | `database/couchdb/docs/schema-v2-and-mapping.md`, `database/couchdb/migrations/export_sql_server_snapshot.sql`, `scripts/export_sql_server_snapshot.ps1` | Migration/reference mapping; SQL Server không phải runtime của PHP_COUCHDB. |
| Phase 01 stabilization/tests | `docs/PHASE_01_STABILIZATION_REPORT.md` | Syntax, JWT, catalog, checkout, HTTP smoke; seed verification limitation at that phase. |
| Order/delivery implementation and tests | `docs/PHASE_02_ORDER_DELIVERY_REPORT.md` | Status rules, delivery tracking, validator/index and integration results. |
| UI changes and browser verification | `docs/PHASE_03_ORDER_UI_UX_REPORT.md` | Desktop 1280px/390px, customer/admin checklist, bug/fix and smoke tests. |
| SSE architecture and browser checks | `docs/PHASE_04_REALTIME_ORDER_DELIVERY_REPORT.md` | `_changes`, SSE, browser update scenarios; offline/online reconnect remains unverified. |
| Isolated seed and failure-path reliability | `docs/PHASE_05_SCOPE_PROPOSAL.md`, `docs/PHASE_05_RELIABILITY_REPORT.md` | 347 fixture docs isolated, closed-port 503, `_test` safeguards. |
| Regression suite and demo data counts/browser checks | `docs/PHASE_06_FINAL_TEST_DEMO_DATA_REPORT.md` | Demo DB counts/statuses, tested customer/admin flows, responsive and limitations. |
| Fauxton/Mango/revision/validator evidence | `docs/PHASE_07_NOSQL_FAUXTON_REPORT.md`, `scripts/phase07_nosql_fixture_demo.php` | Fauxton browser verification, Mango counts/explain, synthetic 409/403 fixture, replication not implemented. |
| Deployment state and blockers | `docs/PHASE_08_RENDER_DEPLOYMENT_REPORT.md`, `docs/RENDER_DEPLOYMENT_GUIDE.md`, `docs/RENDER_ENV_CHECKLIST.md` | Deployment is BLOCKED/NOT DEPLOYED; runbook is target design, not completed deployment. |
| PHP report screenshots | `docs/final_report/03_SCREENSHOT_CHECKLIST.md` | Placeholder/collection plan only; no captured screenshot is claimed. |

## REPORT CONSISTENCY ISSUE

1. **Deployment status:** Phase 08 runbook describes an intended Render architecture, while `PHASE_08_RENDER_DEPLOYMENT_REPORT.md` explicitly says BLOCKED/NOT DEPLOYED. This report uses the latter as the result and labels the runbook as a plan only.
2. **Environment/time scope:** Phase 01/06 recorded healthy local containers/browser flows at their test dates; Phase 08 later could not access Docker/local endpoints in its audit environment. These statements refer to different checks and times. This draft reports prior successful local/browser tests as historical evidence, but does not claim a current live/deployed service or a Phase 09 rerun.
3. **Demo document counts:** Phase 06 describes 56 business documents in its demo dataset. Phase 07 query UI reports 58 documents examined for fallback, consistent with 56 business documents plus two design documents. Other fixture/import totals such as 347 refer to a separate fixture/database and must not be mixed with the Phase 06 demo count.
4. **Database counts are environment-specific:** Phase 07 records 348 business documents in the then-configured application DB and 56 in the Phase 06 demo DB. Those counts are dated observations, not guaranteed current values.
5. **Image snapshot:** source/templates/Phase 03 and Phase 06 agree that order snapshots do not preserve product images; the interface uses an explicit placeholder. No schema change or historical image behavior is claimed.
6. **Evidence vs screenshot files:** phase reports state browser checks were completed, while Phase 07 explicitly says screenshots were not created. This draft treats browser checks as reported verification and leaves all visual evidence images as placeholders.

No source/report mismatch was found that justifies altering business code for this drafting phase. If a later source inspection contradicts a claim above, source should take precedence and the report should be amended before submission.

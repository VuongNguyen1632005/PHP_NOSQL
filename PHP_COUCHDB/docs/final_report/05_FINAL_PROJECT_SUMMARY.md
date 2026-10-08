# TÓM TẮT CUỐI PROJECT – PHASE 09

**PROJECT_NAME:** WEBTHOITRANG PHP + CouchDB (theo `README.md`); `composer.json` package name `webthoitrang/php-couchdb`.

**PROJECT_PATH:** `D:\PHP_NOSQL\PHP_COUCHDB`

**FINAL_TECH_STACK:** PHP 8.3 thuần, Apache, HTML/CSS/JavaScript, Bootstrap cục bộ, Composer, Docker Compose, CouchDB 3.5.0, REST/cURL; JWT HS256 cho JSON API, session cho website. Fauxton là giao diện quản trị CouchDB local.

**ROLES:** Guest, customer, staff, manager. Staff/manager quản trị order; quyền sản phẩm/review có giới hạn theo role.

**CORE_MODULES:** Authentication; catalog/product admin/media; guest/member cart; pricing/checkout/COD/voucher; order history/detail/admin; delivery tracking; reviews; revenue; JSON API; SSE order updates; CouchDB import/demo/index/validator scripts.

**ORDER_WORKFLOW:** `pending → confirmed → packing → shipping → delivered`. Có thể hủy tại `pending`, `confirmed`, `packing`; order đã paid không hủy nếu chưa có refund flow. `status_history[]` lưu event.

**DELIVERY_WORKFLOW:** `created → picked_up → in_transit → out_for_delivery → delivered`; `failed_delivery` được ghi từ bước đang giao và retry sang `in_transit` hoặc `out_for_delivery`. Order giữ `shipping` khi delivery thất bại; tracked order và delivery cùng delivered khi hoàn tất.

**PAYMENT:** COD là checkout mới đang bật. Payment status riêng với order/delivery; staff ghi nhận thu COD hoặc xác minh thủ công bank/card ngoài hệ thống. Chưa có gateway tự động hoặc refund.

**DATABASE:** CouchDB document database, schema v2. PHP dùng REST/cURL. Fauxton/CouchDB local ở `127.0.0.1:5984/_utils/` khi stack local hoạt động.

**DOCUMENT_TYPES:** `customer`, `staff`, `product`, `cart`, `order`, `voucher`, `shipping_method`, `review`; thêm hai design docs `_design/catalog_indexes`, `_design/domain_validation`. Delivery tracking được nhúng trong `order`, không phải type riêng.

**REALTIME:** Có SSE qua PHP endpoint và CouchDB `_changes` longpoll giới hạn cho một order. Browser reconnect được cấu hình; kiểm thử browser offline/online reconnect chưa xác minh. Không dùng WebSocket.

**DEMO_DATA:** Phase 06 dataset riêng ghi nhận 6 customer, 2 staff/manager, 18 product, 1 voucher, 2 shipping methods, 27 orders; delivery có trạng thái thành công, thất bại/retry, và một số delivered legacy không tracking. Đây là synthetic data. Phase 07 ghi nhận thêm 2 design docs khi UI fallback examined 58 total docs. Không nhầm với fixture/import riêng 347 documents.

**DEPLOYMENT:** Local Docker/Compose được các Phase trước kiểm tra. Render Phase 08 **BLOCKED / NOT DEPLOYED**; không có public URL, service, persistent disk hoặc bằng chứng online. Cần Git remote/source publish, local validation, lựa chọn disk plan/chi phí, xác minh proxy HTTPS cookie và PHP production error logging.

**TEST_STATUS:** Phase 01–07 reports ghi nhận các smoke/integration suites chính PASS, Phase 06 89 PHP files lint PASS, demo verify PASS, Fauxton/Mango/fixture 409/403 PASS, UI browser desktop/390px PASS. Offline/online SSE reconnect chưa kiểm tra. Phase 08 local/build/deployment checks UNVERIFIED; không có test mới chạy trong Phase 09.

**PHP_REPORT_STATUS:** Bản Markdown được soạn trong Phase 09; nội dung kỹ thuật có thể đưa sang dàn trang sau khi bổ sung thông tin bìa và ảnh. Ước tính hoàn thiện nội dung: **85%** (ước lượng biên tập, không phải điểm đánh giá). Chưa phải bản nộp cuối.

**NOSQL_REPORT_STATUS:** Bản Markdown được soạn; trọng tâm CouchDB/Fauxton, schema, Mango/index, `_rev`/409 và validator/403. Ước tính hoàn thiện nội dung: **85%** (ước lượng biên tập, không phải điểm đánh giá). Còn thiếu ảnh Fauxton/fixture và thông tin bìa.

**MISSING_SCREENSHOTS:** Tất cả ảnh trong `03_SCREENSHOT_CHECKLIST.md` hiện là placeholder, chưa có file ảnh trong repository. Ưu tiên tối thiểu: PHP-01/02, PHP-05/06/08/10/11/13/14/15/16; NOSQL-01/02/04/05/06/07/09/10/11/14/15. Chụp bằng demo tổng hợp; không lộ credentials hoặc thông tin cá nhân.

**MISSING_INFORMATION:** Tên trường; khoa; tên môn học chính thức; giảng viên; họ tên sinh viên; MSSV; lớp; năm học; yêu cầu trình bày/trích dẫn và số trang nếu có. Hai báo cáo môn học có thể cần thông tin bìa khác nhau.

**IMPORTANT_LIMITATIONS:** Không có payment gateway/refund; không có tích hợp carrier/email; order image không snapshot; một số catalog query cần thiết kế lại nếu dataset lớn; SSE Apache prefork chưa đánh giá tải đồng thời; delivery status Mango demo không có index chuyên biệt; replication chỉ nghiên cứu, chưa chạy; deployment public chưa làm; offline/online reconnect chưa xác minh.

**REPORT CONSISTENCY ISSUE:** Deployment guide mô tả target architecture nhưng Phase 08 xác nhận chưa triển khai; báo cáo này dùng trạng thái NOT DEPLOYED. Counts 56/58/347/348 thuộc các database và mục đích khác nhau, cần luôn nêu đúng ngữ cảnh. Chi tiết ở `04_REPORT_SOURCE_MAP.md`.

## Việc còn lại trước khi chuyển Word

1. Bổ sung các field trang bìa cho cả hai môn.
2. Chụp ảnh thật theo checklist từ local demo/Fauxton; kiểm tra đã che dữ liệu nhạy cảm.
3. Đối chiếu caption, số thứ tự hình/bảng với hướng dẫn trình bày của trường.
4. Nếu yêu cầu báo cáo phải có ảnh evidence, chưa nên chốt PDF cho tới khi ảnh đã được chèn và rà soát.

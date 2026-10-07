# PHASE 07 – NOSQL / COUCHDB / FAUXTON

Ngày kiểm tra: 2026-10-07. Mọi lần đọc dữ liệu nghiệp vụ trong Phase này là read-only; kiểm thử ghi dùng database fixture mới `phase07_nosql_fixture_test`, sau đó database được xóa và xác minh không còn tồn tại. Không thay đổi nghiệp vụ PHP, database đang cấu hình, dữ liệu demo Phase 06 hoặc Mango indexes.

## 1. Topic alignment

Project PHP/CouchDB hiện tại là ứng dụng minh họa cho đề tài quản lý đơn hàng và giao hàng của cửa hàng bán lẻ. Phase này phân tích document thật, chạy Mango query và kiểm chứng revision/validator trên CouchDB 3.5.0; Fauxton có thể mở tại `http://127.0.0.1:5984/_utils/`.

## 2. Current CouchDB architecture

Ứng dụng đang cấu hình database `shopquan_ao_sql_migration_test`; kiểm tra aggregate read-only ghi nhận 348 business documents. Database demo tách biệt `shopquan_ao_phase06_demo_test` có 56 business documents và được dùng để chạy truy vấn minh họa vì chỉ chứa dữ liệu tổng hợp.

Mọi business document được kiểm tra thuộc `schema_version: 2`. Fauxton chạy cùng CouchDB local qua port 5984; PHP container kết nối nội bộ qua `http://couchdb:5984`. Không ghi hoặc đưa credentials vào report.

## 3. Document types

Counts dưới đây là của database ứng dụng đang cấu hình, không tính hai design documents.

| Document type | Số lượng | Ví dụ `_id` | Mục đích / quan hệ nghiệp vụ |
| --- | ---: | --- | --- |
| `cart` | 4 | `cart:<customer-id>` | Giỏ của khách; chứa các dòng product/variant. |
| `customer` | 4 | `customer:<customer-id>` | Tài khoản khách hàng và hồ sơ. |
| `order` | 13 | `order:<legacy-id>` hoặc `order:checkout:<hash>` | Aggregate đơn; tham chiếu snapshot customer/product/shipping/payment. |
| `product` | 48 | `product:<legacy-id>` | Sản phẩm, biến thể size/giá/tồn và đường dẫn ảnh. |
| `review` | 270 | `review:<legacy-id>` | Đánh giá gắn product; tách riêng để truy vấn/quản lý. |
| `shipping_method` | 2 | `shipping_method:<code>` | Danh mục phương thức và phí ship hiện hành. |
| `staff` | 2 | `staff:<legacy-id>` | Tài khoản nhân viên/quản lý. |
| `voucher` | 5 | `voucher:<code>` | Mã giảm giá và số lượng còn lại. |

Project còn có hai design documents: `_design/domain_validation` và `_design/catalog_indexes`.

## 4. Order document model

Order gom dữ liệu cần để xử lý và đọc một đơn vào một JSON document: `customer`, `receiver`, `items[]`, `shipping`, `payment`, `totals`, `status_history[]` và tùy chọn `delivery_tracking`. `items[]` giữ `product_id`, `variant_id`, tên/size, số lượng, đơn giá và thành tiền tại thời điểm mua. `shipping` giữ snapshot mã/tên/phí ship; product hoặc phương thức ship hiện tại đổi sau này không viết lại lịch sử đơn.

Order tổng hợp `order:P06-0020` trong database demo cho thấy `_id`, `_rev`, `type`, `schema_version`, `items[]`, `shipping`, `payment`, `status`, `status_history[]` và `delivery_tracking`. Đơn có `status=shipping`, COD `unpaid`, history đơn hàng `pending → confirmed → packing → shipping`; item là snapshot. Đây là ví dụ an toàn cho phần trình bày, không chứa dữ liệu người mua thật.

Embedded data giúp trang chi tiết đọc một document thay vì JOIN `DON_HANG`/`CHI_TIET_DON_HANG`/`SAN_PHAM`; đánh đổi là snapshot lặp dữ liệu và cập nhật document lớn cần `_rev` mới.

## 5. Delivery document model

Delivery không phải document type riêng: `delivery_tracking` là object lồng trong order, có `tracking_code`, `status`, `estimated_delivery_date`, `delivered_at` và `history[]`. Trên order demo `order:P06-0020`, mã tổng hợp `P06-TRK-0020` ở trạng thái `in_transit`, history là `created → picked_up → in_transit`.

Order status và delivery status là hai enum riêng. Ví dụ: order `shipping` có thể mang delivery `created`, `picked_up`, `in_transit`, `out_for_delivery` hoặc `failed_delivery`. Order legacy đã giao nhưng không tracking tiếp tục được lưu mà không tạo history giả.

## 6. Relational vs Document comparison

| Tiêu chí | SQL Server cũ | CouchDB trong project |
| --- | --- | --- |
| Mô hình đơn hàng | `DON_HANG` và `CHI_TIET_DON_HANG`; dòng chi tiết nối `SAN_PHAM`/variant. | Một `order` chứa `items[]`, receiver, shipping, payment, totals và history. |
| Đọc order detail | JOIN header, details, product/variant và phương thức vận chuyển. | GET một order; thông tin cần giữ cho giao dịch nằm trong snapshot. |
| Schema | Cột, khóa ngoại, CHECK/UNIQUE được định nghĩa theo bảng. | JSON linh hoạt theo document type; `schema_version` và validator đặt các ràng buộc cục bộ. |
| Query | SQL JOIN, WHERE, ORDER BY và index quan hệ. | Mango selector qua `_find`; query path thực tế phụ thuộc index. |
| Snapshot | Cần lưu/tính rõ giá trị lịch sử nếu danh mục đổi. | `items[]` và `shipping` lưu tên/giá/phí lúc mua, đổi lại có dữ liệu trùng. |
| History | Thường tách bảng/event hoặc trigger tùy thiết kế cũ. | `status_history[]`, `payment_history[]`, `delivery_tracking.history[]` cùng order. |
| Toàn vẹn | FK và transaction nhiều bảng. | Validator kiểm tra trong một document; service PHP xử lý quan hệ, tồn kho và quy trình nhiều document. |

Source SQL `WEBTHOITRANG` được dùng để đối chiếu cấu trúc, không được đưa vào runtime.

## 7. Fauxton

URL thực tế: `http://127.0.0.1:5984/_utils/`; trang trả về **Project Fauxton, CouchDB 3.5.0** và hiển thị form đăng nhập. Từ Fauxton, đăng nhập bằng credentials cấu hình local; chọn `shopquan_ao_phase06_demo_test` để xem dữ liệu minh họa thay vì database ứng dụng đang cấu hình.

Quy trình trên giao diện: **Databases** → chọn database demo → **Documents** → mở `order:P06-0020`; kiểm tra `Design Documents` để mở `_design/domain_validation`; vào **Indexes** để xem Mango index; vào **Mango Query** để dán một request từ `database/couchdb/demo_queries/` và chạy. Ở màn Mango, thử Query 02 (shipping), Query 11 (failed delivery), rồi Query 12 (tracking code). Không nhấn Save khi chỉ đang quan sát.

Browser verification hoàn tất trong Fauxton đã đăng nhập (CouchDB 3.5.0). Đã mở database demo, **All Documents**, hai design document, màn **Mango Query**, và **Manage Indexes**. `order:P06-0020` hiển thị `_id`, `_rev` generation 3, `schema_version: 2`, `items[]`, `shipping`, `payment`, `status_history` và `delivery_tracking.history`; đây là dữ liệu synthetic. `_design/domain_validation` mở trực tiếp trong editor ở chế độ quan sát, còn `_design/catalog_indexes` và index list xác nhận định nghĩa index thực tế. Không lưu thay đổi trên Fauxton.

Đã chạy query từ UI: Query 02 shipping trả 6 kết quả / 6 documents examined; Query 11 failed delivery trả 1 / 58 (Fauxton cảnh báo chưa có index phù hợp); Query 12 tracking `P06-TRK-0020` trả 1 / 1 với `delivery_tracking_code`. Kết quả này khớp với API checks. Các trang UI cần cho kịch bản Fauxton đã được kiểm tra sau đăng nhập.

## 8. Mango Queries

Đã tạo 12 request body JSON cho pending, shipping, delivered, cancelled, theo customer, theo khoảng thời gian, COD chưa thanh toán, guest, delivery in-transit, out-for-delivery, failed delivery và tracking code. Mỗi file chạy được với `POST /<db>/_find`; mã customer/tracking trong ví dụ là mã synthetic `P06-*`.

Kết quả read-only trên `shopquan_ao_phase06_demo_test`: pending 5, shipping 6, delivered 6, cancelled 2, customer mẫu 5, khoảng ngày 27, COD pending chưa trả 5, guest 0, in-transit 2, out-for-delivery 1, failed delivery 1, tracking code 1. Dataset không có guest order nên Query 08 hợp lệ theo schema nhưng trả 0 kết quả. Query 06 dùng mốc 2026 quanh lần seed hiện tại; README ghi rõ cần điều chỉnh bounds nếu seed vào thời điểm khác.

## 9. Mango Indexes

`GET /shopquan_ao_phase06_demo_test/_index` xác nhận chín JSON Mango indexes cộng primary `_all_docs`:

| Index | Các trường | Query phù hợp |
| --- | --- | --- |
| `active_products` | `type, active` (partial product/active) | Catalog sản phẩm active. |
| `admin_products` | `type` (partial product) | Danh sách quản trị sản phẩm. |
| `active_reviews` | `type, active` (partial review/active) | Review public active. |
| `admin_reviews` | `type` (partial review) | Danh sách quản trị review. |
| `account_usernames` | `type, auth.username` (partial customer/staff) | Lookup tài khoản. |
| `customer_orders` | `type, customer.customer_id, ordered_at` | Lịch sử đơn của khách. |
| `admin_orders` | `type, status, ordered_at` | Lọc đơn quản trị theo trạng thái. |
| `admin_orders_by_date` | `type, ordered_at` | Lọc/sắp thứ tự đơn theo ngày. |
| `delivery_tracking_code` | `type, delivery_tracking.tracking_code` | Tra cứu mã vận đơn. |

Không tạo index trùng. Ba query delivery-status và query guest dùng `_all_docs` fallback trong dataset nhỏ; không thêm index cho demo ít document. Nếu các truy vấn đó trở thành workload thường xuyên trên dataset lớn, cần đo lại rồi cân nhắc index composite.

## 10. `_explain`

Đã gọi CouchDB `POST /_explain` cho ba request quan trọng:

| Query | Index được chọn |
| --- | --- |
| Order pending (`01_pending_orders.json`) | `_design/catalog_indexes / admin_orders` |
| Order theo customer (`05_customer_orders.json`) | `_design/catalog_indexes / customer_orders` |
| Tracking code (`12_tracking_code.json`) | `_design/catalog_indexes / delivery_tracking_code` |

Các kết quả khớp `use_index` của request. CouchDB mô tả `_explain` và `_find` dùng cùng logic chọn index; các truy vấn theo delivery status được đo ở mục tiếp theo và quét primary index.

## 11. `_id` and `_rev`

`_id` hiện dùng prefix theo type, ví dụ `product:<legacy-id>`, `order:<legacy-id>`, `cart:<customer-id>`; checkout idempotency dùng `order:checkout:<hash>`, review submission dùng `review:submission:<hash>`. Account IDs có prefix `customer:`/`staff:`. Không đổi quy ước IDs.

`_rev` do CouchDB tạo; dạng revision có generation và digest, không phải version schema hay ID do ứng dụng tự đặt. On fixture product, lần đầu cấp generation `1-…`; update với revision hiện hành cấp `2-…`. Order synthetic `order:P06-0020` đang ở generation 3 tại thời điểm kiểm tra. Mỗi update gửi nguyên document mới kèm `_rev` hiện tại.

## 12. Conflict 409

Script fixture độc lập tạo database `phase07_nosql_fixture_test`, đọc revision 1, cập nhật hợp lệ lên revision 2 rồi gửi bản cập nhật cạnh tranh với revision 1 cũ. CouchDB trả **HTTP 409 Conflict** như mong đợi. Nguyên nhân là revision đã bị thay thế; client cần đọc bản mới rồi áp dụng lại thay đổi hoặc yêu cầu người dùng tải lại.

Project xử lý một số trường hợp 409 có chủ đích: tạo account map conflict sang lỗi username đã tồn tại; cập nhật account yêu cầu tải lại; các vòng ghi product/voucher/order trong `OrderWorkflowService` đọc lại và retry có giới hạn. `CouchDbClient` tự nó chỉ trả status code, không tự merge document. [CouchDB conflict model](https://docs.couchdb.org/en/stable/replication/conflicts.html)

## 13. Validator

`_design/domain_validation` cho phép tám type thật (`customer`, `staff`, `product`, `voucher`, `shipping_method`, `cart`, `order`, `review`) và yêu cầu `schema_version: 2`. Validator kiểm tra:

- Customer/staff: legacy id, auth/profile, active; username/reset flag/hash; staff role thuộc manager/staff.
- Product: legacy id/name/category, variant không rỗng, images/active; variant có ID/size/price/stock/active hợp lệ.
- Cart, voucher, shipping method, review: các field cốt lõi, số lượng/số tiền không âm, rating 1–5.
- Order: status thuộc pending/confirmed/packing/shipping/delivered/cancelled; receiver, items, totals và `status_history` tồn tại; payment events hợp lệ và append-only; đơn đã paid không quay về unpaid.
- Delivery tracking: tracking code/date/status hợp lệ; history có thứ tự thời gian, status khớp event cuối, không xóa/sửa event cũ; retry chỉ từ `failed_delivery` sang `in_transit` hoặc `out_for_delivery`; tracked order chỉ hoàn tất khi delivery delivered.

Validator chỉ yêu cầu `status_history` là array; order workflow transitions được thực thi bởi PHP service, không được quy toàn bộ cho validator. CSRF, authorization, inventory, liên-document references và totals business rule vẫn thuộc PHP/service layer.

## 14. Design Documents

`_design/domain_validation` dùng JavaScript `validate_doc_update` để từ chối cập nhật bằng `forbidden`. `_design/catalog_indexes` có `language: query` và các định nghĩa Mango index. Project không khai báo business MapReduce views tại đây; Mango indexes là index definitions của CouchDB.

## 15. `_changes`

Phase realtime hiện dùng `_changes`: `OrderRealtimeController` tạo checkpoint sequence, sau đó gọi `POST /<db>/_changes?filter=_doc_ids&since=<sequence>&feed=longpoll&timeout=...` cho đúng order ID. Nếu có document change, controller đọc order mới và phát sự kiện SSE `order_updated`; nếu không đổi thì phát heartbeat. Customer/guest được kiểm tra ownership trước khi subscribe. Đây là flow đã có, Phase 07 không thêm realtime. [CouchDB changes API](https://docs.couchdb.org/en/stable/api/database/changes.html)

## 16. Attachment research

CouchDB hỗ trợ `_attachments` gắn với revision của document, nhưng project hiện giữ ảnh sản phẩm theo file storage và `images[].path` (`public/assets/img`/upload media), không nhúng bytes ảnh vào order/product JSON. Giữ mô hình đó để không làm thay đổi kiến trúc; attachment demo không thực hiện. Với attachment lớn, truyền qua Changes Feed bằng base64 làm tăng kích thước payload, nên không phù hợp chỉ để thay đường dẫn ảnh hiện tại. [CouchDB attachments API](https://docs.couchdb.org/en/stable/api/document/attachments.html)

## 17. Replication research/demo

Replication là OPTIONAL và **không triển khai**. CouchDB có thể push/pull đồng bộ database qua HTTP; replication có thể mang theo revision/attachment và cần xử lý conflict. Không tạo bản sao database trong Phase này để tránh ghi thêm dữ liệu ngoài fixture. [Replication introduction](https://docs.couchdb.org/en/stable/replication/intro.html)

## 18. Demo flow

Kịch bản 7–9 phút:

1. Mở Fauxton `http://127.0.0.1:5984/_utils/`, sign-in local và chọn `shopquan_ao_phase06_demo_test`.
2. **Databases**: chỉ ra số document; mở **Documents** và tìm `order:P06-0020`.
3. Chỉ `_id`, `_rev`, `type`, `schema_version`, `items[]`, `shipping`, `payment` và snapshot `totals`.
4. Mở `status_history[]` và `delivery_tracking.history[]`; phân biệt order status với delivery status; chỉ mã tracking synthetic.
5. **Mango Query**: chạy `02_shipping_orders.json`, sau đó `11_delivery_failed.json` và `12_tracking_code.json`.
6. **Indexes**: chỉ ra `admin_orders`, `delivery_tracking_code`; giải thích delivery-status demo hiện fallback.
7. Từ `_explain`/request đã đo, so sánh index được chọn với documents examined.
8. Chạy `php scripts/phase07_nosql_fixture_demo.php` trong app container để tái hiện revision 1→2, stale PUT 409 và validator 403; script tự cleanup database fixture.
9. Nếu cần nối với PHP, cập nhật order qua giao diện admin rồi refresh Fauxton để quan sát `_rev`/history đổi. Chỉ dùng database demo và không lưu credentials trong ảnh.

## 19. Screenshot checklist

- [ ] `01_fauxton_database.png` – database demo và document count.
- [ ] `02_order_document.png` – order synthetic với `_id`, `_rev`.
- [ ] `03_order_items_shipping_payment.png` – item/shipping/payment snapshot.
- [ ] `04_status_history.png` – lịch sử trạng thái order.
- [ ] `05_delivery_tracking.png` – tracking code, trạng thái, ETA và history.
- [ ] `06_mango_shipping.png` – query shipping.
- [ ] `07_mango_failed_delivery.png` – query failed delivery.
- [ ] `08_mango_tracking.png` – truy vấn mã tracking synthetic.
- [ ] `09_indexes.png` – danh sách Mango indexes.
- [ ] `10_explain.png` – index được chọn.
- [ ] `11_revision_conflict.png` – console result revision 1→2/409 trên fixture.
- [ ] `12_validator_reject.png` – HTTP 403 khi status invalid trên fixture.

Checklist trên là kế hoạch ảnh cho báo cáo; chưa tạo ảnh. Yêu cầu Phase 07 chỉ yêu cầu lập checklist và không tự chụp khi môi trường không có khả năng chụp an toàn. Không đưa PII hoặc credentials vào ảnh demo.

## 20. Advantages and limitations

Ưu điểm trong project: order detail đọc một aggregate; item/shipping snapshot giữ lịch sử giao dịch; status/payment/delivery histories đặt gần order; JSON/schema v2 cho phép thêm field có kiểm soát; REST và JSON dễ gọi từ PHP.

Giới hạn: snapshot lặp tên/giá; cập nhật yêu cầu `_rev`; stale write trả conflict; query không có index phù hợp có thể quét nhiều document; validator chỉ kiểm tra cục bộ một document, không thay FK/transaction nhiều bảng; event arrays tăng kích thước document theo thời gian.

## 21. Files modified

- `database/couchdb/demo_queries/README.md`
- `database/couchdb/demo_queries/01_pending_orders.json` … `12_tracking_code.json`
- `scripts/phase07_nosql_fixture_demo.php`
- `docs/PHASE_07_NOSQL_FAUXTON_REPORT.md`

Không sửa Composer, business logic, validator, index definitions, seed dataset hoặc database đang cấu hình.

## 22. Tests

- 12/12 JSON files parse hợp lệ bằng PowerShell `ConvertFrom-Json`.
- 12/12 Mango `_find` chạy HTTP 200 trên `shopquan_ao_phase06_demo_test`; số kết quả ghi tại mục 8.
- `_explain` chọn đúng `admin_orders`, `customer_orders`, `delivery_tracking_code`.
- `execution_stats: true` được CouchDB hỗ trợ; guest query và delivery-status query fallback quét 58 documents; tracking code query examined 1 document. Browser Fauxton xác nhận trực tiếp: shipping 6 results / 6 examined (~2 ms); failed delivery 1 / 58 (~3 ms); tracking code 1 / 1 (~1 ms). Mẫu nhỏ này chỉ minh họa query/index, không phải benchmark.
- `composer demo:verify` (PHP script `verify_phase06_demo.php`): PASS; kiểm tra 6 customer, 2 staff, 18 product, 27 order, order/delivery states, failed event và retry.
- `composer couchdb:diagnostics` trên database demo: PASS; active products/reviews và pending orders đều chạy với index mong đợi.
- `php -l scripts/phase07_nosql_fixture_demo.php`: PASS.
- `php scripts/phase07_nosql_fixture_demo.php`: PASS; update revision 1→2, stale PUT HTTP 409, invalid order status HTTP 403, database fixture đã cleanup.
- Không chạy lại `checkout:smoke`/`checkout:http-smoke` vì không thay PHP business code; baseline của các Phase trước không bị thay đổi.

## 23. Remaining issues

- Dataset Phase 06 không có guest order nên Query 08 trả 0. Query theo delivery status quét 58 documents vì không có index trên `delivery_tracking.status`; Fauxton cũng đưa cảnh báo phù hợp. Đây là giới hạn đã biết của dataset/index, không phải lỗi giao diện.
- Screenshot chưa tạo; đây là checklist chuẩn bị cho báo cáo, không phải điều kiện bắt buộc hoàn thành Phase 07. Attachment demo và replication đều optional, không thực hiện.

## 24. Ready for final presentation/deployment?

**Phase 07: DONE. Ready for final presentation: YES.** Fauxton sau đăng nhập đã xác minh database/Documents, Order document và revision, design documents/validator, Mango Query, Manage Indexes, và query shipping/failed-delivery/tracking trực tiếp trên UI. Các kiểm tra API, indexes, `_explain`, execution stats, `_rev`, stale-write 409, validator 403 và cleanup cũng PASS. Screenshot checklist vẫn là kế hoạch ảnh; không phải điều kiện chặn Phase theo yêu cầu. Deployment là công việc riêng và không được thực hiện trong Phase này.

Tài liệu CouchDB chính thức đã tham khảo: [Fauxton tour](https://docs.couchdb.org/en/stable/intro/tour.html), [Mango query/index/explain](https://docs.couchdb.org/en/stable/api/database/find.html), [revisions/MVCC](https://docs.couchdb.org/en/stable/intro/api.html), [conflicts](https://docs.couchdb.org/en/stable/replication/conflicts.html), [design document validator](https://docs.couchdb.org/en/stable/ddocs/ddocs.html), [changes feed](https://docs.couchdb.org/en/stable/api/database/changes.html), [attachments](https://docs.couchdb.org/en/stable/api/document/attachments.html), [replication](https://docs.couchdb.org/en/stable/replication/intro.html).


# BÁO CÁO MÔN NOSQL – BẢN NHÁP

## TÊN ĐỀ TÀI

**Tìm hiểu công cụ Fauxton để quản trị CSDL tài liệu CouchDB cho hệ thống ‘Quản lý đơn hàng và giao hàng của cửa hàng bán lẻ trực tuyến’. Xây dựng ứng dụng minh họa.**

> Ứng dụng PHP trong project `WEBTHOITRANG PHP + CouchDB` là ứng dụng minh họa nghiệp vụ. Trọng tâm báo cáo này là mô hình document CouchDB, cách truy vấn/kiểm tra dữ liệu và vai trò quản trị của Fauxton; không lặp lại báo cáo PHP.

## TRANG BÌA

- Tên trường: `[BỔ SUNG]`
- Khoa: `[BỔ SUNG]`
- Môn học: `[BỔ SUNG]`
- Giảng viên: `[BỔ SUNG]`
- Sinh viên: `[BỔ SUNG]`
- MSSV: `[BỔ SUNG]`
- Lớp: `[BỔ SUNG]`
- Năm học: `[BỔ SUNG]`

## CHƯƠNG 1 – TỔNG QUAN

### 1.1 Lý do chọn đề tài

Hệ thống bán hàng cần lưu nhiều loại dữ liệu như sản phẩm, tài khoản, giỏ hàng, đơn và lịch sử giao nhận. Khi chọn cơ sở dữ liệu tài liệu, các thông tin thường được đọc cùng nhau trong một order có thể được tổ chức thành JSON document. CouchDB còn cung cấp REST API, Mango Query, index và công cụ Fauxton để quan sát/truy vấn dữ liệu.

### 1.2 Mục tiêu

- Tìm hiểu mô hình NoSQL dạng document và một số cơ chế của CouchDB.
- Thực hành tạo/quan sát database, document, revision, index, Mango query và validator trên Fauxton.
- Phân tích cách order/delivery được biểu diễn trong document CouchDB.
- Dùng ứng dụng PHP hiện có làm client nghiệp vụ để minh họa thao tác với CouchDB.

### 1.3 Phạm vi

Báo cáo dựa trên CouchDB 3.5.0 và bộ dữ liệu demo tổng hợp Phase 06. Fauxton được xác minh trực tiếp trên môi trường local tại `http://127.0.0.1:5984/_utils/`. Phase 07 đã đọc dữ liệu ứng dụng ở chế độ read-only và dùng database fixture tạm riêng cho thao tác ghi thử; fixture này đã được xóa. Báo cáo không mô tả database quan hệ cũ như runtime hiện tại.

### 1.4 Bài toán đơn hàng và giao hàng

Khách chọn sản phẩm, checkout tạo order; nhân viên xử lý order qua các bước xác nhận/đóng gói/giao hàng; delivery có tracking, lịch sử, khả năng thất bại và retry. Người dùng xem timeline theo phạm vi sở hữu. Dữ liệu cần cho Order Detail có thể được giữ cùng order dưới dạng snapshot và lịch sử lồng nhau.

## CHƯƠNG 2 – NOSQL VÀ COUCHDB

### 2.1 Cơ sở dữ liệu NoSQL

NoSQL là nhóm hệ quản trị dữ liệu không giới hạn ở cách tổ chức bảng quan hệ truyền thống. Tùy hệ thống, dữ liệu có thể lưu dạng key-value, document, column-family hoặc graph. Project này dùng document database; việc chọn NoSQL không loại bỏ yêu cầu thiết kế dữ liệu, kiểm tra tính hợp lệ hoặc tạo index cho truy vấn.

### 2.2 Document database

Document database lưu từng bản ghi theo cấu trúc tài liệu, thường có thể biểu diễn bằng JSON. Các document có thể chứa object hoặc array lồng nhau. Mô hình này phù hợp khi ứng dụng thường đọc một aggregate hoàn chỉnh; ngược lại, dữ liệu lặp lại và cập nhật document lớn là những đánh đổi cần tính đến.

### 2.3 CouchDB trong project

CouchDB là nơi lưu dữ liệu nghiệp vụ chính. PHP gọi CouchDB qua HTTP REST bằng `CouchDbClient`/cURL; truy vấn Mango gửi tới `_find`, còn index và validator được tổ chức trong design document. CouchDB chạy trong container riêng ở local; Fauxton là giao diện web quản trị. Project sử dụng các document với `type` và `schema_version: 2`.

### 2.4 Các khái niệm được minh họa

- **JSON document và `_id`:** mỗi document có định danh duy nhất trong database; project quy ước prefix như `order:`, `product:`, `customer:`.
- **`_rev` và MVCC:** CouchDB gắn revision cho document. Update cần revision hiện hành; cập nhật bằng revision cũ có thể nhận HTTP 409.
- **Mango Query:** selector JSON truy vấn document; `_find` hỗ trợ chọn trường, giới hạn và sắp xếp.
- **Index:** index phù hợp giảm số document cần xét cho các đường truy vấn; `_explain` giúp xem index được chọn.
- **Design document:** project có `_design/catalog_indexes` và `_design/domain_validation`.
- **Validation:** `validate_doc_update` từ chối document sai contract; đây là kiểm tra ở cấp document, không thay thế quyền nghiệp vụ hoặc transaction liên-document.
- **`_changes`:** PHP dùng feed này trong SSE để theo dõi thay đổi một order; Phase này không xây thêm realtime.
- **Replication:** chỉ nghiên cứu tài liệu ở Phase 07; không cấu hình hoặc thực hiện replication.

### 2.5 Ưu và nhược điểm liên quan project

Order có thể chứa items, receiver/shipping/payment snapshot và histories, thuận tiện đọc một aggregate. Tuy nhiên, snapshot làm lặp một phần thông tin, cập nhật cần `_rev`, conflict phải được xử lý, Mango query cần index phù hợp, và validator chỉ bảo đảm ràng buộc cục bộ. Các quy trình stock/voucher hoặc tham chiếu nhiều document vẫn cần PHP service điều phối.

## CHƯƠNG 3 – FAUXTON

### 3.1 Fauxton là gì

Fauxton là giao diện web đi kèm CouchDB để người vận hành xem database, document, design document và thao tác/query dữ liệu. Trong project, Fauxton truy cập CouchDB local qua loopback; nó là công cụ quản trị, không phải giao diện mua hàng của customer.

### 3.2 Vai trò trong đề tài

Fauxton được dùng để quan sát trực tiếp các khái niệm CouchDB: danh sách database/document, nội dung order JSON, `_id`/`_rev`, Mango Query, Manage Indexes và design document. Thay đổi dữ liệu qua Fauxton có thể ảnh hưởng database, do đó kịch bản báo cáo chỉ mở/đọc hoặc chạy query; không lưu thay đổi không cần thiết.

### 3.3 Các thao tác đã kiểm tra trên giao diện

Phase 07 ghi nhận đã đăng nhập Fauxton (CouchDB 3.5.0), mở database demo `shopquan_ao_phase06_demo_test`, All Documents, order tổng hợp `order:P06-0020`, hai design document, Mango Query và Manage Indexes. Đã chạy query shipping, failed delivery và tracking code. Đây là kết quả browser được báo cáo; screenshot để chèn vào đồ án vẫn chưa được tạo.

### 3.4 Fauxton và PHP website

Website PHP phục vụ nghiệp vụ mua hàng và quản trị. Fauxton cho người vận hành/database xem document, index, revision và query. Hai giao diện có mục đích khác nhau; Fauxton không thay trang admin PHP và không được mở public trong runbook deployment.

## CHƯƠNG 4 – PHÂN TÍCH BÀI TOÁN

```text
Customer → Checkout → Order → Staff xử lý → Shipping → Delivery Tracking → Delivered
```

Order status (`pending`, `confirmed`, `packing`, `shipping`, `delivered`, `cancelled`) mô tả vòng đời đơn. Delivery status (`created`, `picked_up`, `in_transit`, `out_for_delivery`, `failed_delivery`, `delivered`) mô tả tiến trình giao. Khi giao thất bại, order vẫn ở `shipping`; khi giao xong, order và tracking cùng kết thúc.

Order là aggregate phù hợp để lưu snapshot các mục được mua, địa chỉ nhận, phí ship, payment state và các lịch sử cần xem chung. Product, customer, voucher, cart, shipping method và review vẫn có document riêng vì chúng có vòng đời hoặc truy vấn riêng. CouchDB validator có thể kiểm tra cấu trúc và ràng buộc trong một document; tính đúng của tồn kho và tham chiếu nhiều document do PHP service đảm nhiệm.

## CHƯƠNG 5 – THIẾT KẾ DOCUMENT MODEL

### 5.1 Các document type

| Type | Vai trò |
|---|---|
| `customer`, `staff` | Tài khoản ứng dụng và hồ sơ; mật khẩu ứng dụng dùng hash. |
| `product` | Sản phẩm cùng `variants[]` theo size/giá/tồn và đường dẫn ảnh. |
| `cart` | Cart thành viên; cart guest nằm ở session PHP. |
| `order` | Aggregate giao dịch và tùy chọn delivery tracking. |
| `voucher` | Mã giảm giá và số lượng còn lại. |
| `shipping_method` | Danh mục phương thức/giá ship hiện tại. |
| `review` | Đánh giá và phản hồi quản trị, lưu tách khỏi product. |

Đây là các type được xác nhận bởi schema v2 và validator. Fauxton cũng hiển thị hai design document: `_design/catalog_indexes`, `_design/domain_validation`.

### 5.2 Product và Order

Product gom các biến thể liên quan vào `variants[]`, tránh document riêng cho từng size. Order chứa snapshot `items[]` gồm product/variant ID, tên/size, số lượng, đơn giá và thành tiền tại thời điểm đặt. `shipping` lưu snapshot phương thức/phí; `payment` và `totals` giữ trạng thái và giá trị giao dịch.

### 5.3 Order history và delivery tracking

`status_history[]` lưu các event order. `delivery_tracking` là object lồng trong order, gồm tracking code, ngày dự kiến, trạng thái hiện tại, thời điểm giao xong và `history[]`. Mỗi event giao lưu trạng thái/thời gian/ghi chú/actor theo schema. Payment history là một mảng khác; không đồng nhất payment, order và delivery status.

### 5.4 Denormalization và ảnh

Tên sản phẩm, size/giá và phí giao được snapshot để order cũ không thay đổi khi catalog cập nhật. Điều này giảm số lần đọc để dựng chi tiết đơn nhưng tạo dữ liệu trùng và cần `_rev` khi ghi. Product image nằm trong product/media storage; order snapshot không lưu image hoặc image identifier lịch sử. UI vì vậy hiển thị placeholder. Đây là cải tiến có thể xem xét sau, không thuộc schema hiện tại.

### 5.5 Đối chiếu mô hình SQL cũ

Source SQL mẫu trong repository được dùng để đối chiếu cấu trúc, không phải backend runtime hiện tại. Bảng dưới là ánh xạ khái niệm có trong `schema-v2-and-mapping.md`.

**Bảng 5.1. Ánh xạ quan hệ cũ sang document model**

| SQL Server cũ (mẫu) | CouchDB trong project |
|---|---|
| `KHACH_HANG` | `customer` document |
| `NHAN_VIEN` | `staff` document |
| `SAN_PHAM`, `CHI_TIET_SP`, `KICH_THUOC`, `HINH_ANH_SP` | `product` với `variants[]`, `images[]` |
| `DON_HANG` | `order` document |
| `CHI_TIET_DON_HANG` | `order.items[]` snapshot |
| `GIO_HANG` | `cart` document theo customer; guest lưu session |
| `MA_GIAM_GIA` | `voucher` document |
| `PHUONG_THUC_VAN_CHUYEN` | `shipping_method` document và snapshot trong order |
| `DANH_GIA` | `review` document riêng |

SQL Server chỉ là nguồn mẫu/migration reference. Project PHP hiện tại dùng CouchDB.

## CHƯƠNG 6 – ỨNG DỤNG MINH HỌA PHP + COUCHDB

Ứng dụng PHP 8.3 không dùng framework; luồng chính là Browser/API → Router → Controller → Service → Repository → CouchDbClient → CouchDB. `CouchDbClient` gọi REST bằng cURL. Khi checkout, `CheckoutService` tạo order và xử lý reservation/recovery; `OrderWorkflowService` cập nhật order/delivery với revision mới; customer/admin controllers đọc order theo ownership/role.

Đối với realtime hiện có, `OrderRealtimeController` gọi CouchDB `_changes` cho document cụ thể và phát dữ liệu đã lọc qua SSE. Đây là chức năng minh họa của project PHP đã có từ Phase 04; Phase NoSQL không thêm SSE, WebSocket hay service realtime mới.

## CHƯƠNG 7 – MANGO QUERY VÀ INDEX

Các file query nằm trong `database/couchdb/demo_queries/`. `_find` nhận selector JSON. Ví dụ query shipping thực tế chọn `admin_orders`:

**Ví dụ 7.1. Selector rút gọn cho order đang giao**

```json
{
  "selector": {
    "type": {"$eq": "order"},
    "status": {"$eq": "shipping"}
  },
  "use_index": ["_design/catalog_indexes", "admin_orders"],
  "sort": [{"ordered_at": "desc"}],
  "fields": ["_id", "status", "ordered_at", "delivery_tracking"],
  "limit": 20,
  "execution_stats": true
}
```

**Bảng 7.1. Nhóm truy vấn demo và kết quả đã ghi nhận**

| Nhóm | Mục đích / selector | Kết quả Phase 07 | Index / lưu ý |
|---|---|---:|---|
| Pending | order có `status=pending` | 5 | `admin_orders` |
| Shipping | order có `status=shipping` | 6 | `admin_orders`; Fauxton 6 examined/6 results |
| Delivered | order có `status=delivered` | 6 | `admin_orders` |
| Cancelled | order có `status=cancelled` | 2 | `admin_orders` |
| Customer orders | `customer.customer_id=P06-001` | 5 | `customer_orders` |
| Date range | `ordered_at` từ 2026-09-01 đến trước 2026-11-01 | 27 | `admin_orders_by_date`; bounds cần điều chỉnh theo seed date |
| Unpaid COD | pending + COD + `paid=false` | 5 | `admin_orders`; query mẫu là pending COD chưa thu |
| Guest orders | guest marker | 0 | Bộ demo không có guest order |
| In transit | `delivery_tracking.status=in_transit` | 2 | Không có index status delivery; fallback quét 58 documents |
| Out for delivery | status tương ứng | 1 | Fallback quét 58 documents |
| Failed delivery | `failed_delivery` | 1 | Fallback; Fauxton 1 result/58 examined và có cảnh báo thiếu index phù hợp |
| Tracking code | `P06-TRK-0020` | 1 | `delivery_tracking_code`; Fauxton 1 examined/1 result |

Kết quả trên là số liệu dataset demo nhỏ tại ngày kiểm tra, không phải benchmark hiệu năng. Các thống kê Fauxton từng ghi khoảng 2–3 ms cũng chỉ có ý nghĩa minh họa tại lần chạy đó. Phase 07 ghi nhận 9 Mango indexes cùng primary `_all_docs`: active products, admin products, active/admin reviews, usernames, customer orders, admin orders, admin orders by date và delivery tracking code. Không có delivery status index vì query demo nhỏ và chưa được xem là workload cần tối ưu.

`_explain` xác nhận ba query quan trọng chọn lần lượt `admin_orders`, `customer_orders`, `delivery_tracking_code`. Query guest và delivery status dùng fallback. Nếu dữ liệu tăng, cần đo lại và cân nhắc index dựa trên workload thay vì thêm index chỉ để loại cảnh báo ở demo.

## CHƯƠNG 8 – `_ID`, `_REV`, CONFLICT VÀ VALIDATION

### 8.1 `_id` và `_rev`

`_id` là định danh document, project thường gắn prefix type như `order:<id>` hoặc `product:<id>`. `_rev` do CouchDB sinh và quản lý; generation tăng theo lần ghi thành công. Đây không phải số phiên bản schema.

### 8.2 Cập nhật và conflict

Để cập nhật, client cần gửi nội dung mới kèm `_rev` hiện hành. Phase 07 tạo fixture riêng: đọc revision 1, ghi update hợp lệ thành revision 2, rồi thử PUT bằng revision 1 cũ. CouchDB trả HTTP 409 Conflict. Ứng dụng xử lý theo ngữ cảnh: một số vòng ghi order đọc lại và retry có giới hạn; nơi khác yêu cầu người dùng tải lại hoặc báo username đã tồn tại. `CouchDbClient` không tự merge document.

### 8.3 Validator

`_design/domain_validation` dùng `validate_doc_update`; cho phép các type của schema v2, kiểm tra field/kiểu/range và append-only history, đồng bộ trạng thái delivery/order, cấm transition delivery sai và không cho payment đã paid quay về unpaid. Fixture invalid order status bị CouchDB từ chối HTTP 403 trong Phase 07.

Validator không phải cơ chế authorization đầy đủ: quyền người dùng, CSRF, tồn kho, tổng tiền và quan hệ liên-document do ứng dụng PHP/service kiểm tra. `status_history` được yêu cầu là array, còn transition order được kiểm tra bởi workflow service.

## CHƯƠNG 9 – THỰC HÀNH FAUXTON

Các vị trí dưới đây là placeholder để chèn ảnh do người làm báo cáo chụp. Bằng chứng browser Phase 07 tồn tại trong report, nhưng file ảnh chưa được tạo.

**Hình 9.1: Giao diện Fauxton sau khi đăng nhập**  
`[CHÈN HÌNH NOSQL-01 – Không để lộ credential/session tại đây]`

**Hình 9.2: Database demo trong danh sách Fauxton**  
`[CHÈN HÌNH NOSQL-02 – Database overview tại đây]`

**Hình 9.3: Order document tổng hợp**  
`[CHÈN HÌNH NOSQL-03 – order:P06-0020 với _id/_rev tại đây]`

**Hình 9.4: `status_history[]` của order**  
`[CHÈN HÌNH NOSQL-04 – Order history tại đây]`

**Hình 9.5: `delivery_tracking` và history**  
`[CHÈN HÌNH NOSQL-05 – Chỉ dùng tracking synthetic tại đây]`

**Hình 9.6: Mango Query shipping**  
`[CHÈN HÌNH NOSQL-06 – Query và số kết quả tại đây]`

**Hình 9.7: Mango Indexes**  
`[CHÈN HÌNH NOSQL-07 – Manage Indexes tại đây]`

**Hình 9.8: `_explain`**  
`[CHÈN HÌNH NOSQL-08 – Index được chọn tại đây]`

**Hình 9.9: `_rev` sau một lần update**  
`[CHÈN HÌNH NOSQL-09 – Có thể dùng fixture đã cleanup theo script tại đây]`

**Hình 9.10: Validator từ chối status không hợp lệ**  
`[CHÈN HÌNH NOSQL-10 – Kết quả 403 trên fixture tại đây]`

**Hình 9.11: Conflict 409 do stale revision**  
`[CHÈN HÌNH NOSQL-11 – Console/test output fixture 409 tại đây]`

Khi chụp, dùng database demo hoặc fixture tổng hợp, crop thông tin đăng nhập và không hiển thị `.env`, mật khẩu, JWT secret, CouchDB credential, PII hay dữ liệu môi trường thật.

## CHƯƠNG 10 – ĐÁNH GIÁ

### 10.1 Kết quả đạt được

Đã phân tích document model order/delivery, các Mango queries/indexes, `_id`/`_rev`, conflict và validator. Giao diện Fauxton thực tế đã được dùng để xem document, design documents và indexes, chạy ba query minh họa. Fixture độc lập xác nhận update revision, conflict 409, validator 403 và cleanup.

### 10.2 Ưu điểm CouchDB trong bài toán

Order detail có thể đọc snapshot cần thiết từ một document; JSON dễ biểu diễn items và histories; REST thuận tiện kết nối từ PHP; Fauxton giúp quan sát document/query/index trực tiếp. `schema_version` cùng validator tạo một mức contract giữa các document.

### 10.3 Hạn chế

Document có thể tăng kích thước theo history; snapshot tạo dữ liệu lặp; xung đột revision cần quy trình xử lý; validator không kiểm tra quan hệ giữa nhiều document. Một số Mango query demo thiếu index và phải quét bộ dữ liệu nhỏ. Fauxton là công cụ quản trị, không thay thế authorization và giao diện nghiệp vụ của app.

### 10.4 Vai trò Fauxton

Fauxton được dùng trong báo cáo như giao diện thực hành để quan sát database, document, design document, index và chạy Mango query. Các thao tác ghi thử được tách sang fixture rồi cleanup. Không có ảnh chụp lưu trong project cho tới thời điểm lập bản nháp.

### 10.5 Hướng phát triển

Có thể nghiên cứu replication trên database test cô lập, bổ sung chỉ mục sau khi xác định workload, thiết kế archive cho document history dài, và xem xét lưu image identifier ổn định trong order. Các hướng này chưa được triển khai trong project hiện tại.

## KẾT LUẬN

Project minh họa cách CouchDB lưu dữ liệu bán hàng theo document, trong đó order giữ snapshot item, thanh toán, shipping và các lịch sử. Mango Query cùng index hỗ trợ lọc các order theo trạng thái, customer, thời gian hoặc tracking code. `_id` nhận diện document, `_rev` hỗ trợ optimistic concurrency; fixture đã cho thấy ghi bằng revision cũ trả 409. Design document validator giúp từ chối cấu trúc/trạng thái không hợp lệ ở phạm vi một document.

Fauxton đóng vai trò giao diện quản trị và quan sát database. Phase 07 ghi nhận đã kiểm tra database demo, order document, design documents, Manage Indexes và Mango Query trực tiếp. Ứng dụng PHP kết nối CouchDB qua REST/cURL và là lớp nghiệp vụ minh họa; Fauxton không thay thế website. Replication và deployment chưa được thực hiện. Trước khi nộp cần chụp các hình trong checklist bằng dữ liệu tổng hợp và bổ sung thông tin bìa.

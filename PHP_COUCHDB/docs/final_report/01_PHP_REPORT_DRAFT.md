# BÁO CÁO ĐỒ ÁN PHP – BẢN NHÁP

## TRANG BÌA

- Tên trường: `[BỔ SUNG]`
- Khoa: `[BỔ SUNG]`
- Môn học: `[BỔ SUNG]`
- Tên đề tài: **XÂY DỰNG WEBSITE BÁN HÀNG THỜI TRANG TRỰC TUYẾN BẰNG PHP**
- Giảng viên: `[BỔ SUNG]`
- Sinh viên: `[BỔ SUNG]`
- MSSV: `[BỔ SUNG]`
- Lớp: `[BỔ SUNG]`
- Năm học: `[BỔ SUNG]`

> Tên trong repository là **WEBTHOITRANG PHP + CouchDB** (`composer.json`: `webthoitrang/php-couchdb`). Tên đề tài trên bìa được giữ theo yêu cầu; đây là tên mô tả, không phải tên sản phẩm riêng được xác nhận trong source.

## LỜI MỞ ĐẦU

Thương mại điện tử giúp cửa hàng giới thiệu sản phẩm và tiếp nhận đơn hàng qua Internet. Để minh họa các bước mua hàng trực tuyến, đồ án xây dựng website bán hàng thời trang bằng PHP, trong đó người dùng có thể xem sản phẩm, chọn biến thể, quản lý giỏ hàng và đặt hàng; nhân viên có thể theo dõi, cập nhật đơn và giao hàng.

Đề tài được chọn nhằm vận dụng kiến thức lập trình web vào một quy trình có nhiều trạng thái và dữ liệu liên quan. Ngoài giao diện nghiệp vụ, project còn dùng CouchDB làm nơi lưu trữ document và có thể kiểm tra dữ liệu bằng Fauxton. Phạm vi đồ án tập trung vào ứng dụng chạy cục bộ bằng Docker, các chức năng mua hàng và quản trị đã có trong source, cùng các kiểm thử tự động và kiểm tra trình duyệt được ghi trong báo cáo từng giai đoạn. Project chưa được triển khai lên Render; vì vậy báo cáo không xem deployment trực tuyến là kết quả đã đạt.

## CHƯƠNG 1 – TỔNG QUAN ĐỀ TÀI

### 1.1 Lý do chọn đề tài

Một website bán hàng là bài toán gần với hoạt động thực tế: cần trình bày danh mục sản phẩm, ghi nhận lựa chọn của khách, kiểm tra giá và tồn kho, lập đơn hàng, rồi theo dõi việc xử lý và giao nhận. Các dữ liệu này cũng phù hợp để thực hành PHP và cơ sở dữ liệu tài liệu CouchDB.

### 1.2 Mục tiêu

- Xây dựng ứng dụng web có các vai trò khách vãng lai, khách hàng và nhân viên quản trị.
- Thể hiện luồng từ catalog, cart, checkout đến quản lý order và delivery.
- Tách logic thành Router, Controller, Service, Repository và lớp giao tiếp CouchDB.
- Kiểm tra các luồng quan trọng bằng smoke test, HTTP integration test và browser verification theo báo cáo Phase.

### 1.3 Đối tượng sử dụng

| Actor | Vai trò trong ứng dụng |
|---|---|
| Guest | Xem catalog, thao tác giỏ theo session, checkout và xem các đơn guest thuộc session hiện tại. |
| Customer | Đăng ký/đăng nhập, dùng cart thành viên, checkout, xem đơn của mình, xác nhận nhận hàng và gửi review. |
| Staff | Đăng nhập quản trị, xử lý order/delivery, xem và phản hồi review; quyền cụ thể được giới hạn ở service/controller. |
| Manager | Có các quyền staff và các thao tác quản trị bổ sung như quản lý sản phẩm, ẩn/khôi phục review. |

### 1.4 Phạm vi chức năng

Phạm vi hiện có gồm authentication bằng session cho website và JWT Bearer độc lập cho JSON API; catalog sản phẩm, tìm kiếm/lọc/sắp xếp/phân trang; giỏ guest và giỏ member; checkout COD, voucher và shipping method; order history/detail; quản trị order/delivery/payment; review; quản trị catalog; báo cáo doanh thu; và SSE cập nhật trang chi tiết đơn cho customer/guest. Realtime sử dụng PHP SSE và CouchDB `_changes`, không phải WebSocket.

Checkout mới chỉ bật COD. Bank transfer/card có thể được staff xác minh thủ công sau khi đối soát ngoài hệ thống; không có kết nối cổng thanh toán tự động hoặc hoàn tiền.

### 1.5 Công nghệ sử dụng

**Bảng 1.1. Thành phần kỹ thuật**

| Thành phần | Công nghệ | Vai trò |
|---|---|---|
| Backend | PHP 8.3, không dùng framework | Router, nghiệp vụ, API và render template. |
| Web server | Apache (`php:8.3-apache`) | Phục vụ ứng dụng trong container. |
| Giao diện | HTML, CSS, JavaScript, Bootstrap cục bộ | Hiển thị trang và hỗ trợ tương tác/responsive. |
| Dependency | Composer, PSR-4 | Autoload namespace `App\`; cài `firebase/php-jwt`. |
| Cơ sở dữ liệu | CouchDB 3.5.0, JSON/Mango/REST | Lưu document; PHP gọi REST qua cURL. |
| Xác thực API | JWT HS256 | Access token mặc định 15 phút; quyền và trạng thái account được đọc lại khi request. |
| Môi trường local | Docker Compose | Chạy Apache/PHP, CouchDB và các volume riêng. |

## CHƯƠNG 2 – PHÂN TÍCH VÀ THIẾT KẾ HỆ THỐNG

### 2.1 Kiến trúc

Request từ trình duyệt hoặc API đi theo luồng:

```text
Browser / API → Router → Controller → Service → Repository → CouchDbClient → CouchDB
```

Không phải mọi module đều có Repository riêng; ví dụ checkout/order dùng `CheckoutRepository`, còn giỏ guest nằm trong session. Router và wiring hiện được khai báo tại `public/index.php`. Template PHP dựng giao diện; client không truy cập trực tiếp CouchDB.

### 2.2 Chức năng theo actor

- **Guest:** duyệt sản phẩm và sử dụng cart gắn với session; lịch sử guest giới hạn theo danh sách ID trong session.
- **Customer:** có tài khoản và cart document; xem tối đa 25 order mỗi trang, chi tiết chỉ khi sở hữu order; có thể xác nhận đã nhận và gửi review.
- **Staff:** xem danh sách admin có filter/cursor, cập nhật order/delivery, xử lý payment theo rule, xem và phản hồi review.
- **Manager:** ngoài công việc quản lý order, có thể tạo/sửa/ẩn mềm/khôi phục sản phẩm và ẩn/khôi phục review theo quyền hiện thực.

### 2.3 Workflow mua hàng

```text
Catalog → Cart → Checkout preview → Validate giá/tồn kho/voucher/ship → Order
```

Giá và điều kiện được tính lại từ CouchDB ở checkout. Checkout dùng idempotency token/journal để có thể nhận diện lần gửi lặp và phục hồi một số bước ghi stock/voucher. Ứng dụng hiện chỉ bật phương thức COD cho checkout mới.

### 2.4 Workflow order

```text
pending → confirmed → packing → shipping → delivered
```

Hủy chỉ được phép trước khi bắt đầu giao, ở các bước `pending`, `confirmed`, `packing`. Đơn đã thanh toán không được hủy khi chưa có quy trình refund. Với order đã có delivery tracking, order không thể được đánh dấu `delivered` độc lập trước delivery. Lịch sử order được lưu trong `status_history[]`.

### 2.5 Workflow delivery

```text
created → picked_up → in_transit → out_for_delivery → delivered
```

`failed_delivery` có thể được ghi từ các trạng thái đang xử lý; có thể retry từ `failed_delivery` về `in_transit` hoặc `out_for_delivery`. Khi thất bại, order vẫn ở `shipping`. Khi delivery hoàn tất, delivery và order cùng thành `delivered`. Mỗi event có trạng thái, thời gian UTC, ghi chú và actor; history là append-only theo validator.

### 2.6 Payment

Payment status độc lập với order/delivery status. COD ban đầu có thể `unpaid`; việc xác nhận đã nhận của customer/guest ghi nhận COD theo quy tắc hiện hành. Staff có thể ghi nhận COD đã thu cho đơn delivered hoặc xác minh thủ công bank transfer/card sau đối soát bên ngoài. Chưa có online gateway, thanh toán tự động hay refund.

## CHƯƠNG 3 – THIẾT KẾ DỮ LIỆU

### 3.1 Document types

Validator cho phép các business type: `customer`, `staff`, `product`, `voucher`, `shipping_method`, `cart`, `order`, `review`. Các business document dùng `schema_version: 2`. `delivery_tracking` không phải document type độc lập mà là object nằm trong order.

### 3.2 Order document

Order gom dữ liệu cần để hiển thị và tiếp tục xử lý đơn: customer/guest marker, receiver, `items[]`, shipping snapshot, payment, totals, status, các history và tùy chọn `delivery_tracking`. Item snapshot lưu product/variant reference cùng tên/size, số lượng, đơn giá và thành tiền lúc mua. Ảnh sản phẩm không được lưu trong order snapshot.

**Ví dụ 3.1. Mẫu Order rút gọn, tổng hợp và không chứa dữ liệu cá nhân**

```json
{
  "_id": "order:P06-XXXX",
  "type": "order",
  "schema_version": 2,
  "items": [{
    "product_id": "product:DEMO",
    "variant_id": "VAR-DEMO",
    "product_name": "Sản phẩm minh họa",
    "size": "M",
    "quantity": 1,
    "unit_price": 100000,
    "line_total": 100000
  }],
  "shipping": {"method_code": "BD", "fee": 0, "currency": "VND"},
  "payment": {"method": "cod", "status": "unpaid", "paid": false},
  "totals": {"subtotal": 100000, "shipping_fee": 0, "discount_amount": 0, "grand_total": 100000},
  "status": "shipping",
  "status_history": [{"status": "shipping", "at": "<UTC_TIMESTAMP>"}],
  "delivery_tracking": {
    "tracking_code": "<SYNTHETIC_TRACKING_CODE>",
    "estimated_delivery_date": "<YYYY-MM-DD>",
    "status": "in_transit",
    "history": [{"status": "in_transit", "at": "<UTC_TIMESTAMP>", "note": "<NOTE>"}]
  }
}
```

Đây là mẫu minh họa giản lược theo field thực tế, không phải bản sao của một record. Trường `_rev` do CouchDB cấp khi ghi.

### 3.3 Cách tổ chức dữ liệu

Product chứa variant size/giá/tồn kho và đường dẫn ảnh. Cart của khách đăng nhập là document; cart khách vãng lai ở session. Review, customer, staff, voucher và shipping method là các document riêng. Thiết kế document cho phép Order Detail đọc snapshot giao dịch trong một aggregate, đổi lại có dữ liệu lặp và mỗi lần cập nhật phải xử lý `_rev`.

## CHƯƠNG 4 – XÂY DỰNG CÁC CHỨC NĂNG

### 4.1 Authentication

Đăng ký customer, đăng nhập/đăng xuất, đổi mật khẩu và CSRF được triển khai cho website dùng session. Mật khẩu được lưu dưới dạng hash PHP. Nhân viên cần hash hợp lệ mới đăng nhập. JSON API cấp và kiểm tra JWT; token dùng HS256, issuer/audience và TTL được cấu hình. Token không thay cho session của website.

### 4.2 Product/Catalog

Trang catalog có danh sách, chi tiết, tìm kiếm, lọc, sắp xếp, phân trang và biến thể. Public JSON API hỗ trợ search/category/price/sort/page. Manager có thể quản lý sản phẩm và upload ảnh JPEG/PNG/WebP theo kiểm tra phía server. Ảnh lưu ngoài web root và được phục vụ qua route media. Catalog filter hiện có phần chạy ở PHP sau Mango query, được README giới hạn cho dataset demo hiện tại.

### 4.3 Cart và checkout

Guest cart lưu trong PHP session; cart member lưu trên CouchDB và nhận các dòng cart guest khi đăng nhập. Checkout có preview, kiểm tra lại stock và giá, shipping method, voucher, COD, snapshot giá/tổng và idempotency. Reservation stock/voucher có marker/journal và quy trình bù trừ để xử lý retry.

### 4.4 Order management và lịch sử

Customer xem history/detail giới hạn theo quyền sở hữu; guest chỉ mở order gắn với session. Staff/manager có admin order list/detail, filter trạng thái, cursor pagination và transition. Giao diện hiển thị line item, size, số lượng, đơn giá, tổng, payment state và order timeline. Order legacy không tracking vẫn hiển thị trạng thái “Chưa có thông tin giao hàng”.

### 4.5 Delivery tracking

Khi order vào `shipping`, hệ thống tạo tracking document lồng trong Order, sinh mã có kiểm tra trùng qua Mango query và ghi ETA. Nhân viên cập nhật delivery status/ghi chú; customer và guest đọc timeline trong phạm vi quyền. Thất bại, retry và delivered đều được ghi thành event riêng.

### 4.6 Reviews, revenue và quản trị

Review có rating/nội dung, giới hạn theo tài khoản/session; staff phản hồi, manager ẩn/khôi phục. Báo cáo revenue cộng `grand_total` của order `delivered`; payment status được thể hiện riêng. Các chức năng này được mô tả theo README, controller/service và regression report; không hàm ý có dashboard phân tích nâng cao.

### 4.7 Realtime

Customer order detail dùng `EventSource` tới endpoint PHP SSE. Server kiểm tra quyền sở hữu, theo dõi đúng order bằng CouchDB `_changes` longpoll tối đa 8 giây, gửi payload đã lọc rồi đóng response; trình duyệt tự reconnect sau 5 giây. Browser không kết nối trực tiếp CouchDB. Đây không phải WebSocket; kiểm thử offline/online reconnect thực chưa được thực hiện.

## CHƯƠNG 5 – GIAO DIỆN HỆ THỐNG

Các vị trí dưới đây là chỗ để người làm báo cáo chèn ảnh do mình chụp; chưa khẳng định ảnh đã được lưu.

**Hình 5.1: Trang catalog sản phẩm**  
`[CHÈN HÌNH 5.1 – Trang chủ/catalog tại đây]`

**Hình 5.2: Chi tiết sản phẩm và lựa chọn size**  
`[CHÈN HÌNH 5.2 – Chi tiết sản phẩm tại đây]`

**Hình 5.3: Giỏ hàng**  
`[CHÈN HÌNH 5.3 – Giỏ hàng tại đây]`

**Hình 5.4: Checkout**  
`[CHÈN HÌNH 5.4 – Checkout tổng hợp, không lộ dữ liệu nhận hàng thật]`

**Hình 5.5: Customer Order History**  
`[CHÈN HÌNH 5.5 – Lịch sử đơn tổng hợp tại đây]`

**Hình 5.6: Customer Order Detail và Order Timeline**  
`[CHÈN HÌNH 5.6 – Chi tiết đơn tổng hợp tại đây]`

**Hình 5.7: Delivery Timeline có tracking**  
`[CHÈN HÌNH 5.7 – Mã tracking demo và timeline tại đây]`

**Hình 5.8: Admin Order List và filter**  
`[CHÈN HÌNH 5.8 – Admin Orders tại đây]`

**Hình 5.9: Admin Order Detail và form cập nhật**  
`[CHÈN HÌNH 5.9 – Chi tiết đơn quản trị tại đây]`

**Hình 5.10: Delivery failed/retry/delivered**  
`[CHÈN HÌNH 5.10 – Lịch sử giao hàng có event thất bại và retry tại đây]`

Order snapshot hiện không có ảnh sản phẩm lịch sử. UI dùng placeholder nêu rõ không lưu ảnh; không thay đổi schema trong phạm vi này.

## CHƯƠNG 6 – KIỂM THỬ

**Bảng 6.1. Kết quả kiểm thử theo bằng chứng Phase 01–07**

| Hạng mục | Kết quả được ghi nhận | Cơ sở |
|---|---|---|
| PHP syntax | PASS ở các lượt kiểm tra được báo cáo | Phase 01, 02, 05, 06; Phase 03B sau sửa UI. |
| Composer metadata | PASS với cảnh báo thiếu trường `license` | Phase 01/02/05/06. |
| Authentication/JWT | PASS | `auth:jwt-smoke`, HTTP regression. |
| Catalog/admin product | PASS | `catalog:admin-smoke`, HTTP integration. |
| Cart/checkout/idempotency | PASS | `checkout:smoke`, `checkout:http-smoke`. |
| Order/delivery/payment | PASS | Service/HTTP smoke, demo verify và browser flows Phase 03/06. |
| Ownership/CSRF/revision conflict | PASS trong test tương ứng | Smoke tests có kiểm tra 404/409 và quyền; không phải pentest. |
| Customer/admin browser flow | PASS trong Phase 03B và Phase 06 | Browser thật đã kiểm tra desktop, mobile, form và trạng thái. |
| Responsive 390px | PASS theo Phase 03B/06 | Không tràn ngang trang; bảng item có vùng cuộn riêng. |
| SSE cập nhật order | PASS trong kịch bản browser đã ghi | Offline/online reconnect chưa mô phỏng. |
| Seed verify isolated | PASS | 347 fixture docs được import/verify trên DB tạm rồi xóa. |
| Deployment checks | UNVERIFIED / không triển khai | Phase 08 BLOCKED; không được tính là PASS. |

Phase 06 ghi nhận 89 file PHP lint PASS và các smoke suite chính PASS. Không chạy lại các test trong Phase 09; đây là tổng hợp kết quả lịch sử, không phải kết quả test mới. `seed:verify` trên database ứng dụng từng từ chối vì có document ngoài fixture; Phase 05/06 có `seed:verify-isolated` để kiểm tra trên DB tạm. Điều này không được diễn đạt như lỗi runtime.

## CHƯƠNG 7 – TRIỂN KHAI

Ứng dụng được cấu hình chạy local bằng Docker Compose: PHP 8.3/Apache, CouchDB 3.5.0, named volumes cho dữ liệu CouchDB, upload và session. README mô tả Fauxton tại loopback và health/readiness endpoints. Đây là cấu hình local.

Phase 08 đánh giá Render nhưng **BLOCKED / NOT DEPLOYED**: thư mục project chưa có Git remote để build; Docker Engine không truy cập được trong lượt audit; PHP/Composer/Render CLI và local health checks không khả dụng; chưa chọn chi phí Render disk; cookie secure qua proxy HTTPS và cấu hình hiển thị/log lỗi production cần xác nhận. Không có public URL, dịch vụ, persistent disk hay kết quả online. Hướng dẫn Render là runbook tương lai, không phải bằng chứng deployment.

## CHƯƠNG 8 – ĐÁNH GIÁ

### 8.1 Kết quả đạt được

Project đã có các module storefront và admin chính, order/delivery workflow, payment state riêng, JSON API, SSE cho order detail và cấu trúc CouchDB schema v2. Các luồng nghiệp vụ quan trọng có smoke test; giao diện order đã được kiểm tra trên browser ở desktop/390px theo Phase 03B và Phase 06.

### 8.2 Ưu điểm

- Tách trách nhiệm theo Router/Controller/Service/Repository/CouchDbClient.
- Lưu snapshot item và phí shipping trong order, giúp giữ thông tin giao dịch tại thời điểm tạo.
- Kiểm tra ownership, role, CSRF và revision conflict ở các luồng được test.
- Có dữ liệu demo tổng hợp và lệnh xác minh/cleanup riêng.

### 8.3 Hạn chế

- Chưa có cổng thanh toán, refund, email quên mật khẩu tự động hay tích hợp đơn vị vận chuyển.
- Order không lưu ảnh snapshot; UI dùng placeholder.
- Filter catalog chạy sau Mango query và được README giới hạn cho quy mô fixture demo; cần thiết kế lại nếu dữ liệu tăng.
- SSE giữ worker Apache prefork trong một lượt longpoll hữu hạn; tải đồng thời lớn chưa được đánh giá.
- Chưa deploy online; production security, persistence, restart và proxy behavior chưa được xác minh.

### 8.4 Hướng phát triển

Có thể nghiên cứu tích hợp cổng thanh toán và hoàn tiền, thông báo email, lưu ảnh bền vững, pagination/search phù hợp dataset lớn, tích hợp hãng vận chuyển, đo tải SSE hoặc xây dựng client mobile. Đây là hướng phát triển, không phải chức năng hiện tại.

## KẾT LUẬN

Đồ án xây dựng một ứng dụng bán hàng thời trang với các bước chính từ duyệt catalog đến checkout, theo dõi đơn hàng và giao hàng. Backend PHP được tổ chức theo các lớp rõ ràng, còn CouchDB lưu dữ liệu dưới dạng JSON document và được gọi qua REST/cURL. Các báo cáo kiểm thử ghi nhận order, delivery, payment, quyền truy cập và giao diện responsive hoạt động trong môi trường local/demo đã kiểm tra.

Kết quả cần được trình bày đúng giới hạn: thanh toán trực tuyến tự động và deployment công khai chưa có; Phase 08 bị chặn trước khi tạo Render services. Một số điểm như ảnh lịch sử trong order, khả năng mở rộng catalog/SSE và hoàn thiện môi trường production được để cho giai đoạn sau. Trước khi nộp, cần bổ sung thông tin bìa, ảnh chụp thật và xác nhận lại các yêu cầu định dạng của giảng viên.

# CHECKLIST ẢNH CHO BÁO CÁO

Trạng thái hiện tại: các ảnh dưới đây **chưa được tạo/lưu**. Báo cáo Phase 03B/06/07 có ghi nhận browser verification, nhưng không có bộ ảnh đính kèm trong repository. Chỉ chụp dữ liệu tổng hợp Phase 06 hoặc mã synthetic. Không chụp password, trang `.env`, JWT secret, CouchDB credentials, thông tin khách thật, cookie/session hoặc dữ liệu từ database nghiệp vụ.

## PHP REPORT SCREENSHOTS

| Mã hình | Tên hình | Route/màn hình | Role | Dữ liệu cần có | Mục đích |
|---|---|---|---|---|---|
| PHP-01 | Trang catalog | `/` | Guest | Catalog demo, không cần login | Minh họa trang bán hàng và danh sách sản phẩm. |
| PHP-02 | Chi tiết sản phẩm | `/products/{id}` | Guest | Product demo có variants/images | Minh họa thông tin và lựa chọn size. |
| PHP-03 | Giỏ hàng | `/cart` | Guest hoặc customer demo | Một dòng cart tổng hợp, không lộ thông tin cá nhân | Minh họa quantity/size và tổng hợp giỏ. |
| PHP-04 | Checkout | `/checkout` | Customer/guest demo | COD, shipping method demo; che tên/địa chỉ/số điện thoại/email | Minh họa bước rà soát đơn trước khi đặt. |
| PHP-05 | Customer Order History | `/account/orders` | Customer demo | Có đơn thuộc tài khoản demo ở vài trạng thái | Minh họa lịch sử, payment/delivery badge. |
| PHP-06 | Order Detail có tracking | `/account/orders/{id}` | Customer demo, chủ đơn | `shipping` hoặc `in_transit`, mã tracking tổng hợp | Minh họa item snapshot, tổng tiền và trạng thái. |
| PHP-07 | Order Timeline | Trong Order Detail | Customer demo | Order có `status_history[]` | Minh họa lịch sử trạng thái thật. |
| PHP-08 | Delivery Timeline | Trong Order Detail | Customer demo, chủ đơn | Một đơn có `failed_delivery` rồi retry hoặc delivered | Minh họa event giao hàng và ghi chú tổng hợp. |
| PHP-09 | Legacy order không tracking | `/account/orders/{id}` | Customer demo, chủ đơn | Đơn delivered cũ không có `delivery_tracking` | Minh họa tương thích ngược và thông báo không có tracking. |
| PHP-10 | Admin Order List và filter | `/admin/orders` | Staff hoặc manager demo | Nhiều trạng thái để dùng filter | Minh họa filter, pagination và badge order/payment/delivery. |
| PHP-11 | Admin Order Detail | `/admin/orders/{id}` | Manager demo | Đơn shipping có item, payment, delivery | Minh họa chi tiết và các form được phép. |
| PHP-12 | Chuyển order status | Admin Order Detail | Manager demo | Đơn `pending`/`confirmed` phù hợp transition | Minh họa form chỉ hiển thị bước hợp lệ. |
| PHP-13 | Delivery failure và retry | Admin Order Detail | Manager demo | Một order delivery failed và event retry | Minh họa trạng thái lỗi, ghi chú và chuyển tiếp. |
| PHP-14 | Delivered và success message | Admin Order Detail | Manager demo | Delivery cuối cùng được cập nhật trong demo | Minh họa kết quả delivered và thông báo thành công. |
| PHP-15 | Mobile Order Detail 390px | `/account/orders/{id}` | Customer demo | Đơn có item, payment, cả hai timeline | Minh họa responsive và thông tin mobile đọc được. |
| PHP-16 | Mobile Admin Order Detail 390px | `/admin/orders/{id}` | Manager demo | Order detail có delivery form | Minh họa layout/form responsive; tránh ghi lại PII. |
| PHP-17 | Customer cập nhật realtime | Customer Order Detail và cửa sổ admin riêng | Customer + manager demo, profile riêng nếu có | Đơn tổng hợp đang cập nhật | Minh họa SSE chỉ khi có thể chụp cả trạng thái trước/sau và không lộ session. |

Route customer API, token và response có thể dùng làm bằng chứng kỹ thuật riêng, nhưng không chụp access token. Với ảnh các trang order, ẩn/crop địa chỉ, email, phone và tên thật; ưu tiên demo data dùng `example.test`.

## NOSQL REPORT SCREENSHOTS

| Mã hình | Tên hình | Màn hình/đường dẫn | Role/công cụ | Dữ liệu cần có | Mục đích |
|---|---|---|---|---|---|
| NOSQL-01 | Fauxton database overview | Fauxton → Databases | Fauxton local đã đăng nhập; crop vùng auth | `shopquan_ao_phase06_demo_test` | Giới thiệu database demo. |
| NOSQL-02 | All Documents/order document | Fauxton → demo DB → Documents | Fauxton local | `order:P06-0020` synthetic | Minh họa JSON document và `_id`. |
| NOSQL-03 | Revision của order | Trong order document | Fauxton local | `_rev` hiện có của order demo | Minh họa revision do CouchDB quản lý. |
| NOSQL-04 | Item/shipping/payment snapshot | Trong order document | Fauxton local | `items[]`, shipping/payment/totals, dữ liệu tổng hợp | Minh họa cấu trúc aggregate. |
| NOSQL-05 | `status_history[]` | Trong order document | Fauxton local | Order có nhiều event tổng hợp | Minh họa lịch sử order. |
| NOSQL-06 | `delivery_tracking.history[]` | Trong order document | Fauxton local | Tracking synthetic `P06-*` | Minh họa history delivery. |
| NOSQL-07 | Mango Query shipping | Fauxton → Mango Query | Fauxton local | Query 02 | Minh họa selector và kết quả shipping. |
| NOSQL-08 | Mango Query failed delivery | Fauxton → Mango Query | Fauxton local | Query 11 | Minh họa lỗi giao và thống kê scan/cảnh báo index nếu hiển thị. |
| NOSQL-09 | Mango Query tracking code | Fauxton → Mango Query | Fauxton local | Query 12 synthetic | Minh họa lookup dùng index tracking. |
| NOSQL-10 | Manage Indexes | Fauxton → Indexes | Fauxton local | Index `admin_orders`, `customer_orders`, `delivery_tracking_code` | Minh họa Mango indexes. |
| NOSQL-11 | `_explain` output | Fauxton/HTTP tool theo quy trình dự án | Chỉ dữ liệu demo | Pending, customer hoặc tracking query | Minh họa index được chọn; không gọi số liệu là benchmark. |
| NOSQL-12 | Design document/index definition | Fauxton → Design Documents | Fauxton local, view only | `_design/catalog_indexes` | Minh họa định nghĩa query index. |
| NOSQL-13 | Validator definition | Fauxton → Design Documents | Fauxton local, view only | `_design/domain_validation` | Minh họa `validate_doc_update`; không nhấn Save. |
| NOSQL-14 | Validator reject | Terminal/test output của `phase07_nosql_fixture_demo.php` | Local app/container | Fixture isolated, invalid status, HTTP 403 | Bằng chứng validator từ chối dữ liệu. |
| NOSQL-15 | Revision conflict | Terminal/test output của fixture | Local app/container | Fixture isolated, stale `_rev`, HTTP 409 | Bằng chứng optimistic concurrency. |

### Trước khi chụp

- Xác nhận app/Fauxton đang trỏ database demo tổng hợp, không phải database khác.
- Dùng đúng fixture sau khi verify; không tạo thêm dữ liệu tùy tiện chỉ nhằm có ảnh.
- Không sửa/lưu document khi mục đích chỉ là quan sát. Các thử nghiệm ghi 409/403 phải dùng script fixture cô lập có cleanup.
- Kiểm tra crop ảnh để loại thông tin nhạy cảm; không đưa login form đang chứa credential hoặc secret vào ảnh.
- Ghi chú caption và số hình khớp với bản báo cáo cuối sau khi bố cục Word được chốt.

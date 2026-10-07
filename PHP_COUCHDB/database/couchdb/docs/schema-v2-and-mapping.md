# Hợp đồng dữ liệu CouchDB v2 và mapping SQL

Phiên bản này dành cho fixture chuyển đổi từ bộ SQL mẫu đang trong repository. App PHP và mọi bộ import tiếp theo phải cùng dùng một contract. CouchDB lưu các aggregate JSON; document ID, field name, enum và quy tắc quy đổi trong tài liệu này là quy ước chung. `_rev` do CouchDB quản lý, không tạo thủ công.

Seed được sinh tại `../seeds/retail_order_delivery.json` bằng `../tools/build_fixture_seed.py`. Bộ sinh fixture lấy product, variant, ảnh và review từ `WEBTHOITRANG/3_DuLieu.sql`; giữ khách hàng, nhân viên, giỏ, đơn, voucher và phương thức vận chuyển từ `bulk_docs.json` sau khi đổi metadata. Đây là fixture cho SQL mẫu, không phải bộ trích xuất trực tiếp từ SQL Server đang chạy.

## Quy tắc chung

- Mọi document nghiệp vụ mang `schema_version: 2`, `type`, `_id` ổn định và `meta` cho thông tin xuất xứ. Field cấp cao bắt đầu bằng `_` chỉ dành cho CouchDB.
- Giữ ID legacy trong `legacy_id` và giữ nguyên ID biến thể. Repository tạo/đọc prefix qua một hàm dùng chung; trong seed, `_id` sản phẩm là `product:A01`, nhưng `variant_id` là `CTA01SzS`.
- VND là số JSON nguyên, tính theo đồng; file SQL mẫu hiện lưu các mức tiền là số nguyên đồng. Nếu export thực tế có phần thập phân, dừng import và quyết định cách biểu diễn/rounding trước khi chuyển.
- Boolean SQL bit được đổi sang JSON `true`/`false`; `NULL` được giữ là JSON `null` trừ nơi contract yêu cầu field phải có giá trị.
- Ảnh giữ đường dẫn tương đối `assets/img/...`; khi chuyển sang app mới cần chép ảnh dưới `public/assets/img/` để URL khớp.
- `active: false` là xóa mềm nghiệp vụ. Không dùng thao tác xóa CouchDB để thay thế trạng thái đơn hoặc lịch sử.
- Timestamp phải có múi giờ. Ngày lịch sử thiếu trong SQL hoặc `NULL` vẫn để `null`, không tạo thời điểm giả.
- Validator kiểm tra hình dạng và quy tắc cục bộ của một document. Service/migration chịu trách nhiệm kiểm tra link liên document, trạng thái chuyển tiếp, tồn kho, tổng tiền và quyền người dùng.

## Document v2

| Type và ID | Field chính | Nội dung/nguồn |
| --- | --- | --- |
| `customer:<MaKH>` | `legacy_id`, `auth`, `profile`, `active`, `meta` | KHACH_HANG; auth gồm username, password_hash, requires_password_reset. Demo chưa provision có hash null và reset bắt buộc. Không xuất MatKhau SQL. |
| `staff:<MaNV>` | `legacy_id`, `auth`, `profile`, `role`, `active`, `meta` | NHAN_VIEN; role chỉ `manager` hoặc `staff`; credential ứng dụng không dùng mật khẩu CouchDB. |
| `product:<MaSP>` | `legacy_id`, `name`, `description`, `category`, `variants[]`, `images[]`, `active`, `meta` | SAN_PHAM + DANH_MUC + CHI_TIET_SP + KICH_THUOC + HINH_ANH_SP. Mỗi size/giá/tồn kho là variant lồng trong product. Ảnh giữ legacy_id, path, is_primary, active. |
| `cart:<MaKH>` | `customer_id`, `items[]`, `updated_at`, `meta` | GIO_HANG gộp theo khách. Mỗi item giữ product/variant ID, size, quantity, price snapshot và trạng thái chọn mua `selected`. Giỏ khách vãng lai nằm trong session; khi login các dòng được gộp theo variant và giới hạn theo tồn kho hiện tại. |
| `order:<MaDonHang>` | `legacy_id`, `customer`, `guest_order`, `receiver`, `items[]`, `shipping`, optional `delivery_tracking`, `assigned_staff_id`, `discount`, `payment`, `totals`, `status`, `status_history[]`, `ordered_at`, `meta` | DON_HANG + CHI_TIET_DON_HANG và snapshot khách/sản phẩm/phương thức vận chuyển. `shipping` giữ method code/name/fee; `delivery_tracking` giữ mã vận đơn, ngày dự kiến, trạng thái giao và history. `assigned_staff_id` vẫn ở cấp order theo schema hiện hành. Order mới mở rộng `meta.checkout` theo thiết kế recovery trước khi bật checkout. |
| `voucher:<MaCode>` | `code`, `value`, `discount_type`, `remaining_quantity`, `active`, `meta` | MA_GIAM_GIA; type `percent`, `cash`, `shipping`. |
| `shipping_method:<MaPTVC>` | `code`, `name`, `fee`, `currency`, `active`, `meta` | PHUONG_THUC_VAN_CHUYEN; giá trong danh mục là giá hiện tại, còn order lưu snapshot lúc mua. |
| `review:<MaDanhGia>` | `legacy_id`, `product_id`, `customer_id`, `reviewer_name`, `rating`, `content`, `active`, optional `admin_response`, `meta` | DANH_GIA; rating là số nguyên 1–5. Giữ đánh giá guest với customer_id null. Phản hồi cửa hàng lưu content, staff ID/name, updated_at; lần sửa trước được giữ trong `meta.admin_response_history`. |
| `_design/domain_validation` | `language`, `validate_doc_update` | Chứa validator cho các type nghiệp vụ và schema_version 2. Dữ liệu chuẩn trong `../design-docs/domain_validation.json`. |

Review được lưu thành document riêng để product không phình vô hạn và có thể lập index theo product. Review mới có thể do guest/customer gửi; CSRF, rating 1–5 và giới hạn 2000 ký tự được kiểm tra ở PHP. Document ID xác định từ customer/session cùng product nên cùng tài khoản hoặc guest session chỉ gửi một review cho mỗi product; nội dung và phản hồi cửa hàng phải được escape khi render. Staff có thể tạo/cập nhật phản hồi qua endpoint có CSRF; manager giữ quyền ẩn/khôi phục. Checkout lưu một order journal trước khi ghi tồn kho; `meta.checkout.phase` đi qua `stock_reserving`, `voucher_reserving`, `finalizing`, `completed` hoặc `compensating`/`failed`. `operation_id` bắt nguồn từ token và scope customer/session; fingerprint ngăn dùng lại token với thông tin giao hàng khác. `stock_plan` là intent bền để retry và phục hồi.

Order status và delivery status là hai enum độc lập. Order giữ workflow hiện có `pending → confirmed → packing → shipping → delivered` (cancellation theo rule hiện hành). Chỉ khi order vào `shipping` mới khởi tạo `delivery_tracking` với trạng thái `created`; các event sau là `picked_up`, `in_transit`, `out_for_delivery`, `delivered` hoặc `failed_delivery` (có thể thử giao lại từ trạng thái lỗi). Mỗi event lưu `status`, thời gian UTC do server tạo, `note`, `updated_by`; lịch sử chỉ được nối thêm. Khi delivery có tracking hoàn tất thì order và delivery cùng chuyển `delivered`. Staff/manager cập nhật qua service có kiểm tra `_rev`; khách chỉ đọc detail thuộc ownership hiện có. `assigned_staff_id` được tái sử dụng từ field cấp order, không nhân đôi trong tracking. Order cũ không có `delivery_tracking` vẫn hợp lệ và không được backfill bằng lịch sử giả. Các field legacy `shipping.tracking_code` và `shipping.estimated_delivery_date` được chuyển vào tracking khi staff khởi tạo tracking cho một order cũ đang giao.

## Mapping từng bảng SQL

| Bảng/logic SQL | Đích | Cách bảo toàn ràng buộc |
| --- | --- | --- |
| KHACH_HANG | `customer` | MaKH nằm trong _id và legacy_id; TenDangNhap vào auth.username; hồ sơ vào profile; TrangThai vào active. Email/username chuẩn hóa và uniqueness phải xử lý bằng document identity/Service. |
| NHAN_VIEN | `staff` | MaNV là ID; ChucVu map rõ sang role; TrangThai thành active. Không dùng tên chức vụ tùy ý làm quyền. |
| DANH_MUC | Nhúng `category` trong từng product | Danh sách category cho form admin phải có nguồn reference/config rõ ràng; không suy ra từ product đang active. |
| KICH_THUOC | Nhúng `variants[].size` | MaKichThuoc legacy dùng để join khi dựng variant; không tạo document rời cho mọi size sản phẩm. |
| SAN_PHAM | `product` | MaSP thành legacy_id; MaDanhMuc thành category code/name; giữ nguyên mô tả và active. |
| CHI_TIET_SP | Nhúng `product.variants[]` | MaCTSP thành variant_id; giá, stock, active chuyển theo variant. Unique `(MaSP, MaKichThuoc)` kiểm tra khi dựng/import aggregate. |
| HINH_ANH_SP | Nhúng `product.images[]` | Giữ ID ảnh/path/ảnh chính/active; xác minh file ảnh có tồn tại trước khi copy. |
| MA_GIAM_GIA | `voucher` | Giữ code/value/type/quantity/active. Việc giữ chỗ, trừ và hoàn số lượng khi checkout thuộc Service có cơ chế chống xử lý hai lần. |
| PHUONG_THUC_VAN_CHUYEN | `shipping_method` | Giữ code/name/fee/active; order snapshot thông tin và phí khi tạo. |
| GIO_HANG | `cart` theo customer | PK ghép MaKH/MaCTSP trở thành một item duy nhất theo variant; duplicate cần gộp quantity hoặc báo sai dữ liệu. Guest cart dùng session. |
| DON_HANG | `order` | Giữ MaDonHang, receiver, dữ liệu nhận, shipping/payment, current status; lưu status_history mới mà không bịa timestamp cũ. MaKH null giữ guest_order true. |
| CHI_TIET_DON_HANG | Nhúng `order.items[]` | MaCTSP map variant_id; DonGia thành unit_price snapshot; quantity/line_total và product_name/size snapshot. Kiểm tra đủ variant/product. |
| DANH_GIA | `review` | Giữ MaDanhGia, product/customer, reviewer, rating, content và active; customer có thể null. |
| sysdiagrams | Không di chuyển | Metadata diagram nội bộ SQL Server, không thuộc dữ liệu nghiệp vụ website. |
| Trigger cộng số lượng/tổng tiền | `CheckoutService`/`OrderService` | Tính trong một implementation duy nhất; validator kiểm tra totals không âm nhưng không thay transaction nhiều document. |
| Trigger lấy phí ship | `PricingService` | Đọc shipping_method hiện tại, snapshot phí vào order. Không lấy phí bằng trigger sau khi tạo order. |
| FK, CHECK, UNIQUE, cột computed | Validator từng document + Service + migration report | FK liên document không được validator cục bộ bảo đảm. Cột computed được lưu snapshot/tính bởi Service với một công thức chuẩn. |

## Enum và quy tắc nghiệp vụ

Trạng thái cũ map như sau: `Chờ xác nhận` → `pending`; `Đang giao` → `shipping`; `Hoàn tất` → `delivered`; `Hủy` → `cancelled`. Luồng quản trị cho phép `pending → confirmed → packing → shipping → delivered`; `pending`, `confirmed` hoặc `packing` có thể chuyển sang `cancelled`. Trạng thái giao hàng không cho phép lùi hoặc hủy sau khi bắt đầu giao. Chỉ staff/manager đã đăng nhập được cập nhật; server kiểm tra transition và không nhận trạng thái tùy ý từ trình duyệt.

Với đơn mới, `subtotal = sum(quantity × unit_price)`, phần trăm làm tròn VND đến đồng gần nhất, giảm tiền mặt không vượt subtotal cộng phí ship; mã ship giảm đúng phí ship. `grand_total = max(0, subtotal + shipping_fee - discount_amount)`. Phí ship và voucher được snapshot vào order; hạn mức voucher giảm khi reserve thành công. Stock cùng marker reservation được ghi trong một product PUT có `_rev`; compensation chỉ cộng lại reservation còn `reserved` rồi đánh dấu `released`.

Đơn checkout mới chỉ bật COD. Payment khởi tạo `unpaid`/`paid:false`; trạng thái giao hàng không đồng nghĩa đã thu tiền. Khách hoặc phiên guest xác nhận đơn COD đã nhận sẽ ghi paid; đơn chuyển khoản/thẻ giữ trạng thái tới khi staff đối chiếu thủ công. `payment_history[]` ghi status, timestamp, source, người thực hiện, phương thức và việc xác minh thủ công; thao tác chỉ một chiều, chưa có refund. Chưa có kết nối tự động tới ngân hàng/cổng thanh toán. Hủy đơn chưa thanh toán chạy qua `meta.order_transition`, hoàn stock/voucher theo marker nguyên tử cùng `_rev` và có thể resume sau crash. Đơn đã thanh toán phải chờ quy trình refund; không tự đánh dấu hủy. Marker idempotency được giữ trên product/voucher/cart để bảo vệ cả retry đồng thời; cần tác vụ compaction an toàn cho order terminal trước khi dùng với lượng đơn lớn.

Validator CouchDB áp dụng thêm contract cho `payment_history`: mỗi event phải ghi `status: "paid"`, thời điểm và nguồn; nếu event đã tồn tại thì chỉ được nối thêm event mới, không được xóa hoặc sửa event cũ. Một order đã có payment status `paid` cũng không thể chuyển ngược về `unpaid`. Đây là ràng buộc toàn vẹn dữ liệu ở cấp document; quyền xác nhận thanh toán và CSRF vẫn do ứng dụng PHP kiểm tra. Khi cập nhật validator trên môi trường local test, dùng `docker compose exec -T app composer db:validator:update`; script chỉ chấp nhận tên database kết thúc bằng `_test` và không sửa business documents.

`GetRevenueStats` cũ cộng TongTien; trang Revenue cộng tiền sau phí ship/giảm giá. Định nghĩa thống nhất đề xuất là tổng grand_total của đơn delivered, dùng chung ở JSON API và UI. Payment và delivery là hai trạng thái riêng; COD chỉ đánh dấu paid khi quy tắc nhận hàng được thực hiện.

## Identity, index và bảo mật

Đăng ký khách dùng email lowercase, document ID xác định từ SHA-256 của email và xử lý conflict 409, tránh tạo hai customer cho cùng email. Lookup username dùng Mango index `account_usernames`; không dựa riêng vào thao tác đọc trước rồi insert để chống trùng đồng thời. Tài khoản legacy staff/customer có thể chưa có hash; đăng nhập phải từ chối cho đến khi cấp password hash mới, không thử mật khẩu plaintext SQL.

Index hiện có gồm product theo `type/active`, username theo `type/auth.username`, đơn của khách theo `type/customer.customer_id/ordered_at`, admin theo `type/status/ordered_at` hoặc `type/ordered_at`, tracking code theo `type/delivery_tracking.tracking_code` để kiểm tra uniqueness khi tạo mã, và review theo `type/active`. Không thêm index status delivery vì chưa có truy vấn lọc theo status. Lịch sử khách và màn hình admin dùng cursor pagination Mango; cursor không thay thế quyền kiểm tra owner trong session. Không coi validator là phân quyền khách hàng.

Tài khoản chuyển từ SQL có `password_hash: null` và `requires_password_reset: true`; đăng nhập sẽ không hoạt động cho đến khi provision hash mới. `scripts/provision_staff_password.php` chỉ sửa staff trong database `_test`, nhận email hoặc username legacy (ví dụ `admin`), đọc password hai lần từ TTY đã tắt echo, không nhận password qua argv/env và không ghi plaintext vào log. Không hardcode mật khẩu vào seed, SQL converter, git hoặc log. Endpoint application vẫn phải kiểm tra quyền; CouchDB credentials trong container không đại diện người dùng đăng nhập. Cart member cho phép field mở rộng `selected` để giữ lựa chọn giữa các lần truy cập.

## Cập nhật schema an toàn

Seed fixture và snapshot SQL live là hai artifact riêng. `scripts/import_seed.php` chỉ quản lý fixture mẫu; snapshot live sau khi preflight và transform dùng `scripts/import_sql_snapshot.php` trong một database `_test` riêng. Cả hai importer cài validator trước business documents, kiểm tra kết quả từng hàng `_bulk_docs`, xác minh lại document và từ chối khác biệt thay vì ghi đè.

Với database v1 đã tồn tại, viết migration riêng: đọc document và `_rev` hiện hành, chuyển `_meta` → `meta`, thêm review/full products và schema_version 2, ghi lại đúng revision; xử lý conflict theo từng document rồi verify. Sao lưu trước. Không gửi lại toàn seed như thể thao tác bulk là transaction nguyên tử, và không ghi đè document có thay đổi thật ngoài fixture.

Với snapshot live đã preflight, `tools/transform_sql_snapshot.py` tạo artifact `format_version: 2` theo cùng contract, kèm design validator. Transform từ chối snapshot có lỗi, reconciliation warning chưa giải quyết, payment method lạ hoặc mapping không đầy đủ. Order giữ totals lịch sử và trạng thái hiện tại; timestamp transition/payment không có trong SQL thì không được giả lập. Credential cũ không được xuất; account buộc provision hash riêng. Sau khi review counts/diff, dùng `scripts/import_sql_snapshot.php` vào database `_test` riêng; importer kiểm tra khác biệt theo document, hỗ trợ resume document còn thiếu và không ghi đè hoặc xóa dữ liệu.

## Nguồn và giới hạn

- Bảng: `WEBTHOITRANG/1_TaoBang.sql`; dữ liệu mẫu: `WEBTHOITRANG/3_DuLieu.sql`; logic trigger: `WEBTHOITRANG/2_Trigger.sql`.
- Schema v1 tham khảo: `bulk_docs.json`; fixture v2: `../seeds/retail_order_delivery.json`.
- Danh sách legacy password chỉ có trong SQL nguồn. Builder không chuyển các giá trị này vào seed.
- Nếu cần dữ liệu đang chạy tại SQL Server, thay fixture source bằng export nhất quán từ SQL Server và đối soát từng bảng; không xem seed mẫu là backup hay export của database thật.

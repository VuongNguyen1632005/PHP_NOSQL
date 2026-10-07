# WEBTHOITRANG PHP + CouchDB

Ứng dụng PHP 8.3 chạy bằng Apache/Docker, dùng CouchDB cho catalog và dữ liệu tài khoản/giỏ thành viên. Module catalog có danh sách, trang chi tiết, trang giới thiệu, ảnh, biến thể size, đánh giá chỉ đọc, tìm kiếm, lọc, sắp xếp và phân trang. Giỏ khách lưu trong PHP session; giỏ thành viên được lưu thành một cart document và nhận các dòng guest khi khách đăng nhập.

## Chạy local

Đặt cấu hình CouchDB trong `.env`, sau đó chạy stack từ thư mục này:

```powershell
docker compose up --build -d
docker compose exec -T app composer seed:import
docker compose exec -T app composer catalog:indexes
```

Mở [http://localhost:8081](http://localhost:8081). `/health` xác nhận tiến trình web đang phản hồi; `/health/ready` kiểm tra ứng dụng kết nối được tới database CouchDB đã cấu hình. Docker dùng readiness endpoint này cho healthcheck. Seed fixture được quản lý riêng bởi `composer seed:import` và chỉ cho phép database kết thúc bằng `_test`.

Quản lý CouchDB bằng Fauxton tại [http://127.0.0.1:5984/_utils/](http://127.0.0.1:5984/_utils/); đăng nhập bằng `COUCHDB_USER` và `COUCHDB_PASSWORD` trong `.env`. Cổng CouchDB chỉ bind vào loopback trên máy local. Hướng dẫn xem database, document, index, backup và restore nằm trong [database/couchdb/README.md](database/couchdb/README.md).

Smoke test CouchDB và luồng HTTP end-to-end dùng database ngẫu nhiên, tự xóa sau khi chạy:

```powershell
docker compose exec -T app composer checkout:smoke
docker compose exec -T app composer checkout:http-smoke
docker compose exec -T app composer catalog:admin-smoke
```

Xác minh seed trên database `_test` ngẫu nhiên, cô lập và tự xóa:

```powershell
docker compose exec -T app composer seed:verify-isolated
```

Lệnh `composer seed:verify` chỉ kiểm tra database hiện tại trong `COUCHDB_DATABASE`; nó không tạo seed và sẽ từ chối database có document ngoài bộ fixture. `composer seed:verify-isolated` tạo database dùng một lần, chạy import và verify, rồi xóa chính database đó trong cả trường hợp thành công hoặc thất bại.

Kiểm tra đường lỗi khi CouchDB không kết nối được mà không tác động database:

```powershell
docker compose exec -T app composer couchdb:failure-smoke
```

`COUCHDB_DATABASE` trong `.env.example` trùng với giá trị fallback trong Docker Compose (`retail_order_delivery_test`). Có thể đặt tên `_test` riêng trong `.env`; mọi importer/smoke command vẫn kiểm tra hậu tố test trước khi chạy.

Kiểm tra query Mango mẫu và index mà CouchDB chọn bằng `_explain` trên database `_test` đang cấu hình:

```powershell
docker compose exec -T app composer couchdb:diagnostics
```

Backup/restore JSON thử nghiệm chỉ chạy trên database `_test`; lệnh restore yêu cầu database đích chưa tồn tại. Xem [hướng dẫn backup và phục hồi](database/couchdb/README.md). Ảnh upload thuộc volume riêng và cần backup riêng.

## Database demo Phase 6

Phase 6 dùng một database riêng, `shopquan_ao_phase06_demo_test`, không ghi đè `COUCHDB_DATABASE` hiện tại. Tạo mới, kiểm tra integrity và khôi phục bộ demo chuẩn bằng các lệnh sau từ thư mục project:

```powershell
docker compose exec -T app composer demo:seed
docker compose exec -T app composer demo:verify
docker compose exec -T app composer demo:reset
```

`demo:seed` chỉ tạo database đích nếu database chưa tồn tại. `demo:reset` chỉ xóa database có tên hậu tố `_phase06_demo_test` sau khi xác nhận mọi business document là demo-owned; script từ chối database hiện tại, document không nhận diện và design document lạ. Có thể chỉ định `PHASE06_DEMO_DATABASE` để dùng một tên riêng đúng hậu tố. Mật khẩu được tạo ngẫu nhiên và lưu cục bộ trong `var/phase06_demo_credentials.txt`; file đã được ignore bởi Git. Đặt `PHASE06_DEMO_PASSWORD` cho một lượt seed nếu cần dùng credential do người vận hành chọn.

Bộ dữ liệu gồm 6 khách hàng tổng hợp, 2 tài khoản staff/manager, 18 sản phẩm, 1 voucher, 2 phương thức giao hàng và 27 đơn. Không dùng thông tin khách hàng thật. Xem [báo cáo Phase 6](docs/PHASE_06_FINAL_TEST_DEMO_DATA_REPORT.md) để biết status distribution, kịch bản tracking và kết quả kiểm thử.

Để chuyển snapshot SQL Server, xem [pipeline xuất, preflight, transform và import](database/couchdb/README.md). Importer yêu cầu database CouchDB `_test` riêng, cài validator trước business documents, xác minh từng document và hỗ trợ resume an toàn; không dùng seed fixture làm snapshot database live.

## Giới hạn phạm vi hiện tại

- Đăng ký khách hàng với password hash, đăng nhập/đăng xuất qua session và CSRF; nhân viên chỉ đăng nhập được khi đã có hash hợp lệ. Có thể cấp/reset mật khẩu staff bằng username (email hoặc username legacy như `admin`) trong database `_test` qua `docker compose exec -it app composer account:provision-staff -- <username>`; lệnh yêu cầu TTY, không echo mật khẩu và không ghi plaintext vào log.
- Giỏ guest hỗ trợ thêm, chọn dòng, cập nhật số lượng, đổi size và xóa. Giỏ thành viên lưu trong CouchDB, đồng bộ số lượng theo tồn kho khi merge guest cart.
- Checkout hỗ trợ COD, phương thức ship đang bật trong CouchDB và voucher percent/cash/free-ship. Order journal dùng token idempotency; stock/voucher có marker để retry và bù trừ qua `_rev`. Đơn COD lưu `unpaid` đến khi xác nhận đã thu tiền.
- Phục hồi order thành viên: `docker compose exec -T app php scripts/recover_checkout.php order:checkout:<operation-hash>`. Guest checkout đang dở cần gửi lại biểu mẫu từ trình duyệt/session ban đầu. Marker giữ tới khi đơn terminal đủ 90 ngày; `composer checkout:compact-markers` mặc định chỉ dry-run. Apply dùng `docker compose exec -it app composer checkout:compact-markers -- --apply --older-than-days=90`, yêu cầu xác nhận gõ lại tên database và hiện chỉ cho database `_test`.
- Khách đăng nhập xem danh sách phân trang 25 đơn tại `/account/orders` và chi tiết tại `/account/orders/{id}`. Mango bookmark dùng index theo customer và ngày; truy vấn luôn gắn với customer ID trong session, trang chi tiết trả 404 nếu đơn không thuộc tài khoản đó. Khi đơn đang giao, chủ đơn có thể xác nhận đã nhận qua POST có CSRF; hệ thống chuyển sang `delivered`, ghi audit customer và chỉ đánh dấu COD đã thu. Với chuyển khoản/thẻ chưa xác minh, trạng thái thanh toán được giữ nguyên; xác nhận lặp không tạo audit trùng.
- Khách vãng lai xem được các đơn đã đặt trong phiên trình duyệt hiện tại tại `/orders`; chi tiết chỉ mở được với ID nằm trong session sở hữu. Danh sách không tra cứu hoặc tiết lộ đơn khách khác; đăng xuất xóa liên kết lịch sử guest khỏi phiên. Với đơn đang giao, khách cũng xác nhận đã nhận bằng POST có CSRF; ID đơn phải thuộc phiên hiện tại, trạng thái và COD được cập nhật idempotent.
- Nhân viên role `staff` hoặc `manager` quản lý đơn theo 25 đơn mỗi trang tại `/admin/orders`; cursor giữ bộ lọc trạng thái. Luồng trạng thái là `pending → confirmed → packing → shipping → delivered`. Khi vào `shipping`, order được tạo `delivery_tracking` với mã `DEL-YYYYMMDD-<12 ký tự hex>`; delivery có history append-only và trạng thái riêng `created`, `picked_up`, `in_transit`, `out_for_delivery`, `delivered`, `failed_delivery`. Lỗi giao có thể tiếp tục lại từ `in_transit` hoặc `out_for_delivery`; khi delivery hoàn tất, order và tracking cùng thành `delivered`. Hủy chỉ được phép trước khi giao, đơn đã thanh toán bị chặn cho tới khi có quy trình hoàn tiền. Hủy phục hồi stock/voucher với marker idempotent và journal để có thể chạy lại sau gián đoạn.
- Trang `/admin/orders/{id}` hiển thị Delivery Information và cho staff/manager cập nhật delivery status bằng POST có CSRF; customer/guest chỉ xem timeline theo quyền Order hiện có. JSON API staff mở rộng `POST /api/admin/orders/{id}/delivery` với JWT và body `{ "status": "picked_up", "note": "..." }`. `shipping` chỉ giữ phương thức và phí giao; `assigned_staff_id` cũ vẫn ở cấp order, còn mã vận đơn/ngày dự kiến/trạng thái/history nằm trong `delivery_tracking`.
- Với đơn COD `delivered` nhưng chưa thanh toán, staff/manager có thể ghi nhận đã thu tiền. Đơn `bank_transfer` hoặc `card` chưa thanh toán có thể được staff/manager xác minh thủ công sau khi đối chiếu sao kê tại trang chi tiết quản trị. Các thao tác chỉ chuyển `unpaid → paid`, yêu cầu CSRF, ghi nhân viên/thời gian/phương thức/xác minh thủ công vào `payment_history` và gửi lặp không tạo audit trùng; không hỗ trợ sửa ngược hoặc hoàn tiền. Checkout mới vẫn chỉ bật COD; chưa có kết nối tự động tới ngân hàng/cổng thanh toán.
- Validator CouchDB kiểm tra cấu trúc `payment_history` và `delivery_tracking`, giữ các event cũ append-only, chặn delivery transition sai và đảm bảo order/tracking cùng `delivered`; đồng thời chặn đơn đã thanh toán quay về `unpaid`. Nếu cần cập nhật validator trên database test hiện có, chạy `docker compose exec -T app composer db:validator:update`; lệnh từ chối database không có hậu tố `_test` và không đụng tới business documents.
- Báo cáo doanh thu tại `/admin/revenue` và JSON API tại `/admin/revenue/stats` dùng cùng một phép tính: tổng `grand_total` của mọi đơn `delivered`; số liệu được đọc bằng Mango bookmark theo trang. Bảng hiển thị tối đa 100 đơn giao gần nhất và ghi rõ trạng thái thanh toán riêng.
- API catalog đọc công khai trả JSON: `GET /api/v1/products` hỗ trợ `search`, `category`, `min_price`, `max_price`, `sort` (`default`, `price_asc`, `price_desc`, `rating`), `page` và `page_size` (tối đa 50); `GET /api/v1/products/{id}` trả chi tiết, review đang hiển thị và tối đa 4 sản phẩm cùng danh mục. Customer JWT có thể gửi đánh giá bằng `POST /api/v1/products/{id}/reviews` với JSON `rating` (1–5) và `content` (tối đa 2000 ký tự); danh tính lấy từ token, mỗi tài khoản chỉ đánh giá một lần cho mỗi sản phẩm. Response chỉ đưa các trường sản phẩm/đánh giá công khai, không trả document CouchDB thô.
- API quản lý đơn cần JWT role `staff` hoặc `manager`: `GET /api/admin/orders` trả 25 đơn mỗi trang, hỗ trợ `cursor` và lọc `status` (`pending`, `confirmed`, `packing`, `shipping`, `delivered`, `cancelled`); `GET /api/admin/orders/{id}` trả chi tiết đơn qua allowlist. Có thể cập nhật trạng thái bằng `POST /api/admin/orders/{id}/status` với JSON `{"status":"confirmed"}`; quy tắc chuyển trạng thái vẫn do workflow kiểm tra. `POST /api/admin/orders/{id}/delivery` nhận delivery status và ghi history; khi trạng thái là `delivered`, API đồng thời kết thúc order. `POST /api/admin/orders/{id}/payment/cod-collected` chỉ ghi nhận COD khi đơn đã giao; `POST /api/admin/orders/{id}/payment/verify` dùng cho staff xác minh thủ công chuyển khoản/thẻ sau đối soát ngoài hệ thống. Các thao tác thanh toán ghi audit và lặp lại không nhân đôi lịch sử. API khách hàng cần JWT customer: `GET /api/v1/orders` trả 25 đơn mỗi trang và `next_cursor`; gửi cursor nhận được vào query string để tải trang sau. `GET /api/v1/orders/{id}` chỉ trả chi tiết nếu đơn thuộc đúng customer trong token; ID không tồn tại hoặc thuộc khách khác đều trả 404; order detail có delivery timeline đã lọc. `POST /api/v1/orders/{id}/confirm-received` xác nhận đơn thuộc khách đó đang giao; đơn thành delivered, delivery tracking được đồng bộ nếu có, COD được ghi nhận đã thu, còn chuyển khoản/thẻ vẫn giữ trạng thái chưa thanh toán. Response không trả metadata nội bộ CouchDB hoặc payment audit.
- Giỏ hàng và checkout cần JWT customer: `GET /api/v1/cart`; `POST /api/v1/cart/items`, `/api/v1/cart/items/update`, `/api/v1/cart/items/select`, `/api/v1/cart/items/select-all`, `/api/v1/cart/items/change-size` và `/api/v1/cart/items/remove` nhận JSON. `POST /api/v1/checkout/preview` tính lại giá/ship/voucher từ CouchDB; `POST /api/v1/checkout` tạo đơn COD sau khi kiểm tra lại stock. Checkout bắt buộc header `Idempotency-Key` là 32 ký tự hex thường; gửi lại cùng key và cùng nội dung trả lại cùng đơn, còn nội dung khác trả 422. API hiện chưa xử lý thanh toán online.
- JSON API tài khoản/quản trị có xác thực JWT độc lập với session của trình duyệt: gửi `POST /api/auth/token` với `Content-Type: application/json` và body `{"username":"<email-hoặc-username>","password":"<mật-khẩu>"}` để nhận access token; gửi token trong `Authorization: Bearer <access_token>` tới endpoint được bảo vệ như `GET /api/v1/me`, `GET /api/v1/orders` hoặc `GET /api/admin/revenue/stats`. API doanh thu chỉ dành cho role `staff`/`manager`; role và trạng thái tài khoản được đọc lại từ CouchDB mỗi request. Access token HS256 hết hạn sau 15 phút theo mặc định, không có refresh token hoặc thu hồi tức thời; khóa tài khoản chặn request ngay nhờ kiểm tra trạng thái live, còn token đã cấp trước khi đổi mật khẩu có thể dùng tới khi hết hạn. Các trang web tiếp tục dùng session. Cấu hình `JWT_SECRET` bằng chuỗi ngẫu nhiên riêng tối thiểu 32 byte trong `.env`, không commit hoặc gửi secret cho client; thay secret sẽ vô hiệu toàn bộ token hiện tại. Kiểm tra cấp/xác minh và từ chối token sửa đổi bằng `docker compose exec -T app composer auth:jwt-smoke`.
- Contract OpenAPI cho toàn bộ JSON API nằm tại `docs/openapi.yaml`; có thể nhập file này vào Swagger Editor/Postman để xem route, payload, auth Bearer và các query parameter.
- Khách và người dùng vãng lai có thể gửi một đánh giá cho mỗi sản phẩm trên mỗi tài khoản/phiên. POST có CSRF, sao 1–5 và nội dung tối đa 2000 ký tự; đầu ra được escape trước khi hiển thị. Tại `/admin/reviews`, staff xem và phản hồi đánh giá; phản hồi có người/thời gian và được hiển thị công khai đã escape. Manager ẩn/khôi phục bằng CSRF, giữ document và audit metadata.
- Quản lý sản phẩm tại `/admin/products`: staff xem danh sách; manager tạo, sửa tên/mô tả/danh mục/giá/tồn kho theo size, ẩn mềm và khôi phục sản phẩm. Mã biến thể cũ và ảnh hiện có được giữ để bảo toàn tham chiếu lịch sử; size cũ không thể đổi hoặc xóa. Manager có thể tải ảnh JPEG/PNG/WebP tối đa 5 MB; server kiểm tra MIME thật, cấu trúc và kích thước ảnh, lưu ngoài `public` trong Docker volume `php_uploads`, rồi phục vụ qua `/media/{filename}` với `nosniff`.
- Route `/admin` giữ tương thích với trang `Dashboard.Index` cũ và dùng danh sách quản lý sản phẩm hiện tại; staff/manager cần đăng nhập như các route quản trị khác.
- Khách quên mật khẩu cần liên hệ cửa hàng để nhân viên xác minh và cấp mật khẩu tạm qua `composer account:provision-customer -- <email>`; khách buộc đổi mật khẩu ngay sau đăng nhập. Kênh email tự động chưa được cấu hình.
- Bộ lọc và phân trang catalog hiện chạy ở PHP sau Mango query, phù hợp fixture demo 48 sản phẩm và 270 review; trước khi dùng catalog với dữ liệu lớn cần chuyển sang bookmark pagination và materialized rating summary.
- Index catalog được khai báo ở `database/couchdb/indexes/catalog_indexes.json` và tạo riêng bằng script, không sửa fixture/importer.
- Index lookup username dùng `_design/catalog_indexes/account_usernames`; đăng ký chuẩn hóa email và dùng document ID xác định để xử lý xung đột đăng ký cùng email.
- Index lịch sử đơn dùng `_design/catalog_indexes/customer_orders`; admin dùng `admin_orders` khi lọc trạng thái và `admin_orders_by_date` khi xem tất cả; `delivery_tracking_code` kiểm tra mã vận đơn trùng khi tạo. Không có index delivery status vì hiện không lọc danh sách theo trạng thái này. Sau khi cập nhật, chạy `docker compose exec -T app composer catalog:indexes` để tạo/cập nhật index.
- Cấp/reset mật khẩu customer qua terminal nhập ẩn, chỉ cho database `_test`: `docker compose exec -it app composer account:provision-customer -- <email>`; nhân viên chuyển mật khẩu tạm cho khách qua kênh đã xác minh.

# Dữ liệu CouchDB cho WEBTHOITRANG

Các file tại đây chốt contract và chuẩn bị fixture dữ liệu mẫu. Chúng không ghi vào CouchDB cho tới khi importer được chạy.

## Dựng lại fixture

Từ thư mục gốc `D:\PHP_NOSQL`, chạy với Python 3 (không cần package ngoài):

```powershell
python PHP_COUCHDB\database\couchdb\tools\build_fixture_seed.py
```

Bộ sinh đọc các fixture trong `WEBTHOITRANG`, kiểm tra các hàng sản phẩm/variant/review và xác nhận file của mọi ảnh tham chiếu. Output là `seeds/retail_order_delivery.json`. Mật khẩu dạng rõ trong fixture SQL không được sao chép; năm user demo có `password_hash: null` và `requires_password_reset: true` cho tới khi môi trường PHP cấp hash.

## Thứ tự import

Importer `PHP_COUCHDB/scripts/import_seed.php` chỉ cho phép target kết thúc bằng `_test`. Nó tạo database test nếu còn thiếu, cài validator riêng trước business documents, so khớp document đã có, từ chối khác biệt hoặc dữ liệu ngoài fixture, kiểm tra từng kết quả `_bulk_docs` và kiểm tra lại toàn bộ fixture. Nếu CouchDB ngắt giữa các batch, chạy lại importer để tiếp tục; document giống seed sẽ được bỏ qua. `--verify-only` chỉ đối chiếu dữ liệu hiện có và không ghi.

Khi chỉ cập nhật validator của database test đã có, chạy `docker compose exec -T app composer db:validator:update`. Script giữ nguyên business documents, chỉ ghi `_design/domain_validation` vào database có tên kết thúc bằng `_test`, rồi đọc lại để xác minh. File chuẩn là `design-docs/domain_validation.json`; validator yêu cầu các event `payment_history` hợp lệ, không cho xóa/sửa event cũ và chặn đơn đã paid quay về unpaid. Sau khi cập nhật, chạy `composer seed:verify` để kiểm tra fixture.

Không đổi `COUCHDB_DATABASE` sang database khác `_test` hoặc import vào database có dữ liệu nghiệp vụ. Password hash demo cần được provision riêng bằng PHP trước khi bật đăng nhập.

## Chạy với Docker Compose

Từ `D:\PHP_NOSQL\PHP_COUCHDB`:

```powershell
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
# Sửa COUCHDB_PASSWORD trong .env thành một secret local ngẫu nhiên.
docker compose up --build -d
docker compose run --rm app composer seed:import
docker compose run --rm app composer seed:verify
docker compose exec -T app composer catalog:indexes
```

Web foundation bind vào `127.0.0.1:8081`; CouchDB/Fauxton bind vào `127.0.0.1:5984`. App container kết nối CouchDB bằng `http://couchdb:5984`. Data CouchDB và upload dùng Docker named volume. `docker compose down` giữ data; không thêm `-v` khi chỉ muốn dừng stack.

## Theo dõi và quản trị

Chạy các lệnh sau từ thư mục `PHP_COUCHDB` để kiểm tra dịch vụ mà không cần mở giao diện:

```powershell
docker compose ps
docker compose logs --tail=100 couchdb
docker compose logs --tail=100 app
Invoke-WebRequest http://localhost:8081/health/ready
```

Compose kiểm tra CouchDB bằng `GET /_up` và chỉ khởi động app sau khi node CouchDB sẵn sàng. `/health/ready` kiểm tra thêm app đọc được database đang cấu hình; CouchDB hoặc database chưa sẵn sàng sẽ trả HTTP 503. Endpoint không trả thông tin đăng nhập.

Mở Fauxton tại [http://127.0.0.1:5984/_utils/](http://127.0.0.1:5984/_utils/) và đăng nhập bằng `COUCHDB_USER`/`COUCHDB_PASSWORD` trong `.env`. Tại đây có thể xem database, documents, design documents và Mango indexes. Database mặc định trong `.env.example` là `retail_order_delivery_test`; xác nhận đúng môi trường trước khi tạo hoặc xóa database.

Khởi động lại riêng CouchDB bằng `docker compose restart couchdb`. Dừng stack bằng `docker compose down`; lệnh này giữ named volumes. `docker compose down -v` xóa volume dữ liệu CouchDB cùng volume của các dịch vụ khác, vì vậy chỉ dùng khi chủ động muốn xóa dữ liệu local. Không đưa `.env` hoặc credential lên Git hay chụp màn hình Fauxton có thông tin nhạy cảm.

## Backup và phục hồi thử nghiệm

Các lệnh dưới đây chỉ cho phép database có hậu tố `_test`. Tạo backup JSON trong `var/backups` (bao gồm design documents và CouchDB attachments):

```powershell
docker compose exec -T app composer db:backup -- retail_order_delivery-20261007.json
```

Backup chứa dữ liệu tài khoản/khách hàng và password hash; giữ file như dữ liệu nhạy cảm, không gửi qua kênh công khai. `var/backups/` đã được thêm vào `.gitignore`. Backup phân trang nên không tạo snapshot nguyên tử nếu app tiếp tục ghi trong lúc chạy; với quy trình hiện tại, hãy tạm ngừng thao tác ghi trước khi backup.

Phục hồi luôn tạo một database `_test` mới và từ chối target đã tồn tại, không ghi đè database. Ví dụ:

```powershell
docker compose exec -T app composer db:restore -- retail_order_delivery-20261007.json retail_restore_check_test
docker compose exec -T -e COUCHDB_DATABASE=retail_restore_check_test app composer seed:verify
```

Chạy smoke test trên database phục hồi trước khi dùng tiếp. Sau khi nghiệm thu có thể xóa database thử bằng Fauxton hoặc CouchDB API. Quy trình này hiện chỉ dành cho local/demo; chưa phải backup production có snapshot nhất quán. Ảnh upload nằm ở Docker volume `php_uploads`, không thuộc backup CouchDB, nên phải sao lưu riêng nếu cần giữ các file đó. `docker compose down -v` xóa dữ liệu cả volume và không phải cách phục hồi.

## Xuất snapshot SQL Server

Nếu nguồn migration chỉ là các file trong repository, không cần khởi tạo database SQL Server: dùng `python database/couchdb/tools/build_fixture_seed.py` để dựng fixture hiện có từ `WEBTHOITRANG/3_DuLieu.sql` và `bulk_docs.json`. Script dưới đây chỉ dành cho trường hợp bạn có một database `ShopQuanAo` đang chạy và muốn export dữ liệu live mới hơn fixture.

Để thử toàn pipeline export với bộ demo của repository trên máy chưa có `ShopQuanAo`, chỉ chạy các script tạo schema, trigger và dữ liệu mẫu theo thứ tự sau. Các lệnh không được chạy nếu database đã tồn tại:

```powershell
sqlcmd -S localhost -E -C -b -I -f 65001 -i D:\PHP_NOSQL\WEBTHOITRANG\1_TaoBang.sql
sqlcmd -S localhost -E -C -b -I -f 65001 -i D:\PHP_NOSQL\WEBTHOITRANG\2_Trigger.sql
sqlcmd -S localhost -E -C -b -I -f 65001 -i D:\PHP_NOSQL\WEBTHOITRANG\3_DuLieu.sql
sqlcmd -S localhost -E -C -b -Q "ALTER DATABASE ShopQuanAo SET ALLOW_SNAPSHOT_ISOLATION ON;"
```

`-I` bật `QUOTED_IDENTIFIER`, cần cho các computed column persisted trong schema. Không chạy `GanChuSoHuu.sql` cho export; file đó đổi owner database sang `sa` và không cần thiết. Bộ dữ liệu demo có mật khẩu legacy dạng rõ trong SQL, nhưng exporter cố ý loại các cột đó và account CouchDB phải được provision mật khẩu mới.

Để chuẩn bị dữ liệu thật, chạy PowerShell từ `PHP_COUCHDB`:

```powershell
.\scripts\export_sql_server_snapshot.ps1 -Server localhost -Database ShopQuanAo
```

Snapshot xuất vào `var/migration-exports/`, không bị Git theo dõi. Exporter dùng Windows Integrated Security và một transaction `SNAPSHOT`; database phải bật `ALLOW_SNAPSHOT_ISOLATION` và Windows identity hiện tại cần quyền đọc 13 bảng nghiệp vụ. Nó nối đầy đủ các phần JSON nếu SQL Server chia kết quả lớn thành nhiều hàng, và cố ý không xuất cột mật khẩu legacy. File chứa dữ liệu cá nhân nên cần được bảo vệ, không commit hoặc chia sẻ công khai.

Lệnh export chỉ tạo snapshot JSON nguồn. Sau khi export, kiểm tra counts, orphan references, ảnh, trạng thái đơn và totals theo [mapping schema v2](docs/schema-v2-and-mapping.md). Bộ transform offline tạo document schema v2 nhưng không kết nối hay ghi CouchDB:

Chạy preflight không in giá trị hoặc thông tin cá nhân:

```powershell
python database/couchdb/tools/validate_sql_snapshot.py var/migration-exports/<file>.json --asset-root D:\PHP_NOSQL\WEBTHOITRANG\ShopQuanAo_MVC --strict
python database/couchdb/tools/transform_sql_snapshot.py var/migration-exports/<file>.json --asset-root D:\PHP_NOSQL\WEBTHOITRANG\ShopQuanAo_MVC --output var/migration-exports/v2-<file>
python -m unittest database.couchdb.tools.test_validate_sql_snapshot database.couchdb.tools.test_transform_sql_snapshot -v
```

Preflight dừng khi có khóa trùng, tham chiếu mồ côi, tiền VND lẻ, credential field, trạng thái/ngày không hợp lệ hoặc thiếu ảnh. Sai lệch totals lịch sử được ghi là warning; transform cũng dừng cho tới khi warnings được đối soát. Output chứa dữ liệu cá nhân nên nằm trong `var/migration-exports/` (đã gitignore), không commit hoặc chia sẻ công khai. Transform bảo toàn totals lịch sử, không xuất password hash cũ, buộc provision mật khẩu mới cho account và không bịa thời điểm chuyển trạng thái/thanh toán. Sau khi review artifact, import vào database test riêng (không dùng database fixture `retail_order_delivery_test`) bằng `docker compose exec -e COUCHDB_DATABASE=sql_migration_test -T app composer db:snapshot:import -- /var/www/html/var/migration-exports/v2-<file>.json`. Importer chỉ nhận `_test`, cài validator trước dữ liệu, kiểm tra document hiện có và có thể resume nếu bulk write dở dang; nó không ghi đè hoặc xóa document khác snapshot.

## Nội dung

- `docs/schema-v2-and-mapping.md`: contract, SQL mapping, enum và ràng buộc.
- `design-docs/domain_validation.json`: design document CouchDB cho document version 2.
- `seeds/retail_order_delivery.json`: 347 document fixture: 48 product, 270 review, 3 cart, 3 customer, 2 staff, 13 order, 5 voucher, 2 phương thức ship và 1 validator.
- `tools/build_fixture_seed.py`: builder tái lập fixture từ script SQL mẫu và seed document v1.
- `indexes/catalog_indexes.json`: Mango index phục vụ sản phẩm (partial filter theo type/active), đánh giá, username, lịch sử đơn theo customer/ngày, admin đơn theo trạng thái/ngày hoặc ngày, và màn hình quản trị sản phẩm/đánh giá.
- `queries/*.json`: query `_find` mẫu cho sản phẩm active, đơn pending và review active; mỗi file ghim `use_index` để có thể chạy lại và đối chiếu `_explain`.
- `../../scripts/import_seed.php`: importer PHP chỉ ghi vào database `_test`.
- `../../scripts/create_catalog_indexes.php`: tạo index catalog trong database đang cấu hình; chạy sau seed.
- `../../scripts/couchdb_query_diagnostics.php`: chạy `_explain` và `_find` read-only cho các query mẫu; chỉ chấp nhận database `_test` và báo index được chọn cùng số document trả về.
- `../../scripts/compact_order_markers.php`: dry-run mặc định để kiểm tra marker của journal đã terminal; apply chỉ trong database `_test` sau xác nhận tương tác.
- `../../scripts/database_backup.php`: backup/restore local, chỉ `_test`; restore chỉ tạo database mới.
- `migrations/export_sql_server_snapshot.sql` và `../../scripts/export_sql_server_snapshot.ps1`: truy vấn snapshot đọc-only của 13 bảng nghiệp vụ, bỏ qua mật khẩu cũ.
- `tools/validate_sql_snapshot.py`: kiểm tra cấu trúc và đối soát snapshot trước khi transform/import; không in dữ liệu cá nhân.
- `tools/transform_sql_snapshot.py`: transform snapshot đã preflight thành artifact schema v2 offline; chưa ghi CouchDB.
- `../../scripts/import_sql_snapshot.php`: import artifact vào database `_test` sau khi review; hỗ trợ kiểm tra và resume, không thay thế quy trình backup/production rollout.
- `../../scripts/provision_staff_password.php`: cấp/reset hash staff qua terminal không echo, chỉ khi database kết thúc `_test`.

Đây là dữ liệu học/demo từ repository. Muốn di chuyển dữ liệu hiện hành cần trích export nhất quán từ SQL Server và đối soát riêng.

# PHASE 06 – FINAL TESTING & DEMO DATASET

Ngày kiểm tra: 2026-10-07. Thực hiện trên project local và database demo cô lập; không reset database hiện hành.

## 1. Environment

- PHP 8.3.35; Composer 2.10.3; Docker Compose app container và CouchDB 3.5.0 đều healthy.
- App đang dùng `127.0.0.1:8081`; readiness trả `ready` và dependency `couchdb`.
- Demo app riêng đang chạy ở `127.0.0.1:8082`, trỏ tới database demo Phase 6.
- **DEV DATABASE:** `shopquan_ao_sql_migration_test`.
- **TEST DATABASE:** hiện dùng chung `shopquan_ao_sql_migration_test`; `.env` không tách database dev/test riêng.
- **DEMO DATABASE:** `shopquan_ao_phase06_demo_test`.
- COUCHDB/JWT credentials có trong runtime environment nhưng không được in vào report hoặc output.
- Existing DB không bị reset hay seed lại. Regressions chạy theo scripts sẵn có; isolated seed verification tự xóa database tạm của chính nó.

## 2. Regression suite

Đã đọc `composer.json` và chạy đúng các script có sẵn; tất cả đều PASS:

| Script | Kết quả |
| --- | --- |
| `auth:jwt-smoke` | PASS – cấp token, verify, reject tamper và issuer sai |
| `catalog:admin-smoke` | PASS – staff/manager, create/edit, validation, soft delete/restore |
| `checkout:smoke` | PASS – recovery, order/delivery transitions, history, CSRF, ownership, revision conflict, stock, cancellation |
| `checkout:http-smoke` | PASS – guest/customer APIs, JWT, payment, review, revenue, upload/media và idempotency |
| `couchdb:diagnostics` | PASS – read-only Mango/index diagnostics |
| `couchdb:failure-smoke` | PASS – lỗi CouchDB trả generic HTTP 503, không lộ connection detail |
| `seed:verify-isolated` | PASS – import/verify 347 fixture docs trên DB tạm, DB tạm được xóa |
| PHP lint `src/`, `public/`, `scripts/`, `templates/` | PASS – 89 file |
| `composer validate --no-check-publish` | PASS, có cảnh báo license chưa khai báo |

## 3. Customer flow

Browser thật đã đăng nhập bằng customer demo, mở catalog/product, thêm giỏ, đổi size và quantity, chọn item, xem checkout, dùng `SAVE10` và COD, đặt hàng thành công, rồi xác minh order xuất hiện trong History và Detail. Detail hiển thị đúng item/product, snapshot giá, phí ship, giảm giá, tổng tiền, người nhận, payment status và Order Timeline. Cùng tài khoản xem đơn tracked, failed delivery và delivered legacy; customer khác bị giới hạn theo ownership trong HTTP regression.

## 4. Admin flow

Manager demo đăng nhập thành công. Admin Orders hiển thị 27 đơn; lọc `shipping` trả 6 đơn. Admin Order Detail hiển thị line item, receiver, payment, delivery code, estimated date, và hai history riêng. Browser đã cập nhật một order qua `confirmed → packing → shipping`, sau đó delivery `created → picked_up → in_transit → out_for_delivery → delivered`; trang hiện success message. Một order `failed_delivery` được retry, tiếp tục qua last-mile và delivered; note retry và event được ghi vào timeline. Form transition chỉ hiện trạng thái hợp lệ tiếp theo.

## 5. Order workflow

- Browser: `pending → confirmed → packing → shipping → delivered` thành công.
- Invalid transitions, terminal status, history consistency, stale `_rev`/409 và cancellation/recovery được kiểm tra bởi `checkout:smoke`.
- Status history giữ thứ tự sự kiện; item/product snapshot và tổng tiền hiển thị đúng.

## 6. Delivery workflow

- Browser: `created → picked_up → in_transit → out_for_delivery → delivered` và `failed_delivery → in_transit` retry đều thành công.
- Có tracking code duy nhất, estimated delivery date, delivered event và history append-only.
- Customer có thể đọc Delivery Timeline của đơn thuộc mình; đơn delivered legacy không tracking vẫn mở bình thường.
- Cross-order ownership, CSRF, transition và revision conflict có trong smoke test.

## 7. Payment

- Browser checkout COD + voucher `SAVE10` tính giảm 15.000₫ và tạo đơn đúng tổng tiền 155.000₫; đơn mới ghi unpaid.
- Legacy delivered demo order có trạng thái COD paid; workflow xác nhận đã nhận/COD audit và các payment API được kiểm tra trong `checkout:smoke` và `checkout:http-smoke`.
- UI giữ Payment status tách khỏi Order/Delivery status.

## 8. Authorization/security

- Guest truy cập Admin Orders nhận 403; manager dùng được admin UI; customer xem order history/detail gắn với `legacy_id` của chính mình.
- JWT/role, ownership, CSRF, invalid payload, payment audit, safe upload/media và generic CouchDB failure được kiểm tra qua regression scripts.
- PHP escaping được dùng trong các template đã xem; Phase 6 không thực hiện pentest ngoài phạm vi.

## 9. Realtime regression

PASS. Customer Detail SSE báo đang theo dõi; sau khi workflow service cập nhật order tracked trên database demo riêng, customer page tự hiển thị `in_transit` và ghi chú mới mà không reload. `checkout:http-smoke` cũng kiểm tra SSE/API behavior. Codex In-app Browser dùng chung cookie giữa các tab, vì vậy update realtime dùng cùng PHP workflow service trong tiến trình tách biệt thay vì hai profile đăng nhập song song.

## 10. Responsive verification

- Desktop khoảng 1280px: catalog, product detail, cart, checkout, customer history/detail và admin list/detail được mở trực tiếp.
- Mobile 390px: đo `document.documentElement.scrollWidth` và kiểm tra hiển thị trên catalog, product detail, cart, checkout, customer history/detail, admin list và admin detail. Các trang dùng 375px layout content trong viewport 390px; không có horizontal page overflow.
- Admin order table sản phẩm cuộn bên trong vùng table trên mobile; page layout không bị kéo ngang. Checkout input/select 306px, textarea 306px, button 306px và xếp dọc.
- Customer/admin Order Detail trên 390px vẫn đọc được order info, item, address, status và timeline; form không chồng nút.

## 11. Demo dataset

Database riêng `shopquan_ao_phase06_demo_test` chứa business documents tổng hợp:

- Customers: 6; Staff/Manager: 2.
- Products: 18 active; voucher: 1; shipping methods: 2.
- Orders: 27 — pending 5, confirmed 4, packing 4, shipping 6, delivered 6, cancelled 2.
- Delivery trong 6 đơn shipping: `created` 1, `picked_up` 1, `in_transit` 2, `out_for_delivery` 1, `failed_delivery` 1. Có 2 failed events tổng cộng; một order có failed rồi retry.
- Có 4 tracked delivered orders và 2 legacy delivered orders không tracking.
- Product media từ fixture catalog; Order snapshot không lưu product image. UI placeholder “Đơn không lưu ảnh sản phẩm” rõ ràng và không làm hỏng layout.

## 12. Demo accounts

- Customer demo: AVAILABLE (`customer1@example.test` … `customer6@example.test`).
- Staff demo: AVAILABLE (`staff.demo@example.test`).
- Manager demo: AVAILABLE (`manager.demo@example.test`).
- Mật khẩu sinh cho demo nằm trong `var/phase06_demo_credentials.txt`; file đã được thêm vào `.gitignore`. Không lưu password trong report.
- Dữ liệu tên/địa chỉ/điện thoại tổng hợp, email dùng `example.test`.

## 13. Seed/reset procedure

Từ thư mục project:

```powershell
docker compose exec -T app composer demo:seed
docker compose exec -T app composer demo:verify
docker compose exec -T app composer demo:reset
```

`demo:seed` chỉ tạo DB demo nếu chưa tồn tại. `demo:reset` kiểm tra tên database đúng hậu tố `_phase06_demo_test`, khác `COUCHDB_DATABASE`, và từ chối nếu gặp business document không nhận diện hoặc design document lạ. Reset đã được thử với order/cart phát sinh từ demo app; document không nhận diện trước đó khiến lệnh dừng mà không thay đổi database. `PHASE06_DEMO_DATABASE` có thể đổi tên target theo cùng hậu tố; `PHASE06_DEMO_PASSWORD` có thể đặt password cho lần seed nếu cần.

## 14. Data integrity

`composer demo:verify` PASS trên baseline cuối:

- đúng counts/status distribution như mục 11;
- item quantity dương, product stock không âm, order items và totals khớp;
- customer references trỏ đúng legacy IDs trong tập demo;
- tracking code không trùng, history timestamps tăng/không giảm;
- tracked delivered order đồng bộ trạng thái delivery/order;
- 2 delivered legacy orders không có tracking; có đủ delivery statuses, 2 failed events và retry.

## 15. Bugs found

- Không tái hiện được UI/business bug trong ứng dụng.
- Demo setup ban đầu liên kết customer bằng CouchDB document ID thay vì `legacy_id`; browser History rỗng. Generator đã sửa về `legacy_id` và browser History hiển thị đúng.
- Demo shipping methods ban đầu không khớp code `BD` mà checkout mặc định dùng; browser checkout trả 503 “Chưa có phương thức vận chuyển khả dụng.” Generator đã sửa IDs/codes/fees theo methods của ứng dụng (`BD`, `HT`); checkout browser sau đó PASS.
- Reset guard/verifier ban đầu không nhận diện cart/checkout order do chính demo app phát sinh; thao tác reset bị từ chối an toàn. Guard giờ chỉ nhận cart thuộc customer demo và checkout order với ID prefix/owner demo chính xác; database không nhận diện vẫn bị từ chối.
- Verifier status-count comparison ban đầu phụ thuộc thứ tự document; đã đổi sang so sánh từng trạng thái.

## 16. Bugs fixed

- Sửa generator, integrity verifier, guard reset và fixture demo; không thay đổi business logic của PHP app.
- Sửa README/Composer scripts để có seed, verify và guarded reset cho database demo.
- Không thay schema nhằm bổ sung ảnh vào order snapshot.

## 17. Files modified

- `.gitignore`
- `README.md`
- `composer.json`
- `scripts/seed_phase06_demo.php`
- `scripts/verify_phase06_demo.php`
- `docs/PHASE_06_FINAL_TEST_DEMO_DATA_REPORT.md`
- Local-only ignored file: `var/phase06_demo_credentials.txt`

## 18. Remaining issues

- Development và regression test hiện dùng chung `shopquan_ao_sql_migration_test`; database này không bị reset trong Phase 6. Có thể tách DEV/TEST riêng ở một giai đoạn khác nếu cần.
- Hai browser tabs của Codex chia sẻ cookie/session; realtime kiểm thử browser nhận event không reload, nhưng không thể giữ manager/customer đăng nhập song song trong cùng một browser profile.
- Order snapshot chưa lưu ảnh sản phẩm; UI placeholder hiện rõ và nguyên trạng được giữ theo scope.

## 19. Deferred improvements

- P3: lưu image snapshot hoặc image identifier ổn định trong order để Order Detail hiển thị ảnh gốc.
- Tách dev/test database khi cần môi trường regression độc lập.
- Hỗ trợ hai browser profile tách biệt cho demo realtime song song.

## 20. Ready for NoSQL/Fauxton phase?

YES. PHP core/order/delivery và desktop/mobile flows được kiểm tra; regression suite PASS; demo DB cô lập, đủ status/history và integrity PASS; không còn P0/P1. Có thể sang Phase 7 tập trung CouchDB/Fauxton. Không triển khai Phase 7 trong Phase này.

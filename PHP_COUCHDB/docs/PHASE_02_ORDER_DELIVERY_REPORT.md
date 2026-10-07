# PHASE 02 – ORDER + DELIVERY

Project: `D:\PHP_NOSQL\PHP_COUCHDB`  
Ngày kiểm tra: 2026-10-07

## 1. Existing Order workflow

- Order status đã có sẵn và được giữ nguyên: `pending → confirmed → packing → shipping → delivered`.
- Hủy chỉ có thể bắt đầu ở `pending`, `confirmed`, `packing`; order đã thanh toán không thể hủy nếu chưa có refund flow. Khi đã bắt đầu `shipping`, hủy bị chặn.
- `status_history` được nối thêm khi admin hoặc customer xác nhận nhận hàng. `OrderWorkflowService::updateOrder()` đọc revision mới nhất và retry tối đa 8 lần khi CouchDB trả 409.
- Customer web/API chỉ đọc đúng order có `customer_id` sở hữu; guest chỉ truy cập order ID trong session. Admin order routes chấp nhận role `staff` hoặc `manager`; POST form có CSRF. API staff dùng JWT.
- Trước Phase 2, `shipping` chứa method/fee cùng `tracking_code` và `estimated_delivery_date`; `assigned_staff_id` nằm ở cấp Order nhưng chưa được dùng trong delivery workflow.

## 2. Changes implemented

- Thêm `delivery_tracking` vào cùng Order document, khởi tạo khi Order chuyển sang `shipping`; mã vận đơn được tạo ngẫu nhiên và kiểm tra uniqueness bằng Mango query.
- Thêm cập nhật delivery qua service/controller/API; mọi event dùng thời điểm UTC backend, note tối đa 500 ký tự, actor lấy từ phiên/JWT và history nối thêm.
- Delivery chuyển `delivered` sẽ đồng thời chuyển Order sang `delivered`. Với Order tracking cũ, staff phải bắt đầu tracking từ trạng thái `created`; customer xác nhận đã nhận vẫn đồng bộ delivery/order và COD theo rule cũ.
- Thêm timeline cho customer/guest và khu vực Delivery Information, history, form cập nhật cho staff/manager ở admin order detail.
- Sửa lỗi phát hiện trong HTTP smoke khi guest receipt gọi helper từ static closure; closure đã được sửa và smoke chạy lại PASS.

## 3. Delivery document design

Order mới có cấu trúc khái quát:

```json
{
  "shipping": {
    "method_code": "BD",
    "method_name": "...",
    "fee": 0,
    "currency": "VND"
  },
  "assigned_staff_id": "staff:NV001",
  "delivery_tracking": {
    "tracking_code": "DEL-20261007-12AB34CD56EF",
    "estimated_delivery_date": "2026-10-10",
    "status": "created",
    "delivered_at": null,
    "history": [
      {
        "status": "created",
        "at": "2026-10-07T09:00:00Z",
        "note": "Đã tạo thông tin giao hàng",
        "updated_by": "staff:NV001"
      }
    ]
  }
}
```

`assigned_staff_id` được tái sử dụng ở cấp Order theo schema cũ; không lưu trùng trong `delivery_tracking`. Field legacy `shipping.tracking_code` và `shipping.estimated_delivery_date` được chuyển vào `delivery_tracking` khi staff khởi tạo tracking cho Order cũ đang giao.

## 4. Order status rules

- Enum và các transition hiện tại không đổi.
- Với Order có `delivery_tracking`, staff không thể đánh dấu delivered trực tiếp khi delivery chưa hoàn tất; cập nhật delivery cuối sẽ hoàn tất cả hai trạng thái trong cùng document write.
- Customer receipt confirmation vẫn được phép hoàn tất tracked delivery và Order; COD tiếp tục ghi nhận paid theo workflow hiện tại.
- Order cũ chưa có `delivery_tracking` vẫn dùng được; rule cũ không bị buộc phải backfill.

## 5. Delivery status rules

- `created → picked_up → in_transit → out_for_delivery → delivered`.
- `failed_delivery` có thể phát sinh từ `created`, `picked_up`, `in_transit`, `out_for_delivery`; có thể thử lại từ `failed_delivery → in_transit` hoặc `out_for_delivery`.
- `delivered` là trạng thái cuối. Gửi lại đúng trạng thái là idempotent và không tạo event trùng.
- Khi giao thất bại, Order vẫn ở `shipping`; Order status và delivery status không bị trộn.

## 6. Files modified

- `src/Checkout/CheckoutRepository.php`
- `src/Checkout/CheckoutService.php`
- `src/Orders/OrderWorkflowService.php`
- `src/Orders/OrderAdminController.php`
- `src/Auth/JwtApiController.php`
- `public/index.php`
- `templates/orders/detail.php`
- `templates/orders/delivery_timeline.php` (mới)
- `templates/admin/orders/detail.php`
- `database/couchdb/design-docs/domain_validation.json`
- `database/couchdb/indexes/catalog_indexes.json`
- `database/couchdb/docs/schema-v2-and-mapping.md`
- `scripts/create_catalog_indexes.php`
- `scripts/smoke_checkout.php`
- `scripts/http_smoke_checkout.php`
- `docs/openapi.yaml`
- `README.md`
- `docs/PHASE_02_ORDER_DELIVERY_REPORT.md`

## 7. Routes added/changed

- `POST /admin/orders/{id}/delivery` — staff/manager session, CSRF form, cập nhật delivery status.
- `POST /api/admin/orders/{id}/delivery` — staff/manager JWT, JSON `{ "status": "picked_up", "note": "..." }`.
- Customer web order detail và `GET /api/v1/orders/{id}` hiển thị/đưa ra delivery timeline nếu có; ownership guards không đổi.
- OpenAPI đã mô tả endpoint và detail payload; không thêm route customer mutation.

## 8. Validator/index changes

- CouchDB validator kiểm tra delivery status/code/date/history/event shape, thứ tự timestamp, history append-only, allowed transition và nhất quán `Order=delivered ⇔ tracked delivery=delivered`. Order cũ không có field mới vẫn hợp lệ.
- Thêm `delivery_tracking_code` index dùng khi kiểm tra tracking code trùng. Không thêm index delivery status vì chưa có truy vấn lọc theo status.
- Đã áp dụng index và validator trên DB `_test` đang cấu hình; lệnh cập nhật validator xác nhận source và runtime khớp. Không sửa business documents.

## 9. Automated tests

- PHP syntax check toàn bộ `src`, `public`, `scripts`, `templates` — PASS.
- `composer checkout:smoke` — PASS: Order workflow, delivery state machine, history append-only, CSRF/role/ownership, unique code format, chuyển tracking legacy, CouchDB stale `_rev` trả 409 và history được giữ, customer receipt/COD.
- `composer checkout:http-smoke` — PASS: admin detail/form POST có CSRF, JWT staff updates, customer API detail, quyền, guest/customer ownership và order cũ không có tracking.
- `composer catalog:admin-smoke`, `composer auth:jwt-smoke`, `composer couchdb:diagnostics` — PASS.
- `/health`, `/health/ready`, `/api/v1/products` — HTTP 200; app và CouchDB containers healthy.
- `composer validate --no-check-publish` — PASS; có cảnh báo metadata cũ là thiếu `license`.
- OpenAPI được cập nhật nhưng chưa parse tự động vì môi trường không có YAML parser cài sẵn.
- Không còn test FAIL ở lượt cuối. Trong quá trình phát triển, HTTP smoke tìm thấy và giúp sửa lỗi static closure đã nêu ở mục 2.

## 10. Manual tests required

- **Customer:** đăng nhập → lịch sử đơn → mở Order detail → kiểm tra mã vận đơn, timeline và nội dung tiếng Việt; thử Order cũ không có tracking.
- **Staff/Manager:** đăng nhập → admin orders → order detail → chuyển trạng thái Order vào `shipping` → cập nhật delivery nhiều bước và thử CSRF sai.
- Các màn hình đã được test qua HTTP integration/render assertions, chưa được click-through bằng browser thật.

## 11. Backward compatibility

- Không đổi CouchDB type, schema version, Order status hay database.
- Order không có `delivery_tracking` vẫn đọc được; UI hiển thị “Chưa có thông tin giao hàng” và không sinh history giả.
- Shipping method tiếp tục nằm trong `shipping`. Legacy tracking code/ngày dự kiến được đọc làm fallback; khi staff tạo tracking sẽ chuyển vào field canonical mới.
- Existing assigned staff field tiếp tục ở cấp Order.

## 12. Remaining issues

- Cần kiểm tra thủ công các flow trình duyệt ở mục 10 trước demo.
- OpenAPI YAML chưa được parser tự động xác nhận do thiếu parser trong môi trường; endpoint và schema đã được cập nhật trong source.
- Tracking code có index/query để chống trùng ở tầng ứng dụng; validator không thể đảm bảo uniqueness xuyên nhiều CouchDB documents.

## 13. Ready for Phase 3?

**NO, chờ xác nhận thủ công trên browser trước.** Workflow và integration test đã PASS; sau khi kiểm tra customer/staff UI ở mục 10, baseline sẵn sàng cho giai đoạn tiếp theo. Không triển khai realtime, Fauxton nâng cao hay Phase 3 trong lượt này.

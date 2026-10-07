# Mango queries cho demo Phase 07

Mỗi file JSON chứa trực tiếp request body cho Fauxton `/_find` (hoặc `/_explain`). Chọn đúng database demo `shopquan_ao_phase06_demo_test`, mở **Mango Query**, dán JSON vào ô query rồi chạy. Các mã `P06-*` là dữ liệu tổng hợp do `scripts/seed_phase06_demo.php` tạo, không phải thông tin khách hàng thật.

Dataset hiện có 27 order: pending 5, confirmed 4, packing 4, shipping 6, delivered 6 và cancelled 2. Delivery có in_transit 2, out_for_delivery 1, failed_delivery 1; bộ demo không có guest order nên query 08 hợp lệ nhưng trả 0 document cho đến khi có fixture guest riêng.

Query 06 dùng khoảng ngày cố định rộng quanh ngày tạo dataset hiện tại (2026-10-07). Khi seed mới vào thời điểm khác, cập nhật `ordered_at` bounds theo các ngày trong dataset. Query delivery theo trạng thái có thể cần quét thêm document vì index hiện có chỉ bao phủ tracking code; không thêm index chỉ cho fixture nhỏ.

Các truy vấn status/date/customer/tracking tương ứng với index `admin_orders`, `admin_orders_by_date`, `customer_orders`, `delivery_tracking_code`. Xác nhận lựa chọn thật bằng `POST /<database>/_explain` hoặc Fauxton trước khi thuyết trình.

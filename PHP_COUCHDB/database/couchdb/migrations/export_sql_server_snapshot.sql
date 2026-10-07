/*
 * Read-only snapshot query for scripts/export_sql_server_snapshot.ps1.
 * The PowerShell wrapper runs this query inside a SNAPSHOT transaction and
 * writes one UTF-8 JSON object. Legacy password columns are intentionally
 * omitted: the PHP app must provision credentials separately.
 */
IF (SELECT snapshot_isolation_state FROM sys.databases WHERE database_id = DB_ID()) <> 1
    THROW 51000, 'ALLOW_SNAPSHOT_ISOLATION must be ON before exporting a consistent snapshot.', 1;

SELECT
    1 AS format_version,
    CONVERT(varchar(33), SYSUTCDATETIME(), 127) + 'Z' AS exported_at_utc,
    DB_NAME() AS source_database,
    JSON_QUERY((SELECT MaKH AS legacy_id, TenDangNhap AS username, HoTen AS full_name,
                       SoDienThoai AS phone, Email AS email, DiaChi AS address, TrangThai AS active
                FROM dbo.KHACH_HANG ORDER BY MaKH FOR JSON PATH, INCLUDE_NULL_VALUES)) AS customers,
    JSON_QUERY((SELECT MaNV AS legacy_id, TenDangNhap AS username, HoTen AS full_name,
                       ChucVu AS job_title, TrangThai AS active
                FROM dbo.NHAN_VIEN ORDER BY MaNV FOR JSON PATH, INCLUDE_NULL_VALUES)) AS staff,
    JSON_QUERY((SELECT MaDanhMuc AS legacy_id, TenDanhMuc AS name, TrangThai AS active
                FROM dbo.DANH_MUC ORDER BY MaDanhMuc FOR JSON PATH, INCLUDE_NULL_VALUES)) AS categories,
    JSON_QUERY((SELECT MaKichThuoc AS legacy_id, TenKichThuoc AS name, TrangThai AS active
                FROM dbo.KICH_THUOC ORDER BY MaKichThuoc FOR JSON PATH, INCLUDE_NULL_VALUES)) AS sizes,
    JSON_QUERY((SELECT MaSP AS legacy_id, MaDanhMuc AS category_id, TenSanPham AS name,
                       MoTa AS description, TrangThai AS active
                FROM dbo.SAN_PHAM ORDER BY MaSP FOR JSON PATH, INCLUDE_NULL_VALUES)) AS products,
    JSON_QUERY((SELECT MaCTSP AS legacy_id, MaSP AS product_id, MaKichThuoc AS size_id,
                       GiaBan AS price, SoLuong AS stock, TrangThai AS active
                FROM dbo.CHI_TIET_SP ORDER BY MaSP, MaCTSP FOR JSON PATH, INCLUDE_NULL_VALUES)) AS variants,
    JSON_QUERY((SELECT MaHinhAnh AS legacy_id, MaSP AS product_id, DuongDan AS path,
                       LaAnhChinh AS is_primary, TrangThai AS active
                FROM dbo.HINH_ANH_SP ORDER BY MaSP, MaHinhAnh FOR JSON PATH, INCLUDE_NULL_VALUES)) AS images,
    JSON_QUERY((SELECT MaCode AS code, GiaTri AS value, LoaiGiamGia AS discount_type,
                       SoLuong AS remaining_quantity, TrangThai AS active
                FROM dbo.MA_GIAM_GIA ORDER BY MaCode FOR JSON PATH, INCLUDE_NULL_VALUES)) AS vouchers,
    JSON_QUERY((SELECT MaPTVC AS code, TenPTVC AS name, GiaVanChuyen AS fee, TrangThai AS active
                FROM dbo.PHUONG_THUC_VAN_CHUYEN ORDER BY MaPTVC FOR JSON PATH, INCLUDE_NULL_VALUES)) AS shipping_methods,
    JSON_QUERY((SELECT MaKH AS customer_id, MaCTSP AS variant_id, SoLuong AS quantity
                FROM dbo.GIO_HANG ORDER BY MaKH, MaCTSP FOR JSON PATH, INCLUDE_NULL_VALUES)) AS carts,
    JSON_QUERY((SELECT MaDonHang AS legacy_id, MaKH AS customer_id, MaNV AS staff_id,
                       TenNguoiNhan AS receiver_name, SoDienThoai AS receiver_phone, Email AS receiver_email,
                       MaCode AS voucher_code, MaPTVC AS shipping_code, NgayDat AS ordered_at,
                       NgayGiaoDuKien AS estimated_delivery_at, DiaChiGiao AS shipping_address, GhiChu AS note,
                       TongSoLuong AS stored_quantity, TongTien AS stored_subtotal, GiamGia AS stored_discount,
                       PhiVanChuyen AS stored_shipping_fee, ThanhTien AS stored_grand_total,
                       PhuongThucThanhToan AS payment_method, DaThanhToan AS is_paid,
                       TrangThaiDonHang AS legacy_status, TrangThai AS active
                FROM dbo.DON_HANG ORDER BY MaDonHang FOR JSON PATH, INCLUDE_NULL_VALUES)) AS orders,
    JSON_QUERY((SELECT MaDonHang AS order_id, MaCTSP AS variant_id, SoLuong AS quantity,
                       DonGia AS unit_price, ThanhTien AS stored_line_total, TrangThai AS active
                FROM dbo.CHI_TIET_DON_HANG ORDER BY MaDonHang, MaCTSP FOR JSON PATH, INCLUDE_NULL_VALUES)) AS order_items,
    JSON_QUERY((SELECT MaDanhGia AS legacy_id, MaSP AS product_id, MaKH AS customer_id,
                       TenKhachHang AS reviewer_name, SoSao AS rating, NoiDung AS content, TrangThai AS active
                FROM dbo.DANH_GIA ORDER BY MaDanhGia FOR JSON PATH, INCLUDE_NULL_VALUES)) AS reviews
FOR JSON PATH, WITHOUT_ARRAY_WRAPPER, INCLUDE_NULL_VALUES;

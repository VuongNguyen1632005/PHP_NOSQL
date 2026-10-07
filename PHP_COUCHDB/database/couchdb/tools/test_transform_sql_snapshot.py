import json
import unittest
from pathlib import Path

from .validate_sql_snapshot import TABLES
from .transform_sql_snapshot import transform_snapshot


def valid_snapshot():
    payload = {"format_version": 1, "source_database": "ShopQuanAo", "exported_at_utc": "2026-10-07T00:00:00Z"}
    payload.update({table: [] for table in TABLES})
    payload["customers"] = [{"legacy_id": "KH001", "username": "buyer", "full_name": "Buyer", "phone": "0900", "email": "buyer@example.test", "address": "Address", "active": True}]
    payload["staff"] = [{"legacy_id": "NV001", "username": "Manager", "full_name": "Manager", "job_title": "Quản lý", "active": True}]
    payload["categories"] = [{"legacy_id": "A", "name": "Áo", "active": True}]
    payload["sizes"] = [{"legacy_id": "S", "name": "S", "active": True}]
    payload["products"] = [{"legacy_id": "A01", "category_id": "A", "name": "Shirt", "description": "Description", "active": True}]
    payload["variants"] = [{"legacy_id": "CTA01S", "product_id": "A01", "size_id": "S", "price": 100000, "stock": 3, "active": True}]
    payload["images"] = [{"legacy_id": "IMG001", "product_id": "A01", "path": "assets/img/shirt.jpg", "is_primary": True, "active": True}]
    payload["vouchers"] = [{"code": "SAVE", "value": 10, "discount_type": "percent", "remaining_quantity": 3, "active": True}]
    payload["shipping_methods"] = [{"code": "BD", "name": "Standard", "fee": 20000, "active": True}]
    payload["carts"] = [{"customer_id": "KH001", "variant_id": "CTA01S", "quantity": 1}]
    payload["orders"] = [{
        "legacy_id": "DH0001", "customer_id": "KH001", "staff_id": "NV001", "receiver_name": "Buyer",
        "receiver_phone": "0900", "receiver_email": "buyer@example.test", "voucher_code": "SAVE", "shipping_code": "BD",
        "ordered_at": "2026-10-07T10:00:00", "estimated_delivery_at": None, "shipping_address": "Address", "note": None,
        "stored_quantity": 1, "stored_subtotal": 100000, "stored_discount": 10000, "stored_shipping_fee": 20000,
        "stored_grand_total": 110000, "payment_method": "Thanh toán khi nhận hàng", "is_paid": True,
        "legacy_status": "Chờ xác nhận", "active": True,
    }]
    payload["order_items"] = [{"order_id": "DH0001", "variant_id": "CTA01S", "quantity": 1, "unit_price": 100000, "stored_line_total": 100000, "active": True}]
    payload["reviews"] = [{"legacy_id": "RV001", "product_id": "A01", "customer_id": "KH001", "reviewer_name": "Buyer", "rating": 5, "content": "Good", "active": True}]
    return payload


class SnapshotTransformTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        path = Path(__file__).resolve().parents[1] / "design-docs" / "domain_validation.json"
        cls.validator = json.loads(path.read_text(encoding="utf-8"))

    def test_transforms_all_entities_and_never_copies_credentials(self):
        result = transform_snapshot(valid_snapshot(), self.validator)
        docs = {doc["_id"]: doc for doc in result["docs"]}
        self.assertEqual(result["format_version"], 2)
        self.assertEqual(docs["customer:KH001"]["auth"]["requires_password_reset"], True)
        self.assertIsNone(docs["customer:KH001"]["auth"]["password_hash"])
        self.assertEqual(docs["staff:NV001"]["role"], "manager")
        self.assertEqual(docs["staff:NV001"]["auth"]["username"], "manager")
        self.assertEqual(docs["product:A01"]["variants"][0]["variant_id"], "CTA01S")
        self.assertEqual(docs["cart:KH001"]["items"][0]["unit_price"], 100000)
        order = docs["order:DH0001"]
        self.assertEqual(order["status"], "pending")
        self.assertEqual(order["payment"]["status"], "paid")
        self.assertTrue(order["meta"]["payment_timestamp_unavailable"])
        self.assertEqual(docs["review:RV001"]["product_id"], "A01")
        self.assertFalse(any("password" in key.casefold() for doc in result["docs"] for key in doc))

    def test_reconciliation_warning_blocks_transform(self):
        payload = valid_snapshot()
        payload["orders"][0]["stored_grand_total"] = 109000
        with self.assertRaisesRegex(ValueError, "reconciliation warnings"):
            transform_snapshot(payload, self.validator)

    def test_unknown_legacy_payment_method_is_rejected(self):
        payload = valid_snapshot()
        payload["orders"][0]["payment_method"] = "Crypto"
        with self.assertRaisesRegex(ValueError, "unsupported legacy value"):
            transform_snapshot(payload, self.validator)


if __name__ == "__main__":
    unittest.main()

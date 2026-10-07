import unittest
from decimal import Decimal

from .validate_sql_snapshot import TABLES, validate_snapshot


def valid_snapshot():
    payload = {"format_version": 1, "source_database": "ShopQuanAo"}
    payload.update({table: [] for table in TABLES})
    payload["customers"] = [{"legacy_id": "KH001", "username": "buyer", "full_name": "Private Name"}]
    payload["categories"] = [{"legacy_id": "A", "name": "Áo"}]
    payload["sizes"] = [{"legacy_id": "S", "name": "S"}]
    payload["products"] = [{"legacy_id": "A01", "category_id": "A", "name": "Áo"}]
    payload["variants"] = [{"legacy_id": "CTA01S", "product_id": "A01", "size_id": "S", "price": 100000, "stock": 3}]
    payload["shipping_methods"] = [{"code": "BD", "fee": 20000}]
    payload["orders"] = [{
        "legacy_id": "DH0001", "customer_id": "KH001", "shipping_code": "BD", "legacy_status": "Chờ xác nhận",
        "ordered_at": "2026-10-07T10:00:00", "stored_quantity": 1, "stored_subtotal": 100000,
        "stored_discount": 0, "stored_shipping_fee": 20000, "stored_grand_total": 120000, "is_paid": False,
    }]
    payload["order_items"] = [{"order_id": "DH0001", "variant_id": "CTA01S", "quantity": 1, "unit_price": 100000, "stored_line_total": 100000}]
    payload["reviews"] = [{"legacy_id": "RV000001", "product_id": "A01", "customer_id": "KH001", "rating": 5}]
    return payload


class SnapshotValidationTests(unittest.TestCase):
    def test_valid_snapshot_passes_without_printing_sensitive_values(self):
        counts, issues = validate_snapshot(valid_snapshot())
        self.assertEqual(counts["products"], 1)
        self.assertEqual(issues, [])

    def test_orphan_and_fractional_vnd_are_errors(self):
        payload = valid_snapshot()
        payload["variants"][0]["price"] = Decimal("100000.50")
        payload["products"][0]["category_id"] = "missing"
        _, issues = validate_snapshot(payload)
        codes = {code for _, code in issues}
        self.assertIn("variants[0].price_fractional_vnd", codes)
        self.assertIn("products[0].category_id_orphan_categories", codes)

    def test_historical_reconciliation_difference_is_a_warning(self):
        payload = valid_snapshot()
        payload["orders"][0]["stored_grand_total"] = 119000
        _, issues = validate_snapshot(payload)
        self.assertIn(("warning", "orders[0].grand_total_differs_from_formula"), issues)

    def test_legacy_password_field_is_rejected(self):
        payload = valid_snapshot()
        payload["customers"][0]["password_hash"] = "should-not-be-exported"
        _, issues = validate_snapshot(payload)
        self.assertIn(("error", "customers[0].credential_field_present"), issues)


if __name__ == "__main__":
    unittest.main()

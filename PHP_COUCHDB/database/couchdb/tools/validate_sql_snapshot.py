#!/usr/bin/env python3
"""Preflight a read-only SQL Server JSON snapshot before any CouchDB transform."""

from __future__ import annotations

import argparse
import json
import sys
from collections import Counter
from datetime import datetime
from decimal import Decimal, InvalidOperation
from pathlib import Path
from typing import Any


TABLES = (
    "customers", "staff", "categories", "sizes", "products", "variants", "images",
    "vouchers", "shipping_methods", "carts", "orders", "order_items", "reviews",
)
PRIMARY_KEYS = {
    "customers": "legacy_id", "staff": "legacy_id", "categories": "legacy_id", "sizes": "legacy_id",
    "products": "legacy_id", "variants": "legacy_id", "images": "legacy_id", "vouchers": "code",
    "shipping_methods": "code", "orders": "legacy_id", "reviews": "legacy_id",
}
ORDER_STATUSES = {"Chờ xác nhận", "Đang giao", "Hoàn tất", "Hủy"}


def validate_snapshot(payload: Any, asset_root: Path | None = None) -> tuple[dict[str, int], list[tuple[str, str]]]:
    issues: list[tuple[str, str]] = []
    if not isinstance(payload, dict) or payload.get("format_version") != 1:
        return {}, [("error", "metadata.format_version_invalid")]
    if not isinstance(payload.get("source_database"), str) or not payload["source_database"]:
        issues.append(("error", "metadata.source_database_missing"))

    tables: dict[str, list[dict[str, Any]]] = {}
    for table in TABLES:
        rows = payload.get(table)
        if not isinstance(rows, list) or any(not isinstance(row, dict) for row in rows):
            issues.append(("error", f"table.{table}.missing_or_invalid"))
            tables[table] = []
        else:
            tables[table] = rows

    counts = {table: len(rows) for table, rows in tables.items()}
    ids: dict[str, set[str]] = {}
    indexes: dict[str, dict[str, int]] = {}
    for table, key_field in PRIMARY_KEYS.items():
        seen: dict[str, int] = {}
        for row_index, row in enumerate(tables[table]):
            value = row.get(key_field)
            if value is None or str(value).strip() == "":
                issues.append(("error", f"{table}[{row_index}].{key_field}_missing"))
                continue
            normalized = str(value)
            if normalized in seen:
                issues.append(("error", f"{table}[{row_index}].duplicate_{key_field}"))
            else:
                seen[normalized] = row_index
        indexes[table] = seen
        ids[table] = set(seen)

    # SQL allows customer and staff username constraints separately. The app login
    # index is shared, so a collision across either table would be ambiguous.
    usernames: dict[str, tuple[str, int]] = {}
    for table in ("customers", "staff"):
        for row_index, row in enumerate(tables[table]):
            username = row.get("username")
            if not isinstance(username, str) or not username.strip():
                issues.append(("error", f"{table}[{row_index}].username_missing"))
                continue
            normalized = username.strip().casefold()
            if normalized in usernames:
                issues.append(("error", f"{table}[{row_index}].duplicate_login_username"))
            else:
                usernames[normalized] = (table, row_index)

    def fk(table: str, row_index: int, field: str, target_table: str, nullable: bool = False) -> None:
        value = tables[table][row_index].get(field)
        if value is None and nullable:
            return
        if value is None or str(value) not in ids.get(target_table, set()):
            issues.append(("error", f"{table}[{row_index}].{field}_orphan_{target_table}"))

    def integer_amount(table: str, row_index: int, field: str, *, positive: bool = False) -> Decimal | None:
        value = tables[table][row_index].get(field)
        try:
            amount = Decimal(str(value))
        except (InvalidOperation, ValueError):
            issues.append(("error", f"{table}[{row_index}].{field}_invalid"))
            return None
        if not amount.is_finite() or amount != amount.to_integral_value():
            issues.append(("error", f"{table}[{row_index}].{field}_fractional_vnd"))
            return None
        if amount < (1 if positive else 0):
            issues.append(("error", f"{table}[{row_index}].{field}_negative_or_zero"))
            return None
        return amount

    for i, row in enumerate(tables["products"]):
        fk("products", i, "category_id", "categories")
    variant_pairs: set[tuple[str, str]] = set()
    for i, row in enumerate(tables["variants"]):
        fk("variants", i, "product_id", "products")
        fk("variants", i, "size_id", "sizes")
        pair = (str(row.get("product_id")), str(row.get("size_id")))
        if pair in variant_pairs:
            issues.append(("error", f"variants[{i}].duplicate_product_size"))
        variant_pairs.add(pair)
        integer_amount("variants", i, "price")
        integer_amount("variants", i, "stock")

    for i, row in enumerate(tables["images"]):
        fk("images", i, "product_id", "products")
        path = row.get("path")
        if not isinstance(path, str) or not path.strip():
            issues.append(("error", f"images[{i}].path_missing"))
        elif asset_root is not None:
            candidate = (asset_root / path.replace("\\", "/")).resolve()
            try:
                candidate.relative_to(asset_root.resolve())
            except ValueError:
                issues.append(("error", f"images[{i}].path_outside_asset_root"))
            else:
                if not candidate.is_file():
                    issues.append(("error", f"images[{i}].file_missing"))

    for i, row in enumerate(tables["vouchers"]):
        integer_amount("vouchers", i, "value")
        if row.get("discount_type") not in {"percent", "cash", "shipping"}:
            issues.append(("error", f"vouchers[{i}].discount_type_invalid"))
        if not isinstance(row.get("remaining_quantity"), int) or row["remaining_quantity"] < 0:
            issues.append(("error", f"vouchers[{i}].remaining_quantity_invalid"))
    for i, row in enumerate(tables["shipping_methods"]):
        integer_amount("shipping_methods", i, "fee")

    for i, row in enumerate(tables["carts"]):
        fk("carts", i, "customer_id", "customers")
        fk("carts", i, "variant_id", "variants")
        if not isinstance(row.get("quantity"), int) or row["quantity"] <= 0:
            issues.append(("error", f"carts[{i}].quantity_invalid"))

    order_rows = {str(row.get("legacy_id")): row for row in tables["orders"]}
    order_sums: dict[str, Decimal] = Counter()
    order_quantities: dict[str, int] = Counter()
    for i, row in enumerate(tables["orders"]):
        fk("orders", i, "customer_id", "customers", nullable=True)
        fk("orders", i, "staff_id", "staff", nullable=True)
        fk("orders", i, "voucher_code", "vouchers", nullable=True)
        fk("orders", i, "shipping_code", "shipping_methods")
        if row.get("legacy_status") not in ORDER_STATUSES:
            issues.append(("error", f"orders[{i}].legacy_status_invalid"))
        ordered_at = row.get("ordered_at")
        if not isinstance(ordered_at, str):
            issues.append(("error", f"orders[{i}].ordered_at_missing"))
        else:
            try:
                datetime.fromisoformat(ordered_at.replace("Z", "+00:00"))
            except ValueError:
                issues.append(("error", f"orders[{i}].ordered_at_invalid"))
        for field in ("stored_subtotal", "stored_discount", "stored_shipping_fee", "stored_grand_total"):
            integer_amount("orders", i, field)
        if not isinstance(row.get("is_paid"), bool):
            issues.append(("error", f"orders[{i}].is_paid_invalid"))

    variant_rows = {str(row.get("legacy_id")): row for row in tables["variants"]}
    for i, row in enumerate(tables["order_items"]):
        fk("order_items", i, "order_id", "orders")
        fk("order_items", i, "variant_id", "variants")
        quantity = row.get("quantity")
        if not isinstance(quantity, int) or quantity <= 0:
            issues.append(("error", f"order_items[{i}].quantity_invalid"))
            continue
        price = integer_amount("order_items", i, "unit_price")
        integer_amount("order_items", i, "stored_line_total")
        order_id = str(row.get("order_id"))
        order_quantities[order_id] += quantity
        if price is not None:
            order_sums[order_id] += price * quantity

    # Historical totals are preserved rather than silently rewritten. Differences
    # are warnings requiring review, while fractional currency blocks conversion.
    for order_id, row in order_rows.items():
        order_index = indexes["orders"].get(order_id, 0)
        subtotal = _decimal_or_none(row.get("stored_subtotal"))
        discount = _decimal_or_none(row.get("stored_discount"))
        shipping = _decimal_or_none(row.get("stored_shipping_fee"))
        grand_total = _decimal_or_none(row.get("stored_grand_total"))
        if subtotal is not None and order_id in order_sums and subtotal != order_sums[order_id]:
            issues.append(("warning", f"orders[{order_index}].subtotal_differs_from_items"))
        expected_quantity = order_quantities.get(order_id)
        if expected_quantity is not None and row.get("stored_quantity") != expected_quantity:
            issues.append(("warning", f"orders[{order_index}].quantity_differs_from_items"))
        if None not in (subtotal, discount, shipping, grand_total):
            expected_total = max(Decimal(0), subtotal + shipping - discount)
            if grand_total != expected_total:
                issues.append(("warning", f"orders[{order_index}].grand_total_differs_from_formula"))

    for i, row in enumerate(tables["reviews"]):
        fk("reviews", i, "product_id", "products")
        fk("reviews", i, "customer_id", "customers", nullable=True)
        if not isinstance(row.get("rating"), int) or not 1 <= row["rating"] <= 5:
            issues.append(("error", f"reviews[{i}].rating_invalid"))

    # Password-shaped keys must never enter an export artifact.
    for table, rows in tables.items():
        for i, row in enumerate(rows):
            if any("password" in str(key).casefold() or str(key).casefold() in {"matkhau", "mat_khau"} for key in row):
                issues.append(("error", f"{table}[{i}].credential_field_present"))

    return counts, issues


def _decimal_or_none(value: Any) -> Decimal | None:
    try:
        amount = Decimal(str(value))
    except (InvalidOperation, ValueError):
        return None
    return amount if amount.is_finite() else None


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("snapshot", type=Path, help="SQL Server JSON snapshot created by the PowerShell exporter")
    parser.add_argument("--asset-root", type=Path, help="Optional ShopQuanAo_MVC root for checking image paths")
    parser.add_argument("--strict", action="store_true", help="Return failure when historical reconciliation warnings exist")
    args = parser.parse_args()

    try:
        payload = json.loads(args.snapshot.read_text(encoding="utf-8-sig"), parse_float=Decimal)
    except (OSError, json.JSONDecodeError) as exc:
        print(f"Could not read snapshot JSON: {exc}", file=sys.stderr)
        return 2

    counts, issues = validate_snapshot(payload, args.asset_root)
    print("SQL Server snapshot preflight (no data values or personal fields are printed)")
    for table in TABLES:
        print(f"{table}: {counts.get(table, 0)}")
    severities = Counter(severity for severity, _ in issues)
    print(f"Issues: {severities.get('error', 0)} errors, {severities.get('warning', 0)} reconciliation warnings")
    for severity, code in issues:
        print(f"{severity}: {code}")
    if severities.get("error", 0) or (args.strict and severities.get("warning", 0)):
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

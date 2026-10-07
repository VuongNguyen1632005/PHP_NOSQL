#!/usr/bin/env python3
"""Transform a validated SQL Server snapshot into CouchDB schema-v2 documents."""

from __future__ import annotations

import argparse
import json
import re
import sys
from collections import defaultdict
from pathlib import Path
from typing import Any

try:  # Support both `python -m ...` and direct script execution.
    from .validate_sql_snapshot import TABLES, validate_snapshot
except ImportError:  # pragma: no cover - exercised by direct CLI invocation
    from validate_sql_snapshot import TABLES, validate_snapshot


ROOT = Path(__file__).resolve().parents[4]
SCHEMA_VERSION = 2
STATUS_MAP = {
    "Chờ xác nhận": "pending",
    "Đang giao": "shipping",
    "Hoàn tất": "delivered",
    "Hủy": "cancelled",
}


def _text(value: Any, label: str, *, optional: bool = False) -> str | None:
    if optional and (value is None or (isinstance(value, str) and not value.strip())):
        return None
    if not isinstance(value, str) or not value.strip():
        raise ValueError(f"{label} is missing or invalid")
    return value.strip()


def _integer(value: Any, label: str) -> int:
    if isinstance(value, bool) or not isinstance(value, (int, float)) or int(value) != value:
        raise ValueError(f"{label} must be an integer")
    return int(value)


def _payment_method(value: Any) -> tuple[str, str]:
    label = _text(value, "orders.payment_method")
    normalized = label.casefold()
    if "nhận hàng" in normalized or "cod" in normalized:
        return "cod", label
    if "chuyển khoản" in normalized or "bank" in normalized:
        return "bank_transfer", label
    if "thẻ" in normalized or "card" in normalized or "visa" in normalized or "mastercard" in normalized:
        return "card", label
    raise ValueError("orders.payment_method contains an unsupported legacy value")


def _active(row: dict[str, Any]) -> bool:
    value = row.get("active")
    if not isinstance(value, bool):
        raise ValueError("snapshot active fields must be boolean")
    return value


def transform_snapshot(payload: dict[str, Any], validator: dict[str, Any]) -> dict[str, Any]:
    """Return deterministic v2 seed-shaped JSON without mutating the source."""
    _, issues = validate_snapshot(payload)
    errors = [code for severity, code in issues if severity == "error"]
    warnings = [code for severity, code in issues if severity == "warning"]
    if errors:
        raise ValueError("snapshot preflight failed: " + ", ".join(errors))
    if warnings:
        raise ValueError("snapshot has reconciliation warnings; resolve them before transforming: " + ", ".join(warnings))

    tables = {name: payload[name] for name in TABLES}
    customers = {str(row["legacy_id"]): row for row in tables["customers"]}
    staff = {str(row["legacy_id"]): row for row in tables["staff"]}
    categories = {str(row["legacy_id"]): row for row in tables["categories"]}
    sizes = {str(row["legacy_id"]): row for row in tables["sizes"]}
    products_by_id = {str(row["legacy_id"]): row for row in tables["products"]}
    variants_by_id = {str(row["legacy_id"]): row for row in tables["variants"]}
    shipping_by_code = {str(row["code"]): row for row in tables["shipping_methods"]}
    vouchers_by_code = {str(row["code"]): row for row in tables["vouchers"]}

    images_by_product: dict[str, list[dict[str, Any]]] = defaultdict(list)
    variants_by_product: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for row in tables["images"]:
        images_by_product[str(row["product_id"])].append(row)
    for row in tables["variants"]:
        variants_by_product[str(row["product_id"])].append(row)

    docs: list[dict[str, Any]] = [validator]
    source = f"SQL Server snapshot: {payload['source_database']}"

    for row in tables["customers"]:
        legacy_id = str(row["legacy_id"])
        email = _text(row.get("email"), f"customers.{legacy_id}.email", optional=True)
        docs.append({
            "_id": f"customer:{legacy_id}", "type": "customer", "schema_version": SCHEMA_VERSION,
            "legacy_id": legacy_id,
            "auth": {"username": _text(row.get("username"), f"customers.{legacy_id}.username").lower(), "password_hash": None, "requires_password_reset": True},
            "profile": {
                "name": _text(row.get("full_name"), f"customers.{legacy_id}.full_name"),
                "phone": _text(row.get("phone"), f"customers.{legacy_id}.phone", optional=True),
                "email": email,
                "addresses": ([{"label": "legacy", "address": _text(row.get("address"), f"customers.{legacy_id}.address")}]
                              if row.get("address") else []),
            },
            "active": _active(row),
            "meta": {"source": source, "source_tables": ["KHACH_HANG"], "requires_credential_provisioning": True},
        })

    for row in tables["staff"]:
        legacy_id = str(row["legacy_id"])
        job_title = _text(row.get("job_title"), f"staff.{legacy_id}.job_title", optional=True) or ""
        role = "manager" if re.search(r"manager|quản\s*lý|quan\s*ly", job_title, re.IGNORECASE) else "staff"
        docs.append({
            "_id": f"staff:{legacy_id}", "type": "staff", "schema_version": SCHEMA_VERSION,
            "legacy_id": legacy_id,
            "auth": {"username": _text(row.get("username"), f"staff.{legacy_id}.username").lower(), "password_hash": None, "requires_password_reset": True},
            "profile": {"name": _text(row.get("full_name"), f"staff.{legacy_id}.full_name")},
            "role": role, "active": _active(row),
            "meta": {"source": source, "source_tables": ["NHAN_VIEN"], "legacy_job_title": job_title, "requires_credential_provisioning": True},
        })

    for row in tables["products"]:
        legacy_id = str(row["legacy_id"])
        category_id = str(row["category_id"])
        variants = []
        for variant in variants_by_product.pop(legacy_id, []):
            variant_id = str(variant["legacy_id"])
            size_id = str(variant["size_id"])
            variants.append({
                "variant_id": variant_id, "size": _text(sizes[size_id].get("name"), f"sizes.{size_id}.name"),
                "price": _integer(variant["price"], f"variants.{variant_id}.price"),
                "stock": _integer(variant["stock"], f"variants.{variant_id}.stock"), "active": _active(variant),
            })
        images = []
        for image in images_by_product.pop(legacy_id, []):
            if not isinstance(image.get("is_primary"), bool):
                raise ValueError(f"image {image.get('legacy_id')} is_primary must be boolean")
            images.append({
                "legacy_id": str(image["legacy_id"]), "path": _text(image.get("path"), "images.path").replace("\\", "/"),
                "is_primary": image["is_primary"], "active": _active(image),
            })
        if not variants:
            raise ValueError(f"product {legacy_id} has no variants")
        if not images or sum(image["is_primary"] for image in images) != 1:
            raise ValueError(f"product {legacy_id} must have exactly one primary image")
        category = categories[category_id]
        docs.append({
            "_id": f"product:{legacy_id}", "type": "product", "schema_version": SCHEMA_VERSION,
            "legacy_id": legacy_id, "name": _text(row.get("name"), f"products.{legacy_id}.name"),
            "description": row.get("description"),
            "category": {"code": category_id, "name": _text(category.get("name"), f"categories.{category_id}.name")},
            "variants": variants, "images": images, "active": _active(row),
            "meta": {"source": source, "source_tables": ["SAN_PHAM", "DANH_MUC", "CHI_TIET_SP", "KICH_THUOC", "HINH_ANH_SP"]},
        })
    if variants_by_product or images_by_product:
        raise ValueError("snapshot contains orphan product variants or images")

    for row in tables["vouchers"]:
        code = str(row["code"])
        docs.append({
            "_id": f"voucher:{code}", "type": "voucher", "schema_version": SCHEMA_VERSION,
            "code": code, "value": _integer(row["value"], f"vouchers.{code}.value"),
            "discount_type": _text(row.get("discount_type"), f"vouchers.{code}.discount_type"),
            "remaining_quantity": _integer(row["remaining_quantity"], f"vouchers.{code}.remaining_quantity"),
            "active": _active(row), "meta": {"source": source, "source_tables": ["MA_GIAM_GIA"]},
        })

    for row in tables["shipping_methods"]:
        code = str(row["code"])
        docs.append({
            "_id": f"shipping_method:{code}", "type": "shipping_method", "schema_version": SCHEMA_VERSION,
            "code": code, "name": _text(row.get("name"), f"shipping_methods.{code}.name"),
            "fee": _integer(row["fee"], f"shipping_methods.{code}.fee"), "currency": "VND", "active": _active(row),
            "meta": {"source": source, "source_tables": ["PHUONG_THUC_VAN_CHUYEN"]},
        })

    cart_items: dict[str, dict[str, dict[str, Any]]] = defaultdict(dict)
    for row in tables["carts"]:
        customer_id, variant_id = str(row["customer_id"]), str(row["variant_id"])
        variant = variants_by_id[variant_id]
        product_id, size_id = str(variant["product_id"]), str(variant["size_id"])
        item = cart_items[customer_id].setdefault(variant_id, {
            "product_id": product_id, "variant_id": variant_id, "size": sizes[size_id]["name"],
            "quantity": 0, "unit_price": _integer(variant["price"], f"variants.{variant_id}.price"), "selected": True,
        })
        item["quantity"] += _integer(row["quantity"], f"carts.{customer_id}.{variant_id}.quantity")
    for customer_id, items in cart_items.items():
        docs.append({
            "_id": f"cart:{customer_id}", "type": "cart", "schema_version": SCHEMA_VERSION,
            "customer_id": customer_id, "items": list(items.values()), "updated_at": None,
            "meta": {"source": source, "source_tables": ["GIO_HANG"]},
        })

    items_by_order: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for row in tables["order_items"]:
        variant_id = str(row["variant_id"])
        variant = variants_by_id[variant_id]
        product_id, size_id = str(variant["product_id"]), str(variant["size_id"])
        items_by_order[str(row["order_id"])].append({
            "product_id": product_id, "product_name": _text(products_by_id[product_id].get("name"), f"products.{product_id}.name"),
            "variant_id": variant_id, "size": _text(sizes[size_id].get("name"), f"sizes.{size_id}.name"),
            "quantity": _integer(row["quantity"], f"order_items.{row['order_id']}.{variant_id}.quantity"),
            "unit_price": _integer(row["unit_price"], f"order_items.{row['order_id']}.{variant_id}.unit_price"),
            "line_total": _integer(row["stored_line_total"], f"order_items.{row['order_id']}.{variant_id}.stored_line_total"),
            "currency": "VND",
        })

    for row in tables["orders"]:
        legacy_id = str(row["legacy_id"])
        customer_id = str(row["customer_id"]) if row.get("customer_id") is not None else None
        customer = customers.get(customer_id) if customer_id else None
        shipping_code = str(row["shipping_code"])
        voucher_code = str(row["voucher_code"]) if row.get("voucher_code") is not None else None
        method, legacy_payment_label = _payment_method(row.get("payment_method"))
        status = STATUS_MAP[row["legacy_status"]]
        items = items_by_order.get(legacy_id, [])
        if not items:
            raise ValueError(f"order {legacy_id} has no line items")
        ordered_at = _text(row.get("ordered_at"), f"orders.{legacy_id}.ordered_at")
        docs.append({
            "_id": f"order:{legacy_id}", "type": "order", "schema_version": SCHEMA_VERSION, "legacy_id": legacy_id,
            "customer": ({"customer_id": customer_id, "name": customer.get("full_name"), "email": customer.get("email"), "phone": customer.get("phone")} if customer else None),
            "guest_order": customer_id is None,
            "assigned_staff_id": (str(row["staff_id"]) if row.get("staff_id") is not None else None),
            "receiver": {
                "name": _text(row.get("receiver_name"), f"orders.{legacy_id}.receiver_name"),
                "phone": _text(row.get("receiver_phone"), f"orders.{legacy_id}.receiver_phone"),
                "email": _text(row.get("receiver_email"), f"orders.{legacy_id}.receiver_email", optional=True),
                "address": _text(row.get("shipping_address"), f"orders.{legacy_id}.shipping_address"),
            },
            "items": items,
            "shipping": {"method_code": shipping_code, "method_name": shipping_by_code[shipping_code]["name"],
                         "fee": _integer(row["stored_shipping_fee"], f"orders.{legacy_id}.stored_shipping_fee"), "currency": "VND",
                         "tracking_code": None, "estimated_delivery_date": (str(row["estimated_delivery_at"])[:10] if row.get("estimated_delivery_at") else None)},
            "discount": {"code": voucher_code, "type": (vouchers_by_code[voucher_code]["discount_type"] if voucher_code else None),
                         "value": (_integer(vouchers_by_code[voucher_code]["value"], f"vouchers.{voucher_code}.value") if voucher_code else 0),
                         "amount": _integer(row["stored_discount"], f"orders.{legacy_id}.stored_discount"), "currency": "VND"},
            "payment": {"method": method, "legacy_label": legacy_payment_label,
                        "status": "paid" if row["is_paid"] else "unpaid", "paid": row["is_paid"]},
            "totals": {"total_quantity": _integer(row["stored_quantity"], f"orders.{legacy_id}.stored_quantity"),
                       "subtotal": _integer(row["stored_subtotal"], f"orders.{legacy_id}.stored_subtotal"),
                       "shipping_fee": _integer(row["stored_shipping_fee"], f"orders.{legacy_id}.stored_shipping_fee"),
                       "discount_amount": _integer(row["stored_discount"], f"orders.{legacy_id}.stored_discount"),
                       "grand_total": _integer(row["stored_grand_total"], f"orders.{legacy_id}.stored_grand_total"), "currency": "VND"},
            "status": status,
            "status_history": [{"status": status, "at": None, "source": "legacy_migration",
                                "note": "Legacy source stores current status only; original transition timestamp is unavailable."}],
            "note": row.get("note"), "ordered_at": ordered_at, "active": _active(row),
            "meta": {"source": source, "source_tables": ["DON_HANG", "CHI_TIET_DON_HANG", "KHACH_HANG", "MA_GIAM_GIA", "PHUONG_THUC_VAN_CHUYEN"],
                     "totals_recalculated": False, "payment_timestamp_unavailable": bool(row["is_paid"])},
        })

    for row in tables["reviews"]:
        legacy_id = str(row["legacy_id"])
        customer_id = str(row["customer_id"]) if row.get("customer_id") is not None else None
        reviewer_name = _text(row.get("reviewer_name"), f"reviews.{legacy_id}.reviewer_name", optional=True)
        if not reviewer_name and customer_id:
            reviewer_name = customers[customer_id]["full_name"]
        docs.append({
            "_id": f"review:{legacy_id}", "type": "review", "schema_version": SCHEMA_VERSION,
            "legacy_id": legacy_id, "product_id": str(row["product_id"]), "customer_id": customer_id,
            "reviewer_name": reviewer_name or "Khách hàng", "rating": _integer(row["rating"], f"reviews.{legacy_id}.rating"),
            "content": _text(row.get("content"), f"reviews.{legacy_id}.content", optional=True) or "", "active": _active(row),
            "meta": {"source": source, "source_tables": ["DANH_GIA"]},
        })

    return {"format_version": SCHEMA_VERSION, "source_database": payload["source_database"], "docs": docs}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("snapshot", type=Path, help="Preflighted SQL Server snapshot JSON")
    parser.add_argument("--output", type=Path, required=True, help="New schema-v2 JSON file; existing files are never overwritten")
    parser.add_argument("--asset-root", type=Path, help="Optional legacy asset root for image file verification")
    args = parser.parse_args()
    if args.output.exists():
        print("Refusing to overwrite existing output file", file=sys.stderr)
        return 2
    try:
        payload = json.loads(args.snapshot.read_text(encoding="utf-8-sig"))
        design_doc = json.loads((Path(__file__).resolve().parents[1] / "design-docs" / "domain_validation.json").read_text(encoding="utf-8"))
        _, issues = validate_snapshot(payload, args.asset_root)
        if any(level == "error" for level, _ in issues):
            raise ValueError("snapshot preflight contains errors; run validate_sql_snapshot.py for a safe summary")
        result = transform_snapshot(payload, design_doc)
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    except (OSError, json.JSONDecodeError, ValueError, KeyError, TypeError) as exc:
        print(f"Transform failed: {exc}", file=sys.stderr)
        return 2
    counts: dict[str, int] = defaultdict(int)
    for doc in result["docs"]:
        counts[doc.get("type", "design")] += 1
    print(f"Transformed {len(result['docs'])} documents for {result['source_database']} into {args.output}.")
    print("Counts: " + ", ".join(f"{kind}={count}" for kind, count in sorted(counts.items())))
    print("This creates an offline artifact only; it does not connect to CouchDB or import data.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

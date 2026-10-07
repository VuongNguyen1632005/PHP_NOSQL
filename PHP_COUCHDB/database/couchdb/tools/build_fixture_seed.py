#!/usr/bin/env python3
"""Build a CouchDB demo seed from this repository's checked-in SQL fixtures.

This deliberately does not copy legacy plaintext account passwords. It retains
the already document-shaped demo carts, orders, customers, staff, vouchers and
shipping methods, and rebuilds the complete product/review catalog from SQL.
It is a fixture builder, not a SQL Server database migration tool.
"""

from __future__ import annotations

import json
import re
import sys
from collections import Counter
from decimal import Decimal
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[4]
SQL_PATH = ROOT / "WEBTHOITRANG" / "3_DuLieu.sql"
LEGACY_PATH = ROOT / "bulk_docs.json"
ASSET_ROOT = ROOT / "WEBTHOITRANG" / "ShopQuanAo_MVC"
OUTPUT_PATH = Path(__file__).resolve().parents[1] / "seeds" / "retail_order_delivery.json"
DESIGN_DOC_PATH = Path(__file__).resolve().parents[1] / "design-docs" / "domain_validation.json"
SCHEMA_VERSION = 2
CATEGORY_NAMES = {"A": "Áo", "Q": "Quần", "G": "Giày"}


def split_sql_values(value: str) -> list[str]:
    """Split one SQL VALUES tuple, respecting Unicode and escaped apostrophes."""
    tokens: list[str] = []
    start = 0
    quoted = False
    i = 0
    while i < len(value):
        char = value[i]
        if char == "'":
            if quoted and i + 1 < len(value) and value[i + 1] == "'":
                i += 2
                continue
            quoted = not quoted
        elif char == "," and not quoted:
            tokens.append(value[start:i].strip())
            start = i + 1
        i += 1
    if quoted:
        raise ValueError("Unterminated SQL string literal in VALUES tuple")
    tokens.append(value[start:].strip())
    return tokens


def sql_scalar(token: str) -> Any:
    token = token.strip()
    if token.upper() == "NULL":
        return None
    if token[:1].upper() == "N" and token[1:2] == "'":
        token = token[1:]
    if token.startswith("'") and token.endswith("'"):
        return token[1:-1].replace("''", "'")
    if re.fullmatch(r"-?\d+", token):
        return int(token)
    if re.fullmatch(r"-?\d+\.\d+", token):
        return Decimal(token)
    raise ValueError(f"Unsupported SQL fixture literal: {token}")


def whole_vnd(value: Any, label: str) -> int:
    amount = Decimal(str(value))
    if amount != amount.to_integral_value():
        raise ValueError(f"{label} is fractional; resolve the VND conversion rule before exporting: {value}")
    return int(amount)


def read_insert_rows(sql: str, table: str) -> list[list[Any]]:
    match = re.search(
        rf"INSERT\s+INTO\s+{re.escape(table)}\s*\([^)]*\)\s*VALUES\s*",
        sql,
        flags=re.IGNORECASE,
    )
    if not match:
        raise ValueError(f"No INSERT VALUES statement found for {table}")

    rows: list[list[Any]] = []
    body = sql[match.end():]
    depth = 0
    quoted = False
    row_start: int | None = None
    i = 0
    while i < len(body):
        char = body[i]
        if char == "'":
            if quoted and i + 1 < len(body) and body[i + 1] == "'":
                i += 2
                continue
            quoted = not quoted
        elif not quoted:
            if char == "-" and i + 1 < len(body) and body[i + 1] == "-":
                newline = body.find("\n", i + 2)
                if newline < 0:
                    break
                i = newline + 1
                continue
            if char == "(":
                if depth == 0:
                    row_start = i + 1
                depth += 1
            elif char == ")":
                depth -= 1
                if depth < 0:
                    raise ValueError(f"Unexpected closing parenthesis in {table}")
                if depth == 0 and row_start is not None:
                    rows.append([sql_scalar(t) for t in split_sql_values(body[row_start:i])])
                    row_start = None
            elif char == ";" and depth == 0:
                break
        i += 1
    if quoted or depth != 0:
        raise ValueError(f"Unterminated SQL statement while reading {table}")
    return rows


def require_unique(values: list[str], label: str) -> None:
    duplicates = sorted(value for value, count in Counter(values).items() if count > 1)
    if duplicates:
        raise ValueError(f"Duplicate {label}: {', '.join(duplicates)}")


def build_seed() -> tuple[dict[str, Any], dict[str, int]]:
    sql = SQL_PATH.read_text(encoding="utf-8-sig")
    fixture = json.loads(LEGACY_PATH.read_text(encoding="utf-8-sig"))

    categories = {str(row[0]): str(row[1]) for row in read_insert_rows(sql, "DANH_MUC")}
    sizes = {str(row[0]): str(row[1]) for row in read_insert_rows(sql, "KICH_THUOC")}

    variants_by_product: dict[str, list[dict[str, Any]]] = {}
    for variant_id, product_id, size_id, price, stock, active in read_insert_rows(sql, "CHI_TIET_SP"):
        product_id, variant_id = str(product_id), str(variant_id)
        if str(size_id) not in sizes:
            raise ValueError(f"Variant {variant_id} refers to missing size {size_id}")
        variants_by_product.setdefault(product_id, []).append(
            {
                "variant_id": variant_id,
                "size": sizes[str(size_id)],
                "price": whole_vnd(price, f"Variant {variant_id} price"),
                "stock": int(stock),
                "active": bool(active),
            }
        )

    images_by_product: dict[str, list[dict[str, Any]]] = {}
    for image_id, product_id, path, primary, active in read_insert_rows(sql, "HINH_ANH_SP"):
        product_id, path = str(product_id), str(path).replace("\\", "/")
        full_path = ASSET_ROOT.joinpath(*Path(path).parts)
        if not full_path.is_file():
            raise ValueError(f"Image file is missing for {image_id}: {full_path}")
        images_by_product.setdefault(product_id, []).append(
            {
                "legacy_id": str(image_id),
                "path": path,
                "is_primary": bool(primary),
                "active": bool(active),
            }
        )

    products: list[dict[str, Any]] = []
    for product_id, category_id, name, description, active in read_insert_rows(sql, "SAN_PHAM"):
        product_id, category_id = str(product_id), str(category_id)
        if category_id not in CATEGORY_NAMES:
            raise ValueError(f"Unknown category {category_id} on product {product_id}")
        variants = variants_by_product.pop(product_id, [])
        images = images_by_product.pop(product_id, [])
        if not variants:
            raise ValueError(f"Product {product_id} has no variants")
        if not images or sum(item["is_primary"] for item in images) != 1:
            raise ValueError(f"Product {product_id} must have one primary image")
        products.append(
            {
                "_id": f"product:{product_id}",
                "type": "product",
                "schema_version": SCHEMA_VERSION,
                "legacy_id": product_id,
                "name": name,
                "description": description,
                "category": {"code": category_id, "name": categories.get(category_id, CATEGORY_NAMES[category_id])},
                "variants": variants,
                "images": images,
                "active": bool(active),
                "meta": {
                    "source": "WEBTHOITRANG legacy SQL fixture",
                    "source_tables": ["SAN_PHAM", "DANH_MUC", "CHI_TIET_SP", "KICH_THUOC", "HINH_ANH_SP"],
                },
            }
        )
    if variants_by_product or images_by_product:
        raise ValueError(
            "Fixture has variant/image rows for missing products: "
            f"variants={sorted(variants_by_product)}, images={sorted(images_by_product)}"
        )
    require_unique([d["_id"] for d in products], "product IDs")

    reviews: list[dict[str, Any]] = []
    for review_id, product_id, customer_id, reviewer, rating, content, active in read_insert_rows(sql, "DANH_GIA"):
        product_id = str(product_id)
        if product_id not in {d["legacy_id"] for d in products}:
            raise ValueError(f"Review {review_id} refers to missing product {product_id}")
        if not 1 <= int(rating) <= 5:
            raise ValueError(f"Review {review_id} has a rating outside 1..5")
        reviews.append(
            {
                "_id": f"review:{review_id}",
                "type": "review",
                "schema_version": SCHEMA_VERSION,
                "legacy_id": str(review_id),
                "product_id": product_id,
                "customer_id": str(customer_id) if customer_id is not None else None,
                "reviewer_name": str(reviewer),
                "rating": int(rating),
                "content": content,
                "active": bool(active),
                "meta": {"source": "WEBTHOITRANG legacy SQL fixture", "source_tables": ["DANH_GIA"]},
            }
        )
    require_unique([d["_id"] for d in reviews], "review IDs")

    retained = [doc for doc in fixture["docs"] if doc.get("type") != "product" and not doc.get("_id", "").startswith("_design/")]
    for doc in retained:
        doc["schema_version"] = SCHEMA_VERSION
        if "_meta" in doc:
            doc["meta"] = doc.pop("_meta")
        if doc.get("type") in {"customer", "staff"}:
            auth = doc.get("auth", {})
            # The checked-in SQL contains plaintext demo passwords. Never carry
            # them into a CouchDB fixture; an operator must provision a hash.
            auth.pop("password", None)
            auth.pop("note", None)
            auth["password_hash"] = None
            auth["requires_password_reset"] = True

    design = json.loads(DESIGN_DOC_PATH.read_text(encoding="utf-8-sig"))
    docs = retained + products + reviews + [design]
    require_unique([doc["_id"] for doc in docs], "document IDs")
    counts = dict(sorted(Counter(doc.get("type", "design") for doc in docs).items()))
    return {"docs": docs}, counts


def main() -> int:
    try:
        payload, counts = build_seed()
        OUTPUT_PATH.parent.mkdir(parents=True, exist_ok=True)
        OUTPUT_PATH.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    except (OSError, ValueError, json.JSONDecodeError) as exc:
        print(f"Cannot build CouchDB fixture seed: {exc}", file=sys.stderr)
        return 1
    print(f"Wrote {len(payload['docs'])} CouchDB documents to {OUTPUT_PATH}")
    for doc_type, count in counts.items():
        print(f"  {doc_type}: {count}")
    print("Legacy plaintext passwords are not included; demo accounts require local provisioning.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

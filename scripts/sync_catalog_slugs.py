#!/usr/bin/env python3
"""Normalize and persist slug field in all catalog JSON files."""
import json
from pathlib import Path

# === Paths and configuration ==================================================

ROOT = Path(__file__).resolve().parents[1]
CATALOGS = [
    ROOT / "data" / "batteries.json",
    ROOT / "data" / "copters.json",
    ROOT / "data" / "water.json",
]


# === Slug normalization =======================================================
def slugify(value: str) -> str:
    value = (value or "").strip().lower()
    out = []
    prev_dash = False
    for ch in value:
        if ch.isalnum() or ch in {"_", "-"}:
            out.append(ch)
            prev_dash = False
        else:
            if not prev_dash:
                out.append("-")
            prev_dash = True
    slug = "".join(out).strip("-")
    return slug or "item"


# === File synchronization =====================================================
def sync_file(path: Path) -> tuple[int, int]:
    items = json.loads(path.read_text(encoding="utf-8"))
    changed = 0
    for idx, item in enumerate(items):
        source = str(item.get("slug") or item.get("id") or item.get("title") or f"item-{idx+1}")
        normalized = slugify(source)
        if item.get("slug") != normalized:
            item["slug"] = normalized
            changed += 1
    if changed:
        path.write_text(json.dumps(items, ensure_ascii=False, indent=4) + "\n", encoding="utf-8")
    return len(items), changed


# === Core flow ================================================================
def main() -> None:
    total_items = 0
    total_changed = 0
    for path in CATALOGS:
        count, changed = sync_file(path)
        total_items += count
        total_changed += changed
        print(f"{path.name}: items={count}, changed={changed}")
    print(f"Total items={total_items}, changed={total_changed}")


if __name__ == "__main__":
    main()

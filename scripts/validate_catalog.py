#!/usr/bin/env python3
"""Smoke checks for catalog ids/slugs, sitemap coverage and JSON wiring."""
import json
import xml.etree.ElementTree as ET
from pathlib import Path

# === Paths and configuration ==================================================

ROOT = Path(__file__).resolve().parents[1]
CATALOGS = {
    "batteries": ROOT / "data" / "batteries.json",
    "copters": ROOT / "data" / "copters.json",
    "water": ROOT / "data" / "water.json",
}
BASE_URL = "https://aplus-charisma.ru"

PAGE_EXPECTATIONS = {
    "index.html": [
        "const categories = ['copters', 'batteries', 'water'];",
        "fetch(`/data/${categoryKey}.json`, { cache: 'no-store' })",
    ],
    "water/index.html": ["fetch('/data/water.json', { cache: 'no-store' })"],
    "batteries/index.html": ["fetch('/data/batteries.json', { cache: 'no-store' })"],
    "copters/index.html": ["fetch('/data/copters.json', { cache: 'no-store' })"],
}

ADMIN_EXPECTATIONS = {
    "admin/index.html": [
        '<option value="batteries">',
        '<option value="copters">',
        '<option value="water">',
        "api('save', { method: 'POST', body: JSON.stringify({ category: state.category, items: state.items }) });",
    ],
    "admin/api.php": [
        "'batteries' => $root . '/data/batteries.json'",
        "'copters' => $root . '/data/copters.json'",
        "'water' => $root . '/data/water.json'",
        "if ($action === 'save' && $method === 'POST')",
        "syncJsonExports($db);",
    ],
}


# === Validation helpers =======================================================
def fail(msg: str):
    raise SystemExit(msg)


def require_contains(path: Path, snippets: list[str]):
    text = path.read_text(encoding="utf-8")
    for snippet in snippets:
        if snippet not in text:
            fail(f"Missing expected wiring in {path.relative_to(ROOT)}: {snippet}")


# === Core flow ================================================================
def main():
    expected_urls = set()

    for category, path in CATALOGS.items():
        items = json.loads(path.read_text(encoding="utf-8"))
        ids = [i.get("id") for i in items]
        if len(ids) != len(set(ids)):
            fail(f"Duplicate ids in {category}")

        slugs = []
        for item in items:
            slug = (item.get("slug") or item.get("id") or "").strip().lower()
            if not slug:
                fail(f"Missing slug/id in {category}: {item}")
            slugs.append(slug)
            expected_urls.add(f"{BASE_URL}/{category}/{slug}/")

        if len(slugs) != len(set(slugs)):
            fail(f"Duplicate slugs in {category}")

    tree = ET.parse(ROOT / "sitemap.xml")
    ns = {"sm": "http://www.sitemaps.org/schemas/sitemap/0.9"}
    locs = {loc.text for loc in tree.findall(".//sm:loc", ns)}

    missing = sorted(expected_urls - locs)
    if missing:
        fail(f"Missing product URLs in sitemap: {missing[:5]}")

    for rel_path, snippets in PAGE_EXPECTATIONS.items():
        require_contains(ROOT / rel_path, snippets)

    for rel_path, snippets in ADMIN_EXPECTATIONS.items():
        require_contains(ROOT / rel_path, snippets)

    print("Catalog smoke checks passed")


if __name__ == "__main__":
    main()
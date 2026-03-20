#!/usr/bin/env python3
"""Generate sitemap.xml from catalog JSON files."""
import json
from datetime import datetime, timezone
from pathlib import Path

# === Paths and configuration ==================================================

BASE_URL = "https://aplus-charisma.ru"
ROOT = Path(__file__).resolve().parents[1]

STATIC_PATHS = [
    ("/", "1.0"),
    ("/batteries/", "0.9"),
    ("/copters/", "0.9"),
    ("/water/", "0.9"),
]

CATALOGS = {
    "batteries": ROOT / "data" / "batteries.json",
    "copters": ROOT / "data" / "copters.json",
    "water": ROOT / "data" / "water.json",
}


# === URL builders =============================================================
def load_items(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


def normalize_slug(item: dict) -> str:
    return (item.get("slug") or item.get("id") or "").strip("/").lower()


def build_urls():
    seen = set()
    urls = []

    for path, priority in STATIC_PATHS:
        if path not in seen:
            seen.add(path)
            urls.append((path, priority))

    for category, file_path in CATALOGS.items():
        for item in load_items(file_path):
            slug = normalize_slug(item)
            if not slug:
                continue
            path = f"/{category}/{slug}/"
            if path in seen:
                continue
            seen.add(path)
            urls.append((path, "0.7"))

    return urls


# === XML rendering ============================================================
def render_sitemap(urls):
    lastmod = datetime.now(timezone.utc).date().isoformat()
    lines = [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
    ]
    for path, priority in sorted(urls, key=lambda item: item[0]):
        lines.extend(
            [
                "  <url>",
                f"    <loc>{BASE_URL}{path}</loc>",
                f"    <lastmod>{lastmod}</lastmod>",
                "    <changefreq>weekly</changefreq>",
                f"    <priority>{priority}</priority>",
                "  </url>",
            ]
        )
    lines.append("</urlset>")
    return "\n".join(lines) + "\n"


# === Core flow ================================================================
def main():
    urls = build_urls()
    (ROOT / "sitemap.xml").write_text(render_sitemap(urls), encoding="utf-8")
    print(f"Generated {len(urls)} urls")


if __name__ == "__main__":
    main()

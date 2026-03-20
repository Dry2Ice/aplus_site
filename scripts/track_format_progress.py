#!/usr/bin/env python3
"""Calculate structural-format readiness and optionally refresh FORMAT_PROGRESS.md."""

from __future__ import annotations

from pathlib import Path
import re

# === Paths and configuration ==================================================
ROOT = Path(__file__).resolve().parents[1]
PROGRESS_FILE = ROOT / "FORMAT_PROGRESS.md"

REQUIRED_MARKERS = {
    "index.html": [
        "SEO meta and page identity",
        "Structured data",
        "External UI dependencies",
        "Tailwind UI theme configuration",
        "Global visual system styles",
        "Static fallback for crawlers/no-js",
        "React mount point",
        "Root application component",
        "Application bootstrap",
    ],
    "batteries/index.html": [
        "SEO meta and page identity",
        "Structured data",
        "External UI dependencies",
        "Tailwind UI theme configuration",
        "Global visual system styles",
        "Early theme/accessibility bootstrap",
        "Static fallback for crawlers/no-js",
        "React mount point",
        "Catalog application (React runtime)",
        "Root application component",
        "Application bootstrap",
    ],
    "copters/index.html": [
        "SEO meta and page identity",
        "Structured data",
        "External UI dependencies",
        "Tailwind UI theme configuration",
        "Global visual system styles",
        "Early theme/accessibility bootstrap",
        "Static fallback for crawlers/no-js",
        "React mount point",
        "Root application component",
        "Application bootstrap",
    ],
    "water/index.html": [
        "SEO meta and page identity",
        "Structured data",
        "External UI dependencies",
        "Tailwind UI theme configuration",
        "Global visual system styles",
        "Early theme/accessibility bootstrap",
        "Static fallback for crawlers/no-js",
        "React mount point",
        "Root application component",
        "Application bootstrap",
    ],
    "admin/index.html": [
        "SEO meta and page identity",
        "Tailwind UI theme configuration",
        "Global visual system styles",
        "Admin panel runtime",
    ],
    "admin/api.php": [
        "Session and HTTP security headers",
        "Global constants",
        "Environment/bootstrap guards",
        "Request/session helpers",
        "Catalog normalization and file I/O",
        "Database bootstrap",
    ],
    "send-mail.php": [
        "Entry point and high-level metadata",
        "Response headers and baseline HTTP hardening",
        "Security configuration loading",
        "Security and request-origin helpers",
        "Mail event logging",
        "Guard rails and rejection flow",
        "CORS response policy",
        "Preflight and method validation",
        "Payload parsing and schema validation",
        "Form-type routing and anti-spam checks",
        "Rate limiting",
    ],
    "config/security.php": [
        "Security defaults for host/CORS policy",
        "allowed_hosts",
        "default_cors_origin",
    ],
    "admin/index.php": [
        "Admin entrypoint redirect",
        "header('Location: /admin/index.html'",
    ],
    "robots.txt": [
        "Robots policy",
        "Disallow: /admin/",
        "Sitemap: https://aplus-charisma.ru/sitemap.xml",
    ],
}

# === Progress computation ======================================================
def collect_stats() -> tuple[int, int, dict[str, list[str]]]:
    total = 0
    hit = 0
    missing: dict[str, list[str]] = {}

    for rel_path, markers in REQUIRED_MARKERS.items():
        text = (ROOT / rel_path).read_text(encoding="utf-8")
        missing_markers: list[str] = []
        for marker in markers:
            total += 1
            if marker in text:
                hit += 1
            else:
                missing_markers.append(marker)
        if missing_markers:
            missing[rel_path] = missing_markers

    return hit, total, missing


# === Markdown update ===========================================================
def update_progress_md(percent: int, missing: dict[str, list[str]]) -> None:
    text = PROGRESS_FILE.read_text(encoding="utf-8")
    text = re.sub(
        r"- Текущее покрытие по целевым ключевым файлам: \*\*~?\d+%\*\*\.",
        f"- Текущее покрытие по целевым ключевым файлам: **{percent}%**.",
        text,
    )

    if missing:
        unresolved = ", ".join(sorted(missing.keys()))
        remainder = f"- Осталось: добавить/уточнить секционные маркеры в `{unresolved}`."
    else:
        remainder = "- Осталось: секционные маркеры приведены к целевому минимуму, дальше — только косметическая полировка."

    text = re.sub(r"- Осталось: .*", remainder, text)
    PROGRESS_FILE.write_text(text, encoding="utf-8")


# === Core flow ================================================================
def main() -> None:
    hit, total, missing = collect_stats()
    percent = round((hit / total) * 100) if total else 100
    update_progress_md(percent, missing)

    print(f"Format markers: {hit}/{total} ({percent}%)")
    if missing:
        for rel_path, markers in missing.items():
            print(f"- {rel_path}: missing {markers}")


if __name__ == "__main__":
    main()

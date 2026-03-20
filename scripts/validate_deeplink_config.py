#!/usr/bin/env python3
"""Validate deep-link plumbing in category pages and rewrite rules."""
from pathlib import Path

# === Paths and configuration ==================================================

ROOT = Path(__file__).resolve().parents[1]

# === Static assertions ========================================================
CATEGORY_ASSERTS = {
    "batteries/index.html": [
        "const CATEGORY_BASE_PATH = '/batteries/'",
        "window.addEventListener('popstate'",
        "updateSeoForProduct(selectedProduct)",
        "window.history.pushState({}, '', productPath(p));",
    ],
    "copters/index.html": [
        "const CATEGORY_BASE_PATH = '/copters/'",
        "window.addEventListener('popstate'",
        "updateSeoForProduct(selectedProduct)",
        "window.history.pushState({}, '', productPath(p));",
    ],
    "water/index.html": [
        "const CATEGORY_BASE_PATH = '/water/'",
        "window.addEventListener('popstate'",
        "updateSeoForProduct(selectedProduct)",
        "window.history.pushState({}, '', productPath(p));",
    ],
}

HTACCESS_ASSERTS = [
    "RewriteRule ^batteries/.+ /batteries/index.html [L]",
    "RewriteRule ^copters/.+ /copters/index.html [L]",
    "RewriteRule ^water/.+ /water/index.html [L]",
]


# === Assertion runner =========================================================
def assert_contains(file_path: Path, patterns: list[str]) -> list[str]:
    text = file_path.read_text(encoding="utf-8")
    missing = [p for p in patterns if p not in text]
    return missing


# === Core flow ================================================================
def main() -> None:
    errors = []

    for rel, patterns in CATEGORY_ASSERTS.items():
        path = ROOT / rel
        missing = assert_contains(path, patterns)
        if missing:
            errors.append(f"{rel} missing: {missing}")

    htaccess = ROOT / ".htaccess"
    missing_ht = assert_contains(htaccess, HTACCESS_ASSERTS)
    if missing_ht:
        errors.append(f".htaccess missing: {missing_ht}")

    if errors:
        raise SystemExit("\n".join(errors))

    print("Deep-link config checks passed")


if __name__ == "__main__":
    main()

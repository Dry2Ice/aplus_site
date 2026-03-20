#!/usr/bin/env python3
"""Build script: copies project files into dist/ for production deployment."""

import os
import shutil
import sys

PROJECT_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DIST_DIR = os.path.join(PROJECT_ROOT, "dist")

# Files and directories to include in the production build
INCLUDE_ITEMS = [
    "index.html",
    "404.html",
    "robots.txt",
    "sitemap.xml",
    "map.webp",
    "aplus_logo.svg",
    "send-mail.php",
    "csrf-token.php",
    "api/",
    "admin/",
    "assets/",
    "batteries/",
    "copters/",
    "water/",
    "config/",
    "data/",
]

# Patterns to exclude when copying directories
EXCLUDE_PATTERNS = {
    ".git",
    ".gitignore",
    "node_modules",
    "__pycache__",
    ".env",
    ".env.local",
    ".DS_Store",
    "Thumbs.db",
    "*.pyc",
    "*.pyo",
    "*.log",
    "*.log.*",
}


def should_exclude(path: str) -> bool:
    basename = os.path.basename(path)
    for pattern in EXCLUDE_PATTERNS:
        if pattern.startswith("*"):
            if basename.endswith(pattern[1:]):
                return True
        elif basename == pattern:
            return True
    return False


def copy_tree_filtered(src: str, dst: str) -> None:
    """Copy directory tree, excluding unwanted patterns."""
    os.makedirs(dst, exist_ok=True)
    for item in os.listdir(src):
        s = os.path.join(src, item)
        d = os.path.join(dst, item)
        if should_exclude(s):
            continue
        if os.path.isdir(s):
            copy_tree_filtered(s, d)
        else:
            os.makedirs(os.path.dirname(d), exist_ok=True)
            shutil.copy2(s, d)


def main() -> int:
    # Clean and create dist directory
    if os.path.exists(DIST_DIR):
        shutil.rmtree(DIST_DIR)
    os.makedirs(DIST_DIR)

    copied = 0
    for item in INCLUDE_ITEMS:
        src = os.path.join(PROJECT_ROOT, item)
        dst = os.path.join(DIST_DIR, item)

        if not os.path.exists(src):
            print(f"  SKIP (not found): {item}", file=sys.stderr)
            continue

        if os.path.isdir(src):
            copy_tree_filtered(src, dst)
        else:
            os.makedirs(os.path.dirname(dst), exist_ok=True)
            shutil.copy2(src, dst)

        copied += 1
        print(f"  COPY: {item}")

    print(f"\nBuild complete: {copied} items copied to dist/")
    return 0


if __name__ == "__main__":
    sys.exit(main())

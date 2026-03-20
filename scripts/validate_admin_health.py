#!/usr/bin/env python3
"""Smoke-test admin health endpoint logic via PHP CLI include."""
import json
import subprocess
from pathlib import Path

# === Paths and configuration ==================================================

ROOT = Path(__file__).resolve().parents[1]
API_DIR = ROOT / "admin"

PHP_SNIPPET = r'''
$_GET['action'] = 'health';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = 'localhost';
chdir('%s');
include 'api.php';
'''


# === Core flow ================================================================
def main() -> None:
    cmd = [
        "php",
        "-r",
        PHP_SNIPPET % str(API_DIR).replace("'", "\\'"),
    ]
    result = subprocess.run(cmd, capture_output=True, text=True)
    if result.returncode != 0:
        raise SystemExit(f"php health check failed: {result.stderr.strip() or result.stdout.strip()}")

    raw = result.stdout.strip()
    if not raw:
        raise SystemExit("health endpoint returned empty output")

    try:
        payload = json.loads(raw)
    except json.JSONDecodeError as exc:
        raise SystemExit(f"health endpoint returned invalid json: {exc}: {raw[:200]}")

    required = [
        "ok",
        "time",
        "appEnv",
        "dbExists",
        "dbWritable",
        "sitemapExists",
        "mailLogExists",
        "mailSent24h",
        "mailFailed24h",
        "rateLimited24h",
        "mailFailureAlertThreshold",
        "mailAlertsOk",
    ]
    missing = [k for k in required if k not in payload]
    if missing:
        raise SystemExit(f"health payload missing keys: {missing}")

    if payload.get("ok") is not True:
        raise SystemExit(f"health payload not ok: {payload}")

    print("Admin health smoke check passed")


if __name__ == "__main__":
    main()

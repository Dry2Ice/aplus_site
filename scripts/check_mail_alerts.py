#!/usr/bin/env python3
"""Alert helper: fail if mail_failed count over threshold in last 24h."""

# === Paths and configuration ==================================================
import json
import os
import time
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
LOG_PATH = ROOT / "data" / "mail-events.log"


# === Core flow ================================================================
def main() -> None:
    threshold = int(os.getenv("APLUS_MAIL_FAILURE_ALERT_THRESHOLD", "0"))
    window_start = time.time() - 86400
    mail_failed = 0

    if LOG_PATH.exists():
        for line in LOG_PATH.read_text(encoding="utf-8").splitlines():
            if not line.strip():
                continue
            try:
                item = json.loads(line)
            except json.JSONDecodeError:
                continue
            ts_raw = item.get("at")
            if not ts_raw:
                continue
            try:
                dt = datetime.fromisoformat(str(ts_raw).replace('Z', '+00:00'))
                if dt.tzinfo is None:
                    dt = dt.replace(tzinfo=timezone.utc)
                ts = dt.timestamp()
            except Exception:
                continue
            if ts < window_start:
                continue
            if item.get("event") == "mail_failed":
                mail_failed += 1

    if mail_failed > threshold:
        raise SystemExit(
            f"ALERT: mail_failed in last 24h = {mail_failed}, threshold = {threshold}"
        )

    print(f"Mail alerts check passed: failed={mail_failed}, threshold={threshold}")


if __name__ == "__main__":
    main()

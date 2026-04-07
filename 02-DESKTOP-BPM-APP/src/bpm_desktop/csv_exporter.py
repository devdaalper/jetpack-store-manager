from __future__ import annotations

import csv
from datetime import datetime
from pathlib import Path
from typing import Iterable

from .constants import EXPORTS_DIR, MAX_CSV_ROWS_PER_FILE


def export_bpm_rows_chunked(rows: list[dict], chunk_size: int = MAX_CSV_ROWS_PER_FILE) -> list[Path]:
    chunk_size = max(1, min(int(chunk_size), MAX_CSV_ROWS_PER_FILE))
    EXPORTS_DIR.mkdir(parents=True, exist_ok=True)

    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    files: list[Path] = []

    for i in range(0, len(rows), chunk_size):
        chunk = rows[i : i + chunk_size]
        part = (i // chunk_size) + 1
        path = EXPORTS_DIR / f"bpm_export_{timestamp}_part{part:03d}.csv"

        with path.open("w", newline="", encoding="utf-8") as fh:
            writer = csv.DictWriter(
                fh,
                fieldnames=["path", "bpm", "source", "confidence", "analyzed_at"],
            )
            writer.writeheader()
            for row in chunk:
                writer.writerow(
                    {
                        "path": row.get("path", ""),
                        "bpm": row.get("bpm", 0),
                        "source": row.get("source", "desktop_app"),
                        "confidence": row.get("confidence", ""),
                        "analyzed_at": row.get("analyzed_at", ""),
                    }
                )
        files.append(path)

    return files

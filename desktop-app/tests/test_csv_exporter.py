"""Tests for the CSV export utility."""

from __future__ import annotations

import csv
from pathlib import Path
from unittest.mock import patch

from bpm_desktop.csv_exporter import export_bpm_rows_chunked


def test_export_creates_csv(tmp_path: Path):
    rows = [
        {"path": "a.mp3", "bpm": 120, "source": "path_pattern", "confidence": 0.72, "analyzed_at": "2026-04-07"},
        {"path": "b.wav", "bpm": 140, "source": "metadata_tbpm", "confidence": 0.93, "analyzed_at": "2026-04-07"},
    ]
    with patch("bpm_desktop.csv_exporter.EXPORTS_DIR", tmp_path):
        files = export_bpm_rows_chunked(rows, chunk_size=100)

    assert len(files) == 1
    with files[0].open() as fh:
        reader = csv.DictReader(fh)
        data = list(reader)
    assert len(data) == 2
    assert data[0]["bpm"] == "120"
    assert data[1]["path"] == "b.wav"


def test_export_chunks_by_size(tmp_path: Path):
    rows = [{"path": f"track_{i}.mp3", "bpm": 100 + i, "source": "test", "confidence": 0.8, "analyzed_at": ""} for i in range(5)]
    with patch("bpm_desktop.csv_exporter.EXPORTS_DIR", tmp_path):
        files = export_bpm_rows_chunked(rows, chunk_size=2)

    assert len(files) == 3  # 2 + 2 + 1


def test_export_empty_rows(tmp_path: Path):
    with patch("bpm_desktop.csv_exporter.EXPORTS_DIR", tmp_path):
        files = export_bpm_rows_chunked([], chunk_size=100)
    assert files == []

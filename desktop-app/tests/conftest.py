"""Shared fixtures for the BPM Desktop test suite."""

from __future__ import annotations

import tempfile
from pathlib import Path

import pytest

from bpm_desktop.db import AppDB


@pytest.fixture()
def tmp_db(tmp_path: Path) -> AppDB:
    """Return an AppDB backed by a temporary SQLite file."""
    return AppDB(db_path=tmp_path / "test.sqlite")

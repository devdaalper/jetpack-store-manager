"""Centralised logging configuration for the BPM Desktop application.

Call ``setup_logging()`` once during application startup (typically in
``main.py``).  Every module should then obtain its own logger via::

    import logging
    logger = logging.getLogger(__name__)

Logs go to:
  * **Console** (stderr) — coloured level + message, level defaults to INFO.
  * **Rotating file** — ``local_data/logs/bpm_desktop.log``, up to 5 MB per
    file, 3 backups kept.
"""

from __future__ import annotations

import logging
import sys
from logging.handlers import RotatingFileHandler

from bpm_desktop.constants import LOGS_DIR

_LOG_FILE = LOGS_DIR / "bpm_desktop.log"
_MAX_BYTES = 5 * 1024 * 1024  # 5 MB
_BACKUP_COUNT = 3
_FMT = "%(asctime)s  %(levelname)-8s  %(name)s  %(message)s"
_DATE_FMT = "%Y-%m-%d %H:%M:%S"


def setup_logging(*, level: int = logging.INFO) -> None:
    """Configure the root logger with console and rotating-file handlers."""
    LOGS_DIR.mkdir(parents=True, exist_ok=True)

    root = logging.getLogger()
    root.setLevel(level)

    # Avoid duplicate handlers on repeated calls.
    if root.handlers:
        return

    formatter = logging.Formatter(_FMT, datefmt=_DATE_FMT)

    # ── Console handler ──────────────────────────────────────────────
    console = logging.StreamHandler(sys.stderr)
    console.setLevel(level)
    console.setFormatter(formatter)
    root.addHandler(console)

    # ── Rotating file handler ────────────────────────────────────────
    file_handler = RotatingFileHandler(
        _LOG_FILE,
        maxBytes=_MAX_BYTES,
        backupCount=_BACKUP_COUNT,
        encoding="utf-8",
    )
    file_handler.setLevel(logging.DEBUG)  # file always captures everything
    file_handler.setFormatter(formatter)
    root.addHandler(file_handler)

    # Silence noisy third-party loggers.
    logging.getLogger("urllib3").setLevel(logging.WARNING)
    logging.getLogger("PySide6").setLevel(logging.WARNING)
    logging.getLogger("numba").setLevel(logging.WARNING)
    logging.getLogger("librosa").setLevel(logging.WARNING)

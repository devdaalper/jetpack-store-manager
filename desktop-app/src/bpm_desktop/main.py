"""Entry point for the JPSM BPM Desktop application."""

from __future__ import annotations

import logging
import sys

from PySide6.QtWidgets import QApplication

from .gui.main_window import MainWindow
from .logging_config import setup_logging
from .services.app_context import AppContext

logger = logging.getLogger(__name__)


def main() -> int:
    """Initialize logging, build the application context, and launch the GUI."""
    setup_logging()
    logger.info("Starting JPSM BPM Desktop")

    app = QApplication(sys.argv)
    app.setApplicationName("JPSM BPM Desktop")

    ctx = AppContext()
    window = MainWindow(ctx)
    window.show()
    return app.exec()


if __name__ == "__main__":
    raise SystemExit(main())


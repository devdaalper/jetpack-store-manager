from __future__ import annotations

import sys

from PySide6.QtWidgets import QApplication

from .gui.main_window import MainWindow
from .services.app_context import AppContext


def main() -> int:
    app = QApplication(sys.argv)
    app.setApplicationName("JPSM BPM Desktop")

    ctx = AppContext()
    window = MainWindow(ctx)
    window.show()
    return app.exec()


if __name__ == "__main__":
    raise SystemExit(main())


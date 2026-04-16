"""MainWindow — thin orchestrator that assembles the wizard steps."""

from __future__ import annotations

from PySide6.QtCore import QThread
from PySide6.QtWidgets import (
    QHBoxLayout,
    QLabel,
    QListWidget,
    QMainWindow,
    QMessageBox,
    QPushButton,
    QStackedWidget,
    QVBoxLayout,
    QWidget,
)

from ..constants import APP_NAME
from ..services.app_context import AppContext
from .step_backfill import BackfillStep
from .step_connections import ConnectionsStep
from .step_dryrun import DryRunStep
from .step_preflight import PreflightStep
from .step_publish import PublishStep
from .step_report import ReportStep
from .step_review import ReviewStep


class MainWindow(QMainWindow):
    def __init__(self, ctx: AppContext) -> None:
        super().__init__()
        self.ctx = ctx
        self.setWindowTitle(APP_NAME)
        self.resize(1280, 820)

        self.steps = [
            ConnectionsStep(ctx),
            PreflightStep(ctx),
            DryRunStep(ctx),
            BackfillStep(ctx),
            ReviewStep(ctx),
            PublishStep(ctx),
            ReportStep(ctx),
        ]

        self.step_titles = [
            "1. Conexiones",
            "2. Preflight",
            "3. Dry-run",
            "4. Backfill",
            "5. Revisi\u00f3n",
            "6. Publicaci\u00f3n",
            "7. Reporte",
        ]

        central = QWidget()
        root = QHBoxLayout(central)

        self.step_list = QListWidget()
        self.step_list.addItems(self.step_titles)
        self.step_list.setEnabled(False)
        root.addWidget(self.step_list, 1)

        right = QVBoxLayout()
        self.stack = QStackedWidget()
        for step in self.steps:
            step.completionChanged.connect(self._on_step_completion_changed)
            self.stack.addWidget(step)
        right.addWidget(self.stack, 1)

        nav = QHBoxLayout()
        self.back_btn = QPushButton("Atr\u00e1s")
        self.back_btn.clicked.connect(self._back)
        nav.addWidget(self.back_btn)

        self.next_btn = QPushButton("Siguiente")
        self.next_btn.clicked.connect(self._next)
        nav.addWidget(self.next_btn)

        nav.addStretch(1)
        self.step_status = QLabel("")
        nav.addWidget(self.step_status)
        right.addLayout(nav)

        root.addLayout(right, 5)
        self.setCentralWidget(central)

        self._set_current_index(0)
        self._refresh_nav()

    def _set_current_index(self, idx: int) -> None:
        idx = max(0, min(idx, len(self.steps) - 1))
        self.stack.setCurrentIndex(idx)
        self.step_list.setCurrentRow(idx)
        self.steps[idx].on_enter()
        self._refresh_nav()

    def _refresh_nav(self) -> None:
        idx = self.stack.currentIndex()
        current = self.steps[idx]

        self.back_btn.setEnabled(idx > 0)
        if idx >= len(self.steps) - 1:
            self.next_btn.setEnabled(False)
            self.next_btn.setText("Fin")
        else:
            self.next_btn.setEnabled(current.completed)
            self.next_btn.setText("Siguiente")

        self.step_status.setText("Completado" if current.completed else "Pendiente")
        self.step_status.setStyleSheet("color:#166534;" if current.completed else "color:#92400e;")

    def _on_step_completion_changed(self, _: bool) -> None:
        self._refresh_nav()

    def _back(self) -> None:
        self._set_current_index(self.stack.currentIndex() - 1)

    def _next(self) -> None:
        idx = self.stack.currentIndex()
        if idx >= len(self.steps) - 1:
            return
        if not self.steps[idx].completed:
            QMessageBox.warning(self, "Flujo estricto", "Debes completar el paso actual para avanzar.")
            return
        self._set_current_index(idx + 1)

    def closeEvent(self, event) -> None:  # type: ignore[override]
        thread_attrs = ("_thread", "_sync_thread", "_dry_thread", "_worker_thread")
        active_threads: list[QThread] = []
        for step in self.steps:
            for attr in thread_attrs:
                t = getattr(step, attr, None)
                if isinstance(t, QThread) and t.isRunning():
                    active_threads.append(t)

        if active_threads:
            for t in active_threads:
                t.requestInterruption()
                t.quit()
            for t in active_threads:
                t.wait(5000)
        super().closeEvent(event)

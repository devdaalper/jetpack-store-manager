"""Step 2 — Hardware and connectivity pre-flight checks."""

from __future__ import annotations

import time
from typing import Any

from PySide6.QtCore import QThread, QTimer
from PySide6.QtWidgets import (
    QLabel,
    QPlainTextEdit,
    QProgressBar,
    QPushButton,
    QVBoxLayout,
)

from ..preflight import PreflightRunner, preflight_passed
from ..services.app_context import AppContext
from ._base import BaseStep, PipelineWorker


class PreflightStep(BaseStep):
    def __init__(self, ctx: AppContext) -> None:
        super().__init__("Preflight", ctx)
        self._running = False
        self._thread: QThread | None = None
        self._worker: PipelineWorker | None = None
        self._started_at = 0.0
        self._last_progress_at = 0.0
        self._progress_base_text = "Pendiente"

        layout = QVBoxLayout(self)
        self.run_btn = QPushButton("Ejecutar preflight estricto")
        self.run_btn.clicked.connect(self._run)
        layout.addWidget(self.run_btn)

        self.progress = QProgressBar()
        self.progress.setRange(0, 8)
        self.progress.setValue(0)
        layout.addWidget(self.progress)

        self.progress_info = QLabel(self._progress_base_text)
        self.progress_info.setWordWrap(True)
        layout.addWidget(self.progress_info)

        self.output = QPlainTextEdit()
        self.output.setReadOnly(True)
        layout.addWidget(self.output, 1)

        self.status = QLabel("")
        layout.addWidget(self.status)

        self._activity_timer = QTimer(self)
        self._activity_timer.setInterval(1000)
        self._activity_timer.timeout.connect(self._on_activity_tick)

    def _run(self) -> None:
        if self._running or self._thread is not None:
            return
        self._running = True
        self.run_btn.setEnabled(False)
        self.output.setPlainText("")
        self.progress.setRange(0, 0)
        self._set_progress_base("Preflight iniciado...")
        self.status.setText("Ejecutando preflight...")
        self.status.setStyleSheet("color:#92400e;")
        now = time.monotonic()
        self._started_at = now
        self._last_progress_at = now
        self._activity_timer.start()

        runner = PreflightRunner(self.ctx.creds, ffmpeg_path=self.ctx.ffmpeg_path)
        self._thread = QThread()
        self._worker = PipelineWorker(task=runner.run, kwargs={})
        self._worker.moveToThread(self._thread)

        self._thread.started.connect(self._worker.run)
        self._worker.progress.connect(self._on_progress)
        self._worker.finished.connect(self._on_finished)
        self._worker.failed.connect(self._on_failed)
        self._worker.finished.connect(self._thread.quit)
        self._worker.failed.connect(self._thread.quit)
        self._thread.finished.connect(self._cleanup_worker)
        self._thread.start()

    def _on_progress(self, payload: dict[str, Any]) -> None:
        self._last_progress_at = time.monotonic()
        stage = str(payload.get("stage", "") or "")
        done = max(0, int(payload.get("done", 0) or 0))
        total = max(1, int(payload.get("total", 1) or 1))
        if stage == "start":
            self.progress.setRange(0, total)
            self.progress.setValue(0)
            self._set_progress_base("Preflight: iniciando chequeos...")
            return

        if stage == "check_done":
            step_name = str(payload.get("step_name", "") or "")
            ok = bool(payload.get("ok", False))
            details = str(payload.get("details", "") or "")
            emoji = "\u2705" if ok else "\u274c"
            self.progress.setRange(0, total)
            self.progress.setValue(min(done, total))
            self._set_progress_base(f"Chequeo {done}/{total}: {step_name}")
            self.output.appendPlainText(f"{emoji} {step_name}: {details}")
            return

        if stage == "done":
            self.progress.setRange(0, total)
            self.progress.setValue(min(done, total))
            self._set_progress_base("Preflight completado.")

    def _on_finished(self, checks_obj: object) -> None:
        checks = list(checks_obj or [])
        ok = preflight_passed(checks)
        self.ctx.state.preflight_passed = ok
        self.set_completed(ok)
        if ok:
            self.status.setText("Preflight aprobado.")
            self.status.setStyleSheet("color:#166534;")
        else:
            self.status.setText("Preflight fall\u00f3. Corrige los puntos en rojo para continuar.")
            self.status.setStyleSheet("color:#b91c1c;")

    def _on_failed(self, message: str) -> None:
        self.output.appendPlainText(f"ERROR preflight: {message}")
        self.status.setText("Preflight con error.")
        self.status.setStyleSheet("color:#b91c1c;")
        self.set_completed(False)
        self._set_progress_base("Preflight con error.")

    def _cleanup_worker(self) -> None:
        if self._worker is not None:
            self._worker.deleteLater()
        if self._thread is not None:
            self._thread.deleteLater()
        self._worker = None
        self._thread = None
        self._running = False
        self.run_btn.setEnabled(True)
        self._activity_timer.stop()
        self._refresh_progress_info()

    def _set_progress_base(self, text: str) -> None:
        self._progress_base_text = text.strip() or "Pendiente"
        self._refresh_progress_info()

    def _refresh_progress_info(self) -> None:
        text = self._progress_base_text
        if self._running:
            elapsed = max(0, int(time.monotonic() - self._started_at))
            idle = max(0, int(time.monotonic() - self._last_progress_at))
            text += f"\nTiempo: {elapsed}s"
            if idle >= 8:
                text += f" | sin cambios: {idle}s (sigue trabajando)"
        self.progress_info.setText(text)

    def _on_activity_tick(self) -> None:
        self._refresh_progress_info()

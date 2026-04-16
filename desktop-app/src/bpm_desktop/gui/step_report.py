"""Step 7 — Batch history, event log and rollback."""

from __future__ import annotations

import json
import time
from typing import Any

from PySide6.QtCore import QThread, QTimer
from PySide6.QtWidgets import (
    QComboBox,
    QGridLayout,
    QLabel,
    QMessageBox,
    QPlainTextEdit,
    QProgressBar,
    QPushButton,
    QVBoxLayout,
)

from ..services.app_context import AppContext
from ._base import BaseStep, PipelineWorker


class ReportStep(BaseStep):
    def __init__(self, ctx: AppContext) -> None:
        super().__init__("Reporte", ctx)
        self._running = False
        self._thread: QThread | None = None
        self._worker: PipelineWorker | None = None
        self._progress_base_text = "Pendiente"
        self._started_at = 0.0
        self._last_progress_at = 0.0
        self._current_batch_id = ""

        layout = QVBoxLayout(self)

        controls = QGridLayout()
        self.refresh_btn = QPushButton("Refrescar reporte")
        self.refresh_btn.clicked.connect(self.refresh)
        controls.addWidget(self.refresh_btn, 0, 0)

        controls.addWidget(QLabel("Batch API para rollback"), 0, 1)
        self.batch_selector = QComboBox()
        controls.addWidget(self.batch_selector, 0, 2)

        self.rollback_btn = QPushButton("Rollback lote seleccionado")
        self.rollback_btn.clicked.connect(self._rollback_selected_batch)
        controls.addWidget(self.rollback_btn, 0, 3)
        controls.setColumnStretch(4, 1)
        layout.addLayout(controls)

        self.progress = QProgressBar()
        self.progress.setRange(0, 1)
        self.progress.setValue(0)
        layout.addWidget(self.progress)

        self.progress_info = QLabel(self._progress_base_text)
        self.progress_info.setWordWrap(True)
        layout.addWidget(self.progress_info)

        self.status = QLabel("")
        layout.addWidget(self.status)

        self.output = QPlainTextEdit()
        self.output.setReadOnly(True)
        layout.addWidget(self.output, 1)

        self._activity_timer = QTimer(self)
        self._activity_timer.setInterval(1000)
        self._activity_timer.timeout.connect(self._on_activity_tick)

    def on_enter(self) -> None:
        self.refresh()
        self.set_completed(True)

    def refresh(self) -> None:
        if self._running:
            return
        self.progress.setRange(0, 1)
        self.progress.setValue(0)
        self._set_progress_base("Refrescando reporte...")
        metrics = self.ctx.db.get_metrics()
        api_batches = self.ctx.db.get_recent_publish_batches(limit=100, mode="api")
        self.batch_selector.blockSignals(True)
        self.batch_selector.clear()
        selectable = 0
        for row in api_batches:
            batch_id = str(row["batch_id"])
            status = str(row["status"])
            created_at = str(row["created_at"])
            if status not in ("applied", "duplicate"):
                continue
            self.batch_selector.addItem(f"{batch_id} [{status}] {created_at}", batch_id)
            selectable += 1
        self.batch_selector.blockSignals(False)
        self.rollback_btn.setEnabled(selectable > 0)

        events = self.ctx.db.load_last_event_rows(limit=50)
        out = ["Resumen global", json.dumps(metrics, ensure_ascii=False, indent=2), "", "Eventos recientes"]
        for event in events:
            out.append(f"- {event['created_at']} | {event['event_type']} | {event['payload_json']}")
        self.output.setPlainText("\n".join(out))
        self.progress.setValue(1)
        self._set_progress_base(f"Reporte actualizado. Lotes rollback disponibles: {selectable}")
        self.status.setText(f"Reporte actualizado. Eventos visibles: {len(events)}")
        self.status.setStyleSheet("color:#166534;")

    def _rollback_selected_batch(self) -> None:
        if self._running or self._thread is not None:
            return
        idx = self.batch_selector.currentIndex()
        if idx < 0:
            QMessageBox.warning(self, "Rollback", "No hay lotes API disponibles.")
            return

        batch_id = self.batch_selector.currentData()
        if not batch_id:
            QMessageBox.warning(self, "Rollback", "Batch inv\u00e1lido.")
            return

        if QMessageBox.question(
            self,
            "Confirmar rollback (1/2)",
            f"Se revertir\u00e1 completamente el batch {batch_id}. \u00bfContinuar?",
        ) != QMessageBox.StandardButton.Yes:
            return

        if QMessageBox.question(
            self,
            "Confirmar rollback (2/2)",
            "\u00daltima confirmaci\u00f3n: se restaurar\u00e1n BPM anteriores en WordPress. \u00bfEjecutar rollback?",
        ) != QMessageBox.StandardButton.Yes:
            return

        self._running = True
        self._current_batch_id = str(batch_id)
        self.refresh_btn.setEnabled(False)
        self.rollback_btn.setEnabled(False)
        self.batch_selector.setEnabled(False)
        self.progress.setRange(0, 0)
        self.status.setText(f"Rollback en curso: {self._current_batch_id}")
        self.status.setStyleSheet("color:#92400e;")
        self._set_progress_base(f"Rollback iniciado para batch {self._current_batch_id}...")
        now = time.monotonic()
        self._started_at = now
        self._last_progress_at = now
        self._activity_timer.start()

        self._thread = QThread()
        self._worker = PipelineWorker(
            task=self.ctx.pipeline.rollback_api_batch,
            kwargs={"batch_id": self._current_batch_id},
        )
        self._worker.moveToThread(self._thread)

        self._thread.started.connect(self._worker.run)
        self._worker.progress.connect(self._on_rollback_progress)
        self._worker.finished.connect(self._on_rollback_finished)
        self._worker.failed.connect(self._on_rollback_failed)
        self._worker.finished.connect(self._thread.quit)
        self._worker.failed.connect(self._thread.quit)
        self._thread.finished.connect(self._cleanup_worker)
        self._thread.start()

    def _on_rollback_progress(self, payload: dict[str, Any]) -> None:
        self._last_progress_at = time.monotonic()
        stage = str(payload.get("stage", "") or "")
        if stage == "start":
            self.progress.setRange(0, 1)
            self.progress.setValue(0)
            self._set_progress_base(f"Rollback ejecut\u00e1ndose: {self._current_batch_id}")
            return
        if stage == "done":
            self.progress.setRange(0, 1)
            self.progress.setValue(1)
            self._set_progress_base("Rollback completado.")

    def _on_rollback_finished(self, payload_obj: object) -> None:
        payload = dict(payload_obj or {})
        self.ctx.db.log_event("rollback_completed_ui", {"batch_id": self._current_batch_id, "payload": payload})
        self.output.appendPlainText("\nRollback:\n" + json.dumps(payload, ensure_ascii=False, indent=2))
        self.status.setText(f"Rollback completado: {self._current_batch_id}")
        self.status.setStyleSheet("color:#166534;")
        self._set_progress_base("Rollback completado.")
        self._running = False
        self._activity_timer.stop()
        self.refresh()

    def _on_rollback_failed(self, message: str) -> None:
        self.output.appendPlainText(f"ERROR rollback: {message}")
        self.status.setText(f"Error en rollback: {message}")
        self.status.setStyleSheet("color:#b91c1c;")
        self._set_progress_base("Rollback con error.")
        self._running = False
        self._activity_timer.stop()

    def _cleanup_worker(self) -> None:
        if self._worker is not None:
            self._worker.deleteLater()
        if self._thread is not None:
            self._thread.deleteLater()
        self._worker = None
        self._thread = None
        self.refresh_btn.setEnabled(True)
        self.batch_selector.setEnabled(True)
        self.rollback_btn.setEnabled(self.batch_selector.count() > 0)

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

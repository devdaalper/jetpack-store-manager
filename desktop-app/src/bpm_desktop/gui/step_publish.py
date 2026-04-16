"""Step 6 — Quality gate check and data publication (API or CSV)."""

from __future__ import annotations

import hashlib
import json
import time
from datetime import datetime
from typing import Any

from PySide6.QtCore import QThread, QTimer
from PySide6.QtWidgets import (
    QComboBox,
    QHBoxLayout,
    QLabel,
    QMessageBox,
    QPlainTextEdit,
    QProgressBar,
    QPushButton,
    QVBoxLayout,
)

from ..quality_gate import QualityGateResult
from ..services.app_context import AppContext
from ._base import BaseStep, PipelineWorker


class PublishStep(BaseStep):
    def __init__(self, ctx: AppContext) -> None:
        super().__init__("Publicaci\u00f3n", ctx)
        self._rows: list[dict] = []
        self._running = False
        self._publish_mode = "api"
        self._thread: QThread | None = None
        self._worker: PipelineWorker | None = None
        self._progress_base_text = "Pendiente"
        self._started_at = 0.0
        self._last_progress_at = 0.0

        layout = QVBoxLayout(self)

        controls = QHBoxLayout()
        self.mode = QComboBox()
        self.mode.addItems(["api", "csv"])
        controls.addWidget(QLabel("Modo"))
        controls.addWidget(self.mode)

        self.load_btn = QPushButton("Cargar candidatos + quality gate")
        self.load_btn.clicked.connect(self._load_candidates)
        controls.addWidget(self.load_btn)

        self.publish_btn = QPushButton("Publicar")
        self.publish_btn.clicked.connect(self._publish)
        self.publish_btn.setEnabled(False)
        controls.addWidget(self.publish_btn)
        controls.addStretch(1)
        layout.addLayout(controls)

        self.progress = QProgressBar()
        self.progress.setRange(0, 1)
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

    def _load_candidates(self) -> None:
        if self._running:
            return
        self._set_progress_base("Cargando candidatos...")
        self._rows = self.ctx.pipeline.get_publish_rows(limit=5000)
        gate: QualityGateResult = self.ctx.pipeline.evaluate_publish_quality(self._rows)
        self.progress.setRange(0, max(1, len(self._rows)))
        self.progress.setValue(len(self._rows))

        self.output.setPlainText(
            "Quality Gate:\n" + json.dumps(gate.metrics, ensure_ascii=False, indent=2)
        )
        if gate.passed and self._rows:
            self.publish_btn.setEnabled(True)
            self.status.setText(f"Gate OK. Filas listas: {len(self._rows)}")
            self.status.setStyleSheet("color:#166534;")
            self._set_progress_base(f"Gate OK. Filas listas: {len(self._rows)}")
        else:
            self.publish_btn.setEnabled(False)
            reasons = " | ".join(gate.reasons) if gate.reasons else "No hay filas publicables"
            self.status.setText(f"Gate FAIL: {reasons}")
            self.status.setStyleSheet("color:#b91c1c;")
            self._set_progress_base(f"Gate FAIL: {reasons}")

    def _publish(self) -> None:
        if self._running or self._thread is not None or not self._rows:
            return

        if QMessageBox.question(
            self,
            "Confirmar publicaci\u00f3n (1/2)",
            f"Se publicar\u00e1n {len(self._rows)} filas en modo {self.mode.currentText()}. \u00bfContinuar?",
        ) != QMessageBox.StandardButton.Yes:
            return

        if QMessageBox.question(
            self,
            "Confirmar publicaci\u00f3n (2/2)",
            "Última confirmaci\u00f3n: esta operaci\u00f3n aplica cambios en WordPress. \u00bfPublicar ahora?",
        ) != QMessageBox.StandardButton.Yes:
            return

        self._running = True
        self._publish_mode = self.mode.currentText()
        self.load_btn.setEnabled(False)
        self.publish_btn.setEnabled(False)
        self.mode.setEnabled(False)
        self.status.setText("Publicaci\u00f3n en curso...")
        self.status.setStyleSheet("color:#92400e;")
        now = time.monotonic()
        self._started_at = now
        self._last_progress_at = now
        self._activity_timer.start()
        self.progress.setRange(0, 0)
        self._set_progress_base(f"Publicaci\u00f3n iniciada en modo {self._publish_mode}...")

        if self._publish_mode == "csv":
            task = self.ctx.pipeline.export_csv
            kwargs: dict[str, Any] = {"rows": self._rows}
        else:
            task = self.ctx.pipeline.publish_api
            kwargs = {"profile": self.ctx.state.profile, "rows": self._rows}

        self._thread = QThread()
        self._worker = PipelineWorker(task=task, kwargs=kwargs)
        self._worker.moveToThread(self._thread)

        self._thread.started.connect(self._worker.run)
        self._worker.progress.connect(self._on_publish_progress)
        self._worker.finished.connect(self._on_publish_finished)
        self._worker.failed.connect(self._on_publish_failed)
        self._worker.finished.connect(self._thread.quit)
        self._worker.failed.connect(self._thread.quit)
        self._thread.finished.connect(self._cleanup_worker)
        self._thread.start()

    def _on_publish_progress(self, payload: dict[str, Any]) -> None:
        self._last_progress_at = time.monotonic()
        stage = str(payload.get("stage", "") or "")

        if self._publish_mode == "csv":
            rows_total = max(1, int(payload.get("rows", len(self._rows)) or len(self._rows) or 1))
            if stage == "start":
                self.progress.setRange(0, rows_total)
                self.progress.setValue(0)
                self._set_progress_base(f"Exportando CSV de {rows_total} filas...")
            elif stage == "done":
                self.progress.setRange(0, rows_total)
                self.progress.setValue(rows_total)
                files = payload.get("files", [])
                self._set_progress_base(f"CSV exportado ({len(files)} archivo(s)).")
            return

        chunks_total = max(1, int(payload.get("chunks_total", 1) or 1))
        chunk_index = max(0, int(payload.get("chunk_index", 0) or 0))
        if stage == "start":
            self.progress.setRange(0, chunks_total)
            self.progress.setValue(0)
            rows_total = int(payload.get("rows_total", len(self._rows)) or len(self._rows))
            self._set_progress_base(f"API publish iniciado: {chunks_total} chunk(s), {rows_total} filas.")
            return
        if stage == "chunk_start":
            batch_id = str(payload.get("batch_id", "") or "")
            self._set_progress_base(f"Enviando chunk {chunk_index}/{chunks_total}\nBatch: {batch_id}")
            return
        if stage == "chunk_done":
            self.progress.setRange(0, chunks_total)
            self.progress.setValue(min(chunk_index, chunks_total))
            processed_rows = int(payload.get("processed_rows", 0) or 0)
            self._set_progress_base(
                f"Chunk {chunk_index}/{chunks_total} aplicado | filas procesadas={processed_rows}"
            )
            self.output.appendPlainText(
                f"Publish progreso: chunk {chunk_index}/{chunks_total} | filas procesadas={processed_rows}"
            )
            return
        if stage == "done":
            self.progress.setRange(0, chunks_total)
            self.progress.setValue(chunks_total)
            self._set_progress_base("API publish completado.")

    def _on_publish_finished(self, result_obj: object) -> None:
        try:
            if self._publish_mode == "csv":
                files = [str(x) for x in list(result_obj or [])]
                batch_id = f"csv_{datetime.now().strftime('%Y%m%d_%H%M%S')}"
                payload_hash = hashlib.sha256("\n".join(x["path"] for x in self._rows).encode("utf-8")).hexdigest()
                self.ctx.db.record_publish_batch(
                    batch_id=batch_id,
                    mode="csv",
                    profile=self.ctx.state.profile,
                    status="exported",
                    payload_hash=payload_hash,
                    metrics={"rows": len(self._rows), "files": files},
                )
                self.ctx.db.mark_rows_published([r["path"] for r in self._rows], batch_id)
                self.output.appendPlainText("CSV exportado:\n" + "\n".join(files))
            else:
                payload = dict(result_obj or {})
                self.output.appendPlainText("API publish:\n" + json.dumps(payload, ensure_ascii=False, indent=2))

            self.ctx.state.publish_done = True
            self.status.setText("Publicaci\u00f3n completada.")
            self.status.setStyleSheet("color:#166534;")
            self.set_completed(True)
            self._set_progress_base("Publicaci\u00f3n completada.")
        finally:
            self._running = False
            self._activity_timer.stop()
            self._refresh_progress_info()

    def _on_publish_failed(self, message: str) -> None:
        self.status.setText(f"Error de publicaci\u00f3n: {message}")
        self.status.setStyleSheet("color:#b91c1c;")
        self.output.appendPlainText(f"ERROR publicaci\u00f3n: {message}")
        self._set_progress_base("Publicaci\u00f3n con error.")
        self.set_completed(False)
        self._running = False
        self._activity_timer.stop()
        self._refresh_progress_info()

    def _cleanup_worker(self) -> None:
        if self._worker is not None:
            self._worker.deleteLater()
        if self._thread is not None:
            self._thread.deleteLater()
        self._worker = None
        self._thread = None
        self.load_btn.setEnabled(True)
        self.mode.setEnabled(True)
        self.publish_btn.setEnabled(bool(self._rows))

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

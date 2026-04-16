"""Step 4 — Pausable/resumable batch BPM processing."""

from __future__ import annotations

import time
from typing import Any

from PySide6.QtCore import QThread, QTimer
from PySide6.QtWidgets import (
    QHBoxLayout,
    QLabel,
    QPlainTextEdit,
    QProgressBar,
    QPushButton,
    QSpinBox,
    QVBoxLayout,
)

from ..services.app_context import AppContext
from ._base import BaseStep, PipelineWorker


class BackfillStep(BaseStep):
    def __init__(self, ctx: AppContext) -> None:
        super().__init__("Backfill", ctx)
        self._running = False
        self._batch_in_flight = False
        self._worker_thread: QThread | None = None
        self._worker: PipelineWorker | None = None
        self._queue_next_batch = False
        self._started_at = 0.0
        self._last_progress_at = 0.0
        self._progress_base_text = "Pendiente"

        self._activity_timer = QTimer(self)
        self._activity_timer.setInterval(1000)
        self._activity_timer.timeout.connect(self._on_activity_tick)

        layout = QVBoxLayout(self)
        controls = QHBoxLayout()

        self.start_btn = QPushButton("Iniciar")
        self.start_btn.clicked.connect(self._start)
        controls.addWidget(self.start_btn)

        self.pause_btn = QPushButton("Pausar")
        self.pause_btn.clicked.connect(self._pause)
        self.pause_btn.setEnabled(False)
        controls.addWidget(self.pause_btn)

        self.resume_btn = QPushButton("Reanudar")
        self.resume_btn.clicked.connect(self._resume)
        self.resume_btn.setEnabled(False)
        controls.addWidget(self.resume_btn)

        self.batch_size = QSpinBox()
        self.batch_size.setRange(10, 1000)
        self.batch_size.setValue(40)
        controls.addWidget(QLabel("Lote"))
        controls.addWidget(self.batch_size)
        controls.addStretch(1)
        layout.addLayout(controls)

        self.progress = QProgressBar()
        self.progress.setRange(0, 100)
        self.progress.setValue(0)
        layout.addWidget(self.progress)

        self.progress_info = QLabel(self._progress_base_text)
        self.progress_info.setWordWrap(True)
        layout.addWidget(self.progress_info)

        self.output = QPlainTextEdit()
        self.output.setReadOnly(True)
        layout.addWidget(self.output, 1)

    def _start(self) -> None:
        if not self.ctx.state.dry_run_passed:
            self.output.appendPlainText("Dry-run obligatorio antes de backfill.")
            return
        if self._running:
            return
        self._running = True
        self.start_btn.setEnabled(False)
        self.pause_btn.setEnabled(True)
        self.resume_btn.setEnabled(False)
        now = time.monotonic()
        self._started_at = now
        self._last_progress_at = now
        self.output.appendPlainText("Backfill iniciado.")
        self._set_progress_base("Backfill iniciado. Preparando lote...")
        self._activity_timer.start()
        self._start_next_batch()

    def _pause(self) -> None:
        if not self._running:
            return
        self._running = False
        self.pause_btn.setEnabled(False)
        self.resume_btn.setEnabled(True)
        if self._batch_in_flight:
            self.output.appendPlainText("Pausa solicitada. Se aplicar\u00e1 al terminar el lote actual.")
            self._set_progress_base("Pausa solicitada. Terminando lote actual...")
        else:
            self._activity_timer.stop()
            self.output.appendPlainText("Backfill pausado.")
            self._set_progress_base("Backfill pausado.")

    def _resume(self) -> None:
        if self._running or self._worker_thread is not None:
            return
        self._running = True
        self.pause_btn.setEnabled(True)
        self.resume_btn.setEnabled(False)
        self.output.appendPlainText("Backfill reanudado.")
        self._activity_timer.start()
        self._start_next_batch()

    def _start_next_batch(self) -> None:
        if not self._running or self._batch_in_flight or self._worker_thread is not None:
            return

        self._batch_in_flight = True
        self._last_progress_at = time.monotonic()
        self.progress.setRange(0, 0)
        self._set_progress_base("Procesando lote...")

        self._worker_thread = QThread()
        self._worker = PipelineWorker(
            task=self.ctx.pipeline.process_backfill_batch,
            kwargs={
                "limit": int(self.batch_size.value()),
                "profile": self.ctx.state.profile,
                "ffmpeg_path": self.ctx.ffmpeg_path,
            },
        )
        self._worker.moveToThread(self._worker_thread)

        self._worker_thread.started.connect(self._worker.run)
        self._worker.progress.connect(self._on_batch_progress)
        self._worker.finished.connect(self._on_batch_finished)
        self._worker.failed.connect(self._on_batch_failed)
        self._worker.finished.connect(self._worker_thread.quit)
        self._worker.failed.connect(self._worker_thread.quit)
        self._worker_thread.finished.connect(self._cleanup_batch_worker)
        self._worker_thread.start()

    def _on_batch_progress(self, payload: dict[str, Any]) -> None:
        self._last_progress_at = time.monotonic()
        processed = max(0, int(payload.get("processed", 0) or 0))
        total = max(1, int(payload.get("total", 0) or 1))
        detected = max(0, int(payload.get("detected", 0) or 0))
        manual_review = max(0, int(payload.get("manual_review", 0) or 0))
        failed = max(0, int(payload.get("failed", 0) or 0))
        stage = str(payload.get("stage", "") or "")
        path = str(payload.get("path", "") or "")

        if stage in ("processing", "processed", "done"):
            self.progress.setRange(0, total)
            self.progress.setValue(min(processed, total))

        short_path = path
        if len(short_path) > 90:
            short_path = "..." + short_path[-87:]

        base = (
            f"Lote actual {processed}/{total} | detectados={detected} | manual={manual_review} | failed={failed}"
        )
        if short_path:
            base = f"{base}\nArchivo: {short_path}"
        self._set_progress_base(base)

        if stage == "processed" and (processed == total or processed % 10 == 0):
            self.output.appendPlainText(
                f"Lote progreso: {processed}/{total} | detectados={detected} | manual={manual_review} | failed={failed}"
            )

    def _on_batch_finished(self, payload_obj: object) -> None:
        payload = dict(payload_obj or {})
        self._batch_in_flight = False

        metrics = self.ctx.db.get_metrics()
        total = max(1, int(metrics["audio_total"]))
        with_bpm = int(metrics["with_bpm"])

        self.progress.setMaximum(total)
        self.progress.setValue(with_bpm)

        self.output.appendPlainText(
            f"Procesados={payload['processed']} detectados={payload['detected']} "
            f"manual_review={payload['manual_review']} failed={payload['failed']} "
            f"pendientes={metrics['queue_pending']} cobertura={metrics['coverage_pct']}%"
        )

        if int(metrics["queue_pending"]) <= 0:
            self._queue_next_batch = False
            self._finish_backfill()
            return

        if self._running:
            self._queue_next_batch = True
        else:
            self._queue_next_batch = False
            self._activity_timer.stop()
            self.output.appendPlainText("Backfill pausado.")
            self._set_progress_base("Backfill pausado.")

    def _on_batch_failed(self, message: str) -> None:
        self._batch_in_flight = False
        self._queue_next_batch = False
        self._running = False
        self.pause_btn.setEnabled(False)
        self.resume_btn.setEnabled(True)
        self.output.appendPlainText(f"ERROR backfill: {message}")
        self._set_progress_base("Backfill con error. Puedes reanudar.")
        self._activity_timer.stop()

    def _cleanup_batch_worker(self) -> None:
        if self._worker is not None:
            self._worker.deleteLater()
        if self._worker_thread is not None:
            self._worker_thread.deleteLater()
        self._worker = None
        self._worker_thread = None
        if self._queue_next_batch and self._running:
            self._queue_next_batch = False
            QTimer.singleShot(0, self._start_next_batch)

    def _finish_backfill(self) -> None:
        self._running = False
        self._batch_in_flight = False
        self._activity_timer.stop()
        self.pause_btn.setEnabled(False)
        self.resume_btn.setEnabled(False)
        self.start_btn.setEnabled(False)
        self.ctx.state.backfill_done = True
        self.set_completed(True)
        self._set_progress_base("Backfill completado.")
        self.output.appendPlainText("Backfill completado.")

    def _set_progress_base(self, text: str) -> None:
        self._progress_base_text = text.strip() or "Pendiente"
        self._refresh_progress_info()

    def _refresh_progress_info(self) -> None:
        text = self._progress_base_text
        if self._running or self._batch_in_flight:
            elapsed = max(0, int(time.monotonic() - self._started_at))
            idle = max(0, int(time.monotonic() - self._last_progress_at))
            text += f"\nTiempo: {elapsed}s"
            if idle >= 8:
                text += f" | sin cambios: {idle}s (sigue trabajando)"
        self.progress_info.setText(text)

    def _on_activity_tick(self) -> None:
        self._refresh_progress_info()

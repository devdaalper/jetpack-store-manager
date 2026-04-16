"""Step 3 — Catalog sync + sample dry-run analysis."""

from __future__ import annotations

import json
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


class DryRunStep(BaseStep):
    def __init__(self, ctx: AppContext) -> None:
        super().__init__("Dry-run", ctx)
        layout = QVBoxLayout(self)
        self._dry_running = False
        self._sync_running = False
        self._sync_thread: QThread | None = None
        self._sync_worker: PipelineWorker | None = None
        self._dry_thread: QThread | None = None
        self._dry_worker: PipelineWorker | None = None
        self._progress_base_text = "Sin ejecutar"
        self._started_at = 0.0
        self._last_progress_at = 0.0

        self._activity_timer = QTimer(self)
        self._activity_timer.setInterval(1000)
        self._activity_timer.timeout.connect(self._on_activity_tick)

        controls = QHBoxLayout()
        self.sample_size = QSpinBox()
        self.sample_size.setRange(50, 5000)
        self.sample_size.setValue(500)
        controls.addWidget(QLabel("Muestra"))
        controls.addWidget(self.sample_size)

        self.sync_pages = QSpinBox()
        self.sync_pages.setRange(10, 1500)
        self.sync_pages.setValue(120)
        controls.addWidget(QLabel("P\u00e1ginas sync"))
        controls.addWidget(self.sync_pages)

        self.sync_btn = QPushButton("Sincronizar cat\u00e1logo")
        self.sync_btn.clicked.connect(self._sync_catalog)
        controls.addWidget(self.sync_btn)

        self.run_btn = QPushButton("Ejecutar dry-run")
        self.run_btn.clicked.connect(self._run_dry)
        controls.addWidget(self.run_btn)
        controls.addStretch(1)

        layout.addLayout(controls)

        self.progress = QProgressBar()
        self.progress.setMinimum(0)
        self.progress.setMaximum(100)
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

    def _sync_catalog(self) -> None:
        if self._sync_running or self._dry_running or self._sync_thread is not None or self._dry_thread is not None:
            return
        self._sync_running = True
        self.sync_btn.setEnabled(False)
        self.run_btn.setEnabled(False)
        self.progress.setRange(0, 0)
        self._set_progress_base("Sincronizaci\u00f3n iniciada: esperando primera p\u00e1gina de B2...")
        self.output.appendPlainText("Sincronizaci\u00f3n iniciada...")
        self._start_activity()

        self._sync_thread = QThread()
        self._sync_worker = PipelineWorker(
            task=self.ctx.pipeline.sync_catalog,
            kwargs={"max_pages": int(self.sync_pages.value())},
        )
        self._sync_worker.moveToThread(self._sync_thread)

        self._sync_thread.started.connect(self._sync_worker.run)
        self._sync_worker.progress.connect(self._on_sync_progress)
        self._sync_worker.finished.connect(self._on_sync_finished)
        self._sync_worker.failed.connect(self._on_sync_failed)
        self._sync_worker.finished.connect(self._sync_thread.quit)
        self._sync_worker.failed.connect(self._sync_thread.quit)
        self._sync_thread.finished.connect(self._cleanup_sync_thread)
        self._sync_thread.start()

    def _run_dry(self) -> None:
        if self._dry_running or self._sync_running or self._sync_thread is not None or self._dry_thread is not None:
            return
        if not self.ctx.state.preflight_passed:
            self.status.setText("Debes aprobar preflight antes del dry-run.")
            self.status.setStyleSheet("color:#b91c1c;")
            self.set_completed(False)
            return

        self._dry_running = True
        self.run_btn.setEnabled(False)
        self.sync_btn.setEnabled(False)
        self.progress.setRange(0, 0)
        self._set_progress_base("Dry-run iniciado: preparando muestra...")
        self.output.appendPlainText("Dry-run en progreso...")
        self._start_activity()

        self._dry_thread = QThread()
        self._dry_worker = PipelineWorker(
            task=self.ctx.pipeline.run_dry_run,
            kwargs={
                "sample_size": int(self.sample_size.value()),
                "profile": self.ctx.state.profile,
                "ffmpeg_path": self.ctx.ffmpeg_path,
            },
        )
        self._dry_worker.moveToThread(self._dry_thread)

        self._dry_thread.started.connect(self._dry_worker.run)
        self._dry_worker.progress.connect(self._on_dry_progress)
        self._dry_worker.finished.connect(self._on_dry_finished)
        self._dry_worker.failed.connect(self._on_dry_failed)
        self._dry_worker.finished.connect(self._dry_thread.quit)
        self._dry_worker.failed.connect(self._dry_thread.quit)
        self._dry_thread.finished.connect(self._cleanup_dry_thread)
        self._dry_thread.start()

    def _on_sync_progress(self, payload: dict) -> None:
        pages = max(0, int(payload.get("pages", 0) or 0))
        max_pages = max(1, int(payload.get("max_pages", 1) or 1))
        scanned_audio = max(0, int(payload.get("scanned_audio", 0) or 0))
        upserted = max(0, int(payload.get("upserted", 0) or 0))
        stage = str(payload.get("stage", "") or "")
        self._last_progress_at = time.monotonic()

        if stage in ("page", "done"):
            self.progress.setRange(0, max_pages)
            self.progress.setValue(min(pages, max_pages))

        self._set_progress_base(f"Sync p\u00e1ginas {pages}/{max_pages} | audios detectados={scanned_audio} | upserted={upserted}")

        if stage in ("page", "done") and (pages == max_pages or pages % 10 == 0 or stage == "done"):
            self.output.appendPlainText(
                f"Sync progreso: p\u00e1ginas {pages}/{max_pages}, audios={scanned_audio}, upserted={upserted}"
            )

    def _on_dry_progress(self, payload: dict) -> None:
        total = max(1, int(payload.get("total", 0) or 0))
        processed = max(0, int(payload.get("processed", 0) or 0))
        detected = max(0, int(payload.get("detected", 0) or 0))
        manual_review = max(0, int(payload.get("manual_review", 0) or 0))
        invalid = max(0, int(payload.get("invalid", 0) or 0))
        path = str(payload.get("path", "") or "")
        stage = str(payload.get("stage", "") or "")
        self._last_progress_at = time.monotonic()

        self.progress.setMaximum(total)
        self.progress.setValue(min(processed, total))

        short_path = path
        if len(short_path) > 90:
            short_path = "..." + short_path[-87:]
        if short_path:
            self._set_progress_base(
                f"Procesados {processed}/{total} | detectados={detected} | manual={manual_review} | inv\u00e1lidos={invalid}\n"
                f"Archivo: {short_path}"
            )
        else:
            self._set_progress_base(
                f"Procesados {processed}/{total} | detectados={detected} | manual={manual_review} | inv\u00e1lidos={invalid}"
            )

        if stage == "processed" and (processed == total or processed % 25 == 0):
            self.output.appendPlainText(
                f"Progreso dry-run: {processed}/{total} | detectados={detected} | manual={manual_review} | inv\u00e1lidos={invalid}"
            )

    def _on_sync_finished(self, stats: object) -> None:
        self.output.appendPlainText("Cat\u00e1logo sincronizado:")
        self.output.appendPlainText(json.dumps(dict(stats or {}), ensure_ascii=False, indent=2))
        self._set_progress_base("Sincronizaci\u00f3n completada.")
        self._sync_running = False
        if not self._dry_running:
            self._stop_activity()

    def _on_sync_failed(self, message: str) -> None:
        self.output.appendPlainText(f"ERROR sync cat\u00e1logo: {message}")
        self._set_progress_base("Sincronizaci\u00f3n con error.")
        self._sync_running = False
        if not self._dry_running:
            self._stop_activity()

    def _cleanup_sync_thread(self) -> None:
        if self._sync_worker is not None:
            self._sync_worker.deleteLater()
        if self._sync_thread is not None:
            self._sync_thread.deleteLater()
        self._sync_worker = None
        self._sync_thread = None
        if not self._dry_running:
            self.sync_btn.setEnabled(True)
            self.run_btn.setEnabled(True)

    def _on_dry_finished(self, metrics_obj: object) -> None:
        metrics = metrics_obj
        self.output.appendPlainText("Dry-run:")
        self.output.appendPlainText(json.dumps(metrics.__dict__, ensure_ascii=False, indent=2))

        dry_ok = metrics.detected > 0 and metrics.invalid == 0
        self.ctx.state.dry_run_passed = dry_ok
        self.set_completed(dry_ok)
        if dry_ok:
            self.status.setText("Dry-run aprobado. Puedes iniciar backfill.")
            self.status.setStyleSheet("color:#166534;")
            self._set_progress_base("Dry-run completado correctamente.")
        else:
            self.status.setText("Dry-run fall\u00f3: detecci\u00f3n insuficiente o errores en muestra.")
            self.status.setStyleSheet("color:#b91c1c;")
            self._set_progress_base("Dry-run terminado con observaciones.")

        self._dry_running = False
        if not self._sync_running:
            self._stop_activity()

    def _on_dry_failed(self, message: str) -> None:
        self.output.appendPlainText(f"ERROR dry-run: {message}")
        self.status.setText("Dry-run fall\u00f3.")
        self.status.setStyleSheet("color:#b91c1c;")
        self._set_progress_base("Dry-run con error.")
        self.set_completed(False)
        self._dry_running = False
        if not self._sync_running:
            self._stop_activity()

    def _cleanup_dry_thread(self) -> None:
        if self._dry_worker is not None:
            self._dry_worker.deleteLater()
        if self._dry_thread is not None:
            self._dry_thread.deleteLater()
        self._dry_worker = None
        self._dry_thread = None
        if not self._sync_running:
            self.run_btn.setEnabled(True)
            self.sync_btn.setEnabled(True)

    def _start_activity(self) -> None:
        now = time.monotonic()
        self._started_at = now
        self._last_progress_at = now
        self._activity_timer.start()
        self._refresh_progress_info()

    def _stop_activity(self) -> None:
        self._activity_timer.stop()
        self._refresh_progress_info()

    def _set_progress_base(self, text: str) -> None:
        self._progress_base_text = text.strip() or "Sin ejecutar"
        self._refresh_progress_info()

    def _refresh_progress_info(self) -> None:
        text = self._progress_base_text
        if self._dry_running or self._sync_running:
            elapsed = max(0, int(time.monotonic() - self._started_at))
            idle = max(0, int(time.monotonic() - self._last_progress_at))
            text += f"\nTiempo: {elapsed}s"
            if idle >= 8:
                text += f" | sin cambios: {idle}s (sigue trabajando)"
        self.progress_info.setText(text)

    def _on_activity_tick(self) -> None:
        self._refresh_progress_info()

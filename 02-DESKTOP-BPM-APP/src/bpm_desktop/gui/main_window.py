from __future__ import annotations

import hashlib
import json
import time
from datetime import datetime
from typing import Any, Callable

from PySide6.QtCore import QObject, QThread, QTimer, Signal, Slot
from PySide6.QtWidgets import (
    QApplication,
    QComboBox,
    QFormLayout,
    QGridLayout,
    QGroupBox,
    QHBoxLayout,
    QLabel,
    QLineEdit,
    QListWidget,
    QMainWindow,
    QMessageBox,
    QPushButton,
    QPlainTextEdit,
    QProgressBar,
    QSpinBox,
    QStackedWidget,
    QTableWidget,
    QTableWidgetItem,
    QVBoxLayout,
    QWidget,
)

from ..constants import APP_NAME, PROFILES
from ..models import Credentials
from ..preflight import PreflightRunner, preflight_passed
from ..quality_gate import QualityGateResult
from ..services.app_context import AppContext


class PipelineWorker(QObject):
    progress = Signal(dict)
    finished = Signal(object)
    failed = Signal(str)

    def __init__(self, task: Callable[..., Any], kwargs: dict[str, Any]) -> None:
        super().__init__()
        self.task = task
        self.kwargs = kwargs

    @Slot()
    def run(self) -> None:
        try:
            result = self.task(progress_callback=self._emit_progress, **self.kwargs)
            self.finished.emit(result)
        except Exception as exc:
            self.failed.emit(str(exc))

    def _emit_progress(self, payload: dict[str, Any]) -> None:
        self.progress.emit(dict(payload or {}))


class BaseStep(QWidget):
    completionChanged = Signal(bool)

    def __init__(self, title: str, ctx: AppContext) -> None:
        super().__init__()
        self.title = title
        self.ctx = ctx
        self._completed = False

    @property
    def completed(self) -> bool:
        return self._completed

    def set_completed(self, value: bool) -> None:
        if self._completed == value:
            return
        self._completed = value
        self.completionChanged.emit(value)

    def on_enter(self) -> None:
        return


class ConnectionsStep(BaseStep):
    def __init__(self, ctx: AppContext) -> None:
        super().__init__("Conexiones", ctx)
        self._saved_once = False
        layout = QVBoxLayout(self)

        box = QGroupBox("Credenciales (guardadas en Keychain)")
        form = QFormLayout(box)

        self.b2_key_id = QLineEdit(ctx.creds.b2_key_id)
        self.b2_app_key = QLineEdit(ctx.creds.b2_app_key)
        self.b2_app_key.setEchoMode(QLineEdit.EchoMode.Password)
        self.b2_bucket = QLineEdit(ctx.creds.b2_bucket)
        self.b2_prefix = QLineEdit(ctx.creds.b2_prefix)
        self.wp_url = QLineEdit(ctx.creds.wp_base_url)
        self.wp_token = QLineEdit(ctx.creds.wp_desktop_token)
        self.wp_token.setEchoMode(QLineEdit.EchoMode.Password)

        form.addRow("B2 Key ID", self.b2_key_id)
        form.addRow("B2 App Key", self.b2_app_key)
        form.addRow("B2 Bucket", self.b2_bucket)
        form.addRow("B2 Prefix (opcional)", self.b2_prefix)
        form.addRow("WordPress URL base", self.wp_url)
        form.addRow("Desktop API Token", self.wp_token)
        layout.addWidget(box)

        self.profile = QComboBox()
        self.profile.addItems(list(PROFILES))
        self.profile.setCurrentText(ctx.state.profile)
        layout.addWidget(QLabel("Perfil por defecto"))
        layout.addWidget(self.profile)

        self.save_btn = QPushButton("Guardar y continuar")
        self.save_btn.clicked.connect(self._save)
        layout.addWidget(self.save_btn)

        self.status = QLabel("")
        self.status.setWordWrap(True)
        layout.addWidget(self.status)
        self._set_pending_message()

        for field in (
            self.b2_key_id,
            self.b2_app_key,
            self.b2_bucket,
            self.b2_prefix,
            self.wp_url,
            self.wp_token,
        ):
            field.textChanged.connect(self._mark_dirty)
        self.profile.currentTextChanged.connect(self._mark_dirty)
        layout.addStretch(1)

    def _save(self) -> None:
        creds = Credentials(
            b2_key_id=self.b2_key_id.text().strip(),
            b2_app_key=self.b2_app_key.text().strip(),
            b2_bucket=self.b2_bucket.text().strip(),
            b2_prefix=self.b2_prefix.text().strip(),
            wp_base_url=self.wp_url.text().strip(),
            wp_desktop_token=self.wp_token.text().strip(),
        )
        if not creds.b2_key_id or not creds.b2_app_key or not creds.b2_bucket:
            self.status.setText("Faltan credenciales de B2.")
            self.status.setStyleSheet("color:#b91c1c;")
            self.set_completed(False)
            return
        if not creds.wp_base_url or not creds.wp_desktop_token:
            self.status.setText("Faltan datos de WordPress (URL/token).")
            self.status.setStyleSheet("color:#b91c1c;")
            self.set_completed(False)
            return

        self.ctx.state.profile = self.profile.currentText().strip() or "balanced"
        self.ctx.save_credentials(creds)
        self.ctx.db.log_event("credentials_saved", {"wp_base_url": creds.wp_base_url, "bucket": creds.b2_bucket})

        self.status.setText("Credenciales guardadas. Puedes avanzar al preflight.")
        self.status.setStyleSheet("color:#166534;")
        self._saved_once = True
        self.set_completed(True)

    def _set_pending_message(self) -> None:
        self.status.setText("Pendiente: captura o revisa credenciales y presiona 'Guardar y continuar'.")
        self.status.setStyleSheet("color:#92400e;")

    def _mark_dirty(self) -> None:
        if not self._saved_once:
            return
        self.set_completed(False)
        self._set_pending_message()


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
            emoji = "✅" if ok else "❌"
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
            self.status.setText("Preflight falló. Corrige los puntos en rojo para continuar.")
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
        controls.addWidget(QLabel("Páginas sync"))
        controls.addWidget(self.sync_pages)

        self.sync_btn = QPushButton("Sincronizar catálogo")
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
        self._set_progress_base("Sincronización iniciada: esperando primera página de B2...")
        self.output.appendPlainText("Sincronización iniciada...")
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

        self._set_progress_base(f"Sync páginas {pages}/{max_pages} | audios detectados={scanned_audio} | upserted={upserted}")

        if stage in ("page", "done") and (pages == max_pages or pages % 10 == 0 or stage == "done"):
            self.output.appendPlainText(
                f"Sync progreso: páginas {pages}/{max_pages}, audios={scanned_audio}, upserted={upserted}"
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
                f"Procesados {processed}/{total} | detectados={detected} | manual={manual_review} | inválidos={invalid}\n"
                f"Archivo: {short_path}"
            )
        else:
            self._set_progress_base(
                f"Procesados {processed}/{total} | detectados={detected} | manual={manual_review} | inválidos={invalid}"
            )

        if stage == "processed" and (processed == total or processed % 25 == 0):
            self.output.appendPlainText(
                f"Progreso dry-run: {processed}/{total} | detectados={detected} | manual={manual_review} | inválidos={invalid}"
            )

    def _on_sync_finished(self, stats: object) -> None:
        self.output.appendPlainText("Catálogo sincronizado:")
        self.output.appendPlainText(json.dumps(dict(stats or {}), ensure_ascii=False, indent=2))
        self._set_progress_base("Sincronización completada.")
        self._sync_running = False
        if not self._dry_running:
            self._stop_activity()

    def _on_sync_failed(self, message: str) -> None:
        self.output.appendPlainText(f"ERROR sync catálogo: {message}")
        self._set_progress_base("Sincronización con error.")
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
            self.status.setText("Dry-run falló: detección insuficiente o errores en muestra.")
            self.status.setStyleSheet("color:#b91c1c;")
            self._set_progress_base("Dry-run terminado con observaciones.")

        self._dry_running = False
        if not self._sync_running:
            self._stop_activity()

    def _on_dry_failed(self, message: str) -> None:
        self.output.appendPlainText(f"ERROR dry-run: {message}")
        self.status.setText("Dry-run falló.")
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
            self.output.appendPlainText("Pausa solicitada. Se aplicará al terminar el lote actual.")
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


class ReviewStep(BaseStep):
    def __init__(self, ctx: AppContext) -> None:
        super().__init__("Revisión", ctx)
        layout = QVBoxLayout(self)
        self._progress_base_text = "Pendiente"

        controls = QHBoxLayout()
        self.refresh_btn = QPushButton("Refrescar cola")
        self.refresh_btn.clicked.connect(self._load)
        controls.addWidget(self.refresh_btn)

        self.approve_btn = QPushButton("Aprobar BPM seleccionado")
        self.approve_btn.clicked.connect(self._approve)
        controls.addWidget(self.approve_btn)
        controls.addStretch(1)
        layout.addLayout(controls)

        self.table = QTableWidget(0, 4)
        self.table.setHorizontalHeaderLabels(["Path", "Sugerido", "Confianza", "Elegido"])
        self.table.horizontalHeader().setStretchLastSection(True)
        layout.addWidget(self.table, 1)

        self.progress = QProgressBar()
        self.progress.setRange(0, 1)
        self.progress.setValue(0)
        layout.addWidget(self.progress)

        self.progress_info = QLabel(self._progress_base_text)
        self.progress_info.setWordWrap(True)
        layout.addWidget(self.progress_info)

        self.status = QLabel("")
        layout.addWidget(self.status)

    def on_enter(self) -> None:
        self._load()

    def _load(self) -> None:
        self._set_progress_base("Cargando cola de revisión...")
        rows = self.ctx.db.get_manual_review_rows(limit=1000)
        total_rows = max(1, len(rows))
        self.progress.setRange(0, total_rows)
        self.progress.setValue(0)
        self.table.setRowCount(len(rows))
        for i, row in enumerate(rows):
            self.table.setItem(i, 0, QTableWidgetItem(str(row["path"])))
            self.table.setItem(i, 1, QTableWidgetItem(str(row["suggested_bpm"])))
            self.table.setItem(i, 2, QTableWidgetItem(f"{float(row['suggested_confidence']):.2f}"))
            chosen = QTableWidgetItem(str(int(row["suggested_bpm"] or 0)))
            self.table.setItem(i, 3, chosen)
            self.progress.setValue(i + 1)
            if i == 0 or (i + 1) % 100 == 0 or (i + 1) == len(rows):
                self._set_progress_base(f"Cargando revisión {i + 1}/{len(rows)}")

        pending = self.ctx.db.pending_review_count()
        self.status.setText(f"Pendientes de revisión: {pending}")
        self.status.setStyleSheet("color:#92400e;" if pending > 0 else "color:#166534;")
        if len(rows) == 0:
            self.progress.setRange(0, 1)
            self.progress.setValue(1)
        self._set_progress_base(f"Revisión cargada. Pendientes={pending}")
        self.set_completed(pending == 0)

    def _approve(self) -> None:
        row = self.table.currentRow()
        if row < 0:
            QMessageBox.warning(self, "Revisión", "Selecciona una fila.")
            return

        path_item = self.table.item(row, 0)
        bpm_item = self.table.item(row, 3)
        if not path_item or not bpm_item:
            return

        path = path_item.text().strip()
        try:
            bpm = int(bpm_item.text().strip())
        except Exception:
            QMessageBox.warning(self, "Revisión", "BPM inválido.")
            return

        if bpm < 40 or bpm > 260:
            QMessageBox.warning(self, "Revisión", "BPM fuera de rango (40-260).")
            return

        self.ctx.db.apply_manual_review(path, bpm)
        self.ctx.db.log_event("manual_review_approved", {"path": path, "bpm": bpm})
        self._set_progress_base(f"Aprobado: {path} -> {bpm} BPM")
        self._load()

    def _set_progress_base(self, text: str) -> None:
        self._progress_base_text = text.strip() or "Pendiente"
        self.progress_info.setText(self._progress_base_text)


class PublishStep(BaseStep):
    def __init__(self, ctx: AppContext) -> None:
        super().__init__("Publicación", ctx)
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
            "Confirmar publicación (1/2)",
            f"Se publicarán {len(self._rows)} filas en modo {self.mode.currentText()}. ¿Continuar?",
        ) != QMessageBox.StandardButton.Yes:
            return

        if QMessageBox.question(
            self,
            "Confirmar publicación (2/2)",
            "Última confirmación: esta operación aplica cambios en WordPress. ¿Publicar ahora?",
        ) != QMessageBox.StandardButton.Yes:
            return

        self._running = True
        self._publish_mode = self.mode.currentText()
        self.load_btn.setEnabled(False)
        self.publish_btn.setEnabled(False)
        self.mode.setEnabled(False)
        self.status.setText("Publicación en curso...")
        self.status.setStyleSheet("color:#92400e;")
        now = time.monotonic()
        self._started_at = now
        self._last_progress_at = now
        self._activity_timer.start()
        self.progress.setRange(0, 0)
        self._set_progress_base(f"Publicación iniciada en modo {self._publish_mode}...")

        if self._publish_mode == "csv":
            task = self.ctx.pipeline.export_csv
            kwargs = {"rows": self._rows}
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
            self.status.setText("Publicación completada.")
            self.status.setStyleSheet("color:#166534;")
            self.set_completed(True)
            self._set_progress_base("Publicación completada.")
        finally:
            self._running = False
            self._activity_timer.stop()
            self._refresh_progress_info()

    def _on_publish_failed(self, message: str) -> None:
        self.status.setText(f"Error de publicación: {message}")
        self.status.setStyleSheet("color:#b91c1c;")
        self.output.appendPlainText(f"ERROR publicación: {message}")
        self._set_progress_base("Publicación con error.")
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
            QMessageBox.warning(self, "Rollback", "Batch inválido.")
            return

        if QMessageBox.question(
            self,
            "Confirmar rollback (1/2)",
            f"Se revertirá completamente el batch {batch_id}. ¿Continuar?",
        ) != QMessageBox.StandardButton.Yes:
            return

        if QMessageBox.question(
            self,
            "Confirmar rollback (2/2)",
            "Última confirmación: se restaurarán BPM anteriores en WordPress. ¿Ejecutar rollback?",
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
            self._set_progress_base(f"Rollback ejecutándose: {self._current_batch_id}")
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
            "5. Revisión",
            "6. Publicación",
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
        self.back_btn = QPushButton("Atrás")
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

"""Step 5 — Manual review of low-confidence BPM detections."""

from __future__ import annotations

from PySide6.QtWidgets import (
    QHBoxLayout,
    QLabel,
    QMessageBox,
    QProgressBar,
    QPushButton,
    QTableWidget,
    QTableWidgetItem,
    QVBoxLayout,
)

from ..services.app_context import AppContext
from ._base import BaseStep


class ReviewStep(BaseStep):
    def __init__(self, ctx: AppContext) -> None:
        super().__init__("Revisi\u00f3n", ctx)
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
        self._set_progress_base("Cargando cola de revisi\u00f3n...")
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
                self._set_progress_base(f"Cargando revisi\u00f3n {i + 1}/{len(rows)}")

        pending = self.ctx.db.pending_review_count()
        self.status.setText(f"Pendientes de revisi\u00f3n: {pending}")
        self.status.setStyleSheet("color:#92400e;" if pending > 0 else "color:#166534;")
        if len(rows) == 0:
            self.progress.setRange(0, 1)
            self.progress.setValue(1)
        self._set_progress_base(f"Revisi\u00f3n cargada. Pendientes={pending}")
        self.set_completed(pending == 0)

    def _approve(self) -> None:
        row = self.table.currentRow()
        if row < 0:
            QMessageBox.warning(self, "Revisi\u00f3n", "Selecciona una fila.")
            return

        path_item = self.table.item(row, 0)
        bpm_item = self.table.item(row, 3)
        if not path_item or not bpm_item:
            return

        path = path_item.text().strip()
        try:
            bpm = int(bpm_item.text().strip())
        except (ValueError, TypeError):
            QMessageBox.warning(self, "Revisi\u00f3n", "BPM inv\u00e1lido.")
            return

        if bpm < 40 or bpm > 260:
            QMessageBox.warning(self, "Revisi\u00f3n", "BPM fuera de rango (40-260).")
            return

        self.ctx.db.apply_manual_review(path, bpm)
        self.ctx.db.log_event("manual_review_approved", {"path": path, "bpm": bpm})
        self._set_progress_base(f"Aprobado: {path} -> {bpm} BPM")
        self._load()

    def _set_progress_base(self, text: str) -> None:
        self._progress_base_text = text.strip() or "Pendiente"
        self.progress_info.setText(self._progress_base_text)

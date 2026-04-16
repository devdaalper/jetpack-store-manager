"""Step 1 — Credential entry and profile selection."""

from __future__ import annotations

from PySide6.QtWidgets import (
    QComboBox,
    QFormLayout,
    QGroupBox,
    QLabel,
    QLineEdit,
    QPushButton,
    QVBoxLayout,
)

from ..constants import PROFILES
from ..models import Credentials
from ..services.app_context import AppContext
from ._base import BaseStep


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

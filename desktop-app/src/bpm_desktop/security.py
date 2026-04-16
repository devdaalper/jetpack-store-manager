"""Secure credential storage backed by the OS keyring."""

from __future__ import annotations

from dataclasses import asdict

import keyring

from .constants import APP_SERVICE_NAME
from .models import Credentials


class CredentialStore:
    """Persists and retrieves B2/WordPress credentials via the system keyring."""
    _KEY = "credentials"

    def save(self, creds: Credentials) -> None:
        """Store credentials in the system keyring."""
        payload = "\n".join(
            [
                creds.b2_key_id,
                creds.b2_app_key,
                creds.b2_bucket,
                creds.b2_prefix,
                creds.wp_base_url,
                creds.wp_desktop_token,
            ]
        )
        keyring.set_password(APP_SERVICE_NAME, self._KEY, payload)

    def load(self) -> Credentials:
        """Load credentials from the system keyring, returning empty fields if absent."""
        raw = keyring.get_password(APP_SERVICE_NAME, self._KEY) or ""
        parts = raw.split("\n")
        while len(parts) < 6:
            parts.append("")
        return Credentials(
            b2_key_id=parts[0],
            b2_app_key=parts[1],
            b2_bucket=parts[2],
            b2_prefix=parts[3],
            wp_base_url=parts[4],
            wp_desktop_token=parts[5],
        )

    def clear(self) -> None:
        """Remove stored credentials from the system keyring."""
        try:
            keyring.delete_password(APP_SERVICE_NAME, self._KEY)
        except keyring.errors.PasswordDeleteError:
            return

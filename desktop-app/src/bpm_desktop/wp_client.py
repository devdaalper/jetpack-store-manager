"""WordPress REST/AJAX client for BPM batch import, health checks, and rollbacks."""

from __future__ import annotations

import hashlib
import logging
from typing import Any

import requests

from .models import PublishMetrics

logger = logging.getLogger(__name__)


class WordPressAPIError(RuntimeError):
    """Raised when the WordPress desktop API returns an error."""


class WordPressClient:
    """Client for the JetPack Store Manager WordPress desktop API."""

    def __init__(self, base_url: str, desktop_token: str) -> None:
        self.base_url = base_url.rstrip("/")
        self.desktop_token = desktop_token.strip()

    @property
    def ajax_url(self) -> str:
        return f"{self.base_url}/wp-admin/admin-ajax.php"

    def _action_url(self, action: str) -> str:
        return f"{self.ajax_url}?action={action}"

    def _headers(self) -> dict[str, str]:
        if not self.desktop_token:
            return {
                "Accept": "application/json",
                "Content-Type": "application/json",
            }
        return {
            "Accept": "application/json",
            "Authorization": f"Bearer {self.desktop_token}",
            "Content-Type": "application/json",
        }

    def _parse_contract(self, response: requests.Response) -> dict[str, Any]:
        try:
            payload = response.json()
        except (ValueError, requests.JSONDecodeError) as exc:
            raise WordPressAPIError(f"Respuesta no JSON ({response.status_code}): {response.text[:200]}") from exc

        if response.status_code >= 400:
            msg = ""
            if isinstance(payload, dict):
                data = payload.get("data")
                if isinstance(data, dict):
                    msg = str(data.get("message") or data.get("error") or "")
                elif isinstance(data, str):
                    msg = data
            raise WordPressAPIError(msg or f"Error HTTP {response.status_code}")

        if not payload.get("success"):
            data = payload.get("data")
            if isinstance(data, dict):
                raise WordPressAPIError(str(data.get("message") or "Error API"))
            raise WordPressAPIError(str(data or "Error API"))

        data = payload.get("data")
        if isinstance(data, dict) and isinstance(data.get("data"), dict):
            return data["data"]
        if isinstance(data, dict):
            return data
        return {}

    def health_check(self) -> dict[str, Any]:
        """Verify connectivity and authentication with the WordPress API."""
        action = "jpsm_desktop_api_health"
        logger.debug("WP health check → %s", self.base_url)
        response = requests.post(
            self._action_url(action),
            headers=self._headers(),
            json={"api_version": "2"},
            timeout=20,
        )
        result = self._parse_contract(response)
        logger.info("WP health check OK")
        return result

    def import_batch(self, batch_id: str, profile: str, rows: list[dict[str, Any]]) -> PublishMetrics:
        """Send a BPM batch to WordPress for import and return resulting metrics."""
        action = "jpsm_import_bpm_batch_api"
        response = requests.post(
            self._action_url(action),
            headers=self._headers(),
            json={
                "batch_id": batch_id,
                "profile": profile,
                "rows": rows,
                "api_version": "2",
            },
            timeout=120,
        )
        data = self._parse_contract(response)
        logger.info("Import batch %s: %d rows → %d upserted", batch_id, len(rows), int(data.get("upserted", 0)))
        return PublishMetrics(
            processed_rows=int(data.get("processed_rows", 0)),
            upserted=int(data.get("upserted", 0)),
            invalid_rows=int(data.get("invalid_rows", 0)),
            manual_protected=int(data.get("manual_protected", 0)),
            duplicate_batch=bool(data.get("duplicate_batch", False)),
            batch_version=str(data.get("batch_version", "")),
        )

    def rollback_batch(self, batch_id: str) -> dict[str, Any]:
        """Request a rollback of a previously published batch."""
        action = "jpsm_rollback_bpm_batch_api"
        response = requests.post(
            self._action_url(action),
            headers=self._headers(),
            json={
                "batch_id": batch_id,
                "api_version": "2",
            },
            timeout=120,
        )
        return self._parse_contract(response)

    @staticmethod
    def payload_hash(rows: list[dict[str, Any]]) -> str:
        """Compute a SHA-256 hash of the batch payload for deduplication."""
        digest = hashlib.sha256()
        for row in rows:
            digest.update(str(row.get("path", "")).encode("utf-8"))
            digest.update(str(row.get("bpm", "")).encode("utf-8"))
            digest.update(str(row.get("source", "")).encode("utf-8"))
        return digest.hexdigest()

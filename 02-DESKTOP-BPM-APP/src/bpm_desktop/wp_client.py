from __future__ import annotations

import hashlib
from typing import Any

import requests

from .models import PublishMetrics


class WordPressAPIError(RuntimeError):
    pass


class WordPressClient:
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
        except Exception as exc:
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
        action = "jpsm_desktop_api_health"
        response = requests.post(
            self._action_url(action),
            headers=self._headers(),
            json={"api_version": "2"},
            timeout=20,
        )
        return self._parse_contract(response)

    def import_batch(self, batch_id: str, profile: str, rows: list[dict[str, Any]]) -> PublishMetrics:
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
        return PublishMetrics(
            processed_rows=int(data.get("processed_rows", 0)),
            upserted=int(data.get("upserted", 0)),
            invalid_rows=int(data.get("invalid_rows", 0)),
            manual_protected=int(data.get("manual_protected", 0)),
            duplicate_batch=bool(data.get("duplicate_batch", False)),
            batch_version=str(data.get("batch_version", "")),
        )

    def rollback_batch(self, batch_id: str) -> dict[str, Any]:
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
        digest = hashlib.sha256()
        for row in rows:
            digest.update(str(row.get("path", "")).encode("utf-8"))
            digest.update(str(row.get("bpm", "")).encode("utf-8"))
            digest.update(str(row.get("source", "")).encode("utf-8"))
        return digest.hexdigest()

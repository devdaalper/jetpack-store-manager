from __future__ import annotations

import base64
from dataclasses import dataclass
from typing import Any, Iterable, Optional
from urllib.parse import quote

import requests

from .constants import AUDIO_EXTENSIONS
from .models import CatalogObject


class B2Error(RuntimeError):
    pass


@dataclass
class B2AuthSession:
    account_id: str
    api_url: str
    download_url: str
    authorization_token: str


class B2Client:
    def __init__(self, key_id: str, app_key: str, bucket_name: str, prefix: str = "") -> None:
        self.key_id = key_id.strip()
        self.app_key = app_key.strip()
        self.bucket_name = bucket_name.strip()
        self.prefix = prefix.strip()
        self.session: Optional[B2AuthSession] = None
        self.bucket_id: Optional[str] = None

    def authorize(self) -> B2AuthSession:
        if not self.key_id or not self.app_key:
            raise B2Error("Credenciales B2 incompletas.")

        token = base64.b64encode(f"{self.key_id}:{self.app_key}".encode("utf-8")).decode("ascii")
        res = requests.get(
            "https://api.backblazeb2.com/b2api/v2/b2_authorize_account",
            headers={"Authorization": f"Basic {token}"},
            timeout=30,
        )
        if res.status_code != 200:
            raise B2Error(f"b2_authorize_account falló ({res.status_code}): {res.text[:200]}")

        data = res.json()
        self.session = B2AuthSession(
            account_id=str(data.get("accountId", "")),
            api_url=str(data.get("apiUrl", "")),
            download_url=str(data.get("downloadUrl", "")),
            authorization_token=str(data.get("authorizationToken", "")),
        )
        if not self.session.api_url or not self.session.authorization_token:
            raise B2Error("Respuesta inválida en autorización B2.")
        return self.session

    def ensure_bucket_id(self) -> str:
        if self.bucket_id:
            return self.bucket_id
        auth = self.session or self.authorize()

        payload = {"accountId": auth.account_id}
        res = requests.post(
            f"{auth.api_url}/b2api/v2/b2_list_buckets",
            headers={
                "Authorization": auth.authorization_token,
                "Content-Type": "application/json",
            },
            json=payload,
            timeout=30,
        )
        if res.status_code != 200:
            raise B2Error(f"b2_list_buckets falló ({res.status_code}): {res.text[:200]}")

        data = res.json()
        for bucket in data.get("buckets", []):
            if str(bucket.get("bucketName", "")) == self.bucket_name:
                self.bucket_id = str(bucket.get("bucketId", ""))
                break
        if not self.bucket_id:
            raise B2Error(f"Bucket no encontrado: {self.bucket_name}")
        return self.bucket_id

    def list_audio_page(self, start_file_name: str | None = None, max_file_count: int = 1000) -> tuple[list[CatalogObject], str | None]:
        auth = self.session or self.authorize()
        bucket_id = self.ensure_bucket_id()

        payload: dict[str, Any] = {
            "bucketId": bucket_id,
            "maxFileCount": max(1, min(int(max_file_count), 1000)),
        }
        if self.prefix:
            payload["prefix"] = self.prefix
        if start_file_name:
            payload["startFileName"] = start_file_name

        res = requests.post(
            f"{auth.api_url}/b2api/v2/b2_list_file_names",
            headers={
                "Authorization": auth.authorization_token,
                "Content-Type": "application/json",
            },
            json=payload,
            timeout=60,
        )
        if res.status_code != 200:
            raise B2Error(f"b2_list_file_names falló ({res.status_code}): {res.text[:200]}")

        data = res.json()
        out: list[CatalogObject] = []
        for file_data in data.get("files", []):
            if str(file_data.get("action", "upload")) != "upload":
                continue
            path = str(file_data.get("fileName", ""))
            if not path or path.endswith("/"):
                continue
            ext = path.rsplit(".", 1)[-1].lower() if "." in path else ""
            content_type = str(file_data.get("contentType", "")).lower()
            media_kind = "audio" if (ext in AUDIO_EXTENSIONS or content_type.startswith("audio/")) else "other"
            if media_kind != "audio":
                continue
            out.append(
                CatalogObject(
                    path=path,
                    extension=ext,
                    size=int(file_data.get("size", 0) or 0),
                    etag=str(file_data.get("contentSha1", "")),
                    last_modified=str(file_data.get("uploadTimestamp", "")),
                    media_kind=media_kind,
                )
            )

        next_file = data.get("nextFileName")
        next_file_name = str(next_file) if next_file else None
        return out, next_file_name

    def iter_audio_objects(self, max_pages: int = 200) -> Iterable[CatalogObject]:
        start = None
        pages = 0
        while pages < max_pages:
            pages += 1
            rows, start = self.list_audio_page(start_file_name=start, max_file_count=1000)
            for row in rows:
                yield row
            if not start:
                break

    def build_download_url(self, path: str) -> str:
        auth = self.session or self.authorize()
        encoded = quote(path.lstrip("/"), safe="/")
        return f"{auth.download_url}/file/{quote(self.bucket_name, safe='')}/{encoded}"

    def fetch_head_bytes(self, path: str, max_bytes: int = 2048) -> bytes:
        auth = self.session or self.authorize()
        url = self.build_download_url(path)
        res = requests.get(
            url,
            headers={
                "Authorization": auth.authorization_token,
                "Range": f"bytes=0-{max(1, int(max_bytes))}",
            },
            timeout=40,
        )
        if res.status_code not in (200, 206):
            raise B2Error(f"Lectura de bytes falló ({res.status_code}) para {path}")
        return bytes(res.content)

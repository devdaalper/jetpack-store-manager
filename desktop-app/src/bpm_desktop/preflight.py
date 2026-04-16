"""Pre-launch validation checks for system clock, disk, SQLite, FFmpeg, B2, and WordPress."""

from __future__ import annotations

import logging
import shutil
import sqlite3
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Callable

import requests

from .b2_client import B2Client, B2Error
from .constants import DB_PATH, FFMPEG_BUNDLED_PATH
from .models import Credentials, PreflightCheck
from .wp_client import WordPressAPIError, WordPressClient

logger = logging.getLogger(__name__)


class PreflightRunner:
    """Executes a series of environment and connectivity checks before pipeline use."""
    def __init__(self, creds: Credentials, ffmpeg_path: str | None = None) -> None:
        self.creds = creds
        self.ffmpeg_path = ffmpeg_path or str(FFMPEG_BUNDLED_PATH)

    def run(self, progress_callback: Callable[[dict[str, Any]], None] | None = None) -> list[PreflightCheck]:
        """Run all preflight checks and return results, emitting progress along the way."""
        checks: list[PreflightCheck] = []
        total_steps = 8
        done = 0

        def emit(step_name: str, check: PreflightCheck) -> None:
            log_fn = logger.info if check.ok else logger.warning
            log_fn("Preflight %s: %s — %s", step_name, "OK" if check.ok else "FAIL", check.details)
            if progress_callback is None:
                return
            progress_callback(
                {
                    "stage": "check_done",
                    "step_name": step_name,
                    "done": done,
                    "total": total_steps,
                    "ok": bool(check.ok),
                    "details": str(check.details),
                }
            )

        if progress_callback is not None:
            progress_callback({"stage": "start", "done": 0, "total": total_steps})

        check_clock = self._check_clock()
        checks.append(check_clock)
        done += 1
        emit("Reloj del sistema", check_clock)

        check_disk = self._check_disk()
        checks.append(check_disk)
        done += 1
        emit("Espacio en disco", check_disk)

        check_sqlite = self._check_sqlite()
        checks.append(check_sqlite)
        done += 1
        emit("SQLite writable", check_sqlite)

        check_ffmpeg = self._check_ffmpeg()
        checks.append(check_ffmpeg)
        done += 1
        emit("FFmpeg", check_ffmpeg)

        b2_checks = self._check_b2()
        for b2_check in b2_checks:
            checks.append(b2_check)
            done += 1
            emit(str(b2_check.name), b2_check)

        check_wp = self._check_wp()
        checks.append(check_wp)
        done += 1
        emit("WordPress desktop API", check_wp)

        if progress_callback is not None:
            progress_callback({"stage": "done", "done": done, "total": max(1, done)})
        return checks

    def _check_clock(self) -> PreflightCheck:
        now = datetime.now(timezone.utc)
        ok = now.year >= 2024
        return PreflightCheck(
            name="Reloj del sistema",
            ok=ok,
            details=f"UTC: {now.isoformat()}" if ok else "Fecha del sistema inválida",
        )

    def _check_disk(self) -> PreflightCheck:
        usage = shutil.disk_usage(DB_PATH.parent)
        free_gb = usage.free / (1024 ** 3)
        ok = free_gb >= 2.0
        return PreflightCheck(
            name="Espacio en disco",
            ok=ok,
            details=f"Libre: {free_gb:.2f} GB",
        )

    def _check_sqlite(self) -> PreflightCheck:
        try:
            DB_PATH.parent.mkdir(parents=True, exist_ok=True)
            con = sqlite3.connect(DB_PATH)
            con.execute("CREATE TABLE IF NOT EXISTS _preflight_probe(id INTEGER PRIMARY KEY, t TEXT)")
            con.execute("INSERT INTO _preflight_probe(t) VALUES(datetime('now'))")
            con.commit()
            con.close()
            return PreflightCheck("SQLite writable", True, str(DB_PATH))
        except (sqlite3.Error, OSError) as exc:
            return PreflightCheck("SQLite writable", False, str(exc))

    def _check_ffmpeg(self) -> PreflightCheck:
        candidate = self.ffmpeg_path
        ffmpeg_bin = shutil.which(candidate) if "/" not in candidate else candidate
        if ffmpeg_bin and Path(ffmpeg_bin).exists():
            return PreflightCheck("FFmpeg", True, ffmpeg_bin)
        fallback = shutil.which("ffmpeg")
        if fallback:
            return PreflightCheck("FFmpeg", True, fallback)
        return PreflightCheck("FFmpeg", False, "No se encontró ffmpeg (bundled ni PATH)")

    def _check_b2(self) -> list[PreflightCheck]:
        checks: list[PreflightCheck] = []
        try:
            client = B2Client(
                key_id=self.creds.b2_key_id,
                app_key=self.creds.b2_app_key,
                bucket_name=self.creds.b2_bucket,
                prefix=self.creds.b2_prefix,
            )
            session = client.authorize()
            checks.append(PreflightCheck("B2 authorize", True, f"apiUrl={session.api_url}"))

            bucket_id = client.ensure_bucket_id()
            checks.append(PreflightCheck("B2 bucket", True, f"bucketId={bucket_id}"))

            sample = None
            max_pages = 60
            for obj in client.iter_audio_objects(max_pages=max_pages):
                sample = obj
                break

            if sample is None:
                prefix = self.creds.b2_prefix.strip() or "(vacío)"
                checks.append(
                    PreflightCheck(
                        "B2 list/read",
                        False,
                        f"No se detectaron audios tras escanear hasta {max_pages} páginas (prefix={prefix})",
                    )
                )
                return checks

            head = client.fetch_head_bytes(sample.path, max_bytes=2048)
            checks.append(PreflightCheck("B2 list/read", len(head) > 0, f"sample={sample.path}"))
        except B2Error as exc:
            checks.append(PreflightCheck("B2 connectivity", False, str(exc)))
        except (requests.RequestException, OSError) as exc:  # pragma: no cover
            checks.append(PreflightCheck("B2 connectivity", False, f"Error inesperado: {exc}"))
        return checks

    def _check_wp(self) -> PreflightCheck:
        try:
            client = WordPressClient(self.creds.wp_base_url, self.creds.wp_desktop_token)
            data = client.health_check()
            ok = bool(data.get("ok", True))
            details = f"server_time={data.get('server_time', '-') }"
            return PreflightCheck("WordPress desktop API", ok, details)
        except WordPressAPIError as exc:
            return PreflightCheck("WordPress desktop API", False, str(exc))
        except (requests.RequestException, OSError) as exc:  # pragma: no cover
            return PreflightCheck("WordPress desktop API", False, f"Error inesperado: {exc}")


def preflight_passed(checks: list[PreflightCheck]) -> bool:
    """Return True if every preflight check passed."""
    return all(c.ok for c in checks)

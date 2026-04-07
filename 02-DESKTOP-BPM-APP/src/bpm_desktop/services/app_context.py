from __future__ import annotations

import shutil
from dataclasses import dataclass

from ..b2_client import B2Client
from ..constants import FFMPEG_BUNDLED_PATH
from ..db import AppDB
from ..models import Credentials
from ..pipeline import BPMPipeline
from ..security import CredentialStore
from ..wp_client import WordPressClient


@dataclass
class RuntimeState:
    profile: str = "balanced"
    preflight_passed: bool = False
    dry_run_passed: bool = False
    backfill_done: bool = False
    publish_done: bool = False


class AppContext:
    def __init__(self) -> None:
        self.credential_store = CredentialStore()
        self.db = AppDB()
        self.creds = self.credential_store.load()
        self.state = RuntimeState()
        self.ffmpeg_path = self.resolve_ffmpeg_path()
        self.b2 = B2Client(
            key_id=self.creds.b2_key_id,
            app_key=self.creds.b2_app_key,
            bucket_name=self.creds.b2_bucket,
            prefix=self.creds.b2_prefix,
        )
        self.wp = WordPressClient(
            base_url=self.creds.wp_base_url,
            desktop_token=self.creds.wp_desktop_token,
        )
        self.pipeline = BPMPipeline(self.db, self.b2, self.wp)
        recovered = self.db.recover_interrupted_processing()
        if recovered > 0:
            self.db.log_event("queue_recovered_after_restart", {"recovered_rows": recovered})

    def resolve_ffmpeg_path(self) -> str:
        bundled = str(FFMPEG_BUNDLED_PATH)
        if FFMPEG_BUNDLED_PATH.exists():
            return bundled
        return shutil.which("ffmpeg") or "ffmpeg"

    def save_credentials(self, creds: Credentials) -> None:
        self.creds = creds
        self.credential_store.save(creds)
        self.b2 = B2Client(
            key_id=self.creds.b2_key_id,
            app_key=self.creds.b2_app_key,
            bucket_name=self.creds.b2_bucket,
            prefix=self.creds.b2_prefix,
        )
        self.wp = WordPressClient(
            base_url=self.creds.wp_base_url,
            desktop_token=self.creds.wp_desktop_token,
        )
        self.pipeline = BPMPipeline(self.db, self.b2, self.wp)

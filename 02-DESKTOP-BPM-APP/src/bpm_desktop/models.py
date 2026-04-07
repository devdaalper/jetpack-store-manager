from __future__ import annotations

from dataclasses import dataclass
from typing import Optional


@dataclass
class Credentials:
    b2_key_id: str
    b2_app_key: str
    b2_bucket: str
    b2_prefix: str
    wp_base_url: str
    wp_desktop_token: str


@dataclass
class CatalogObject:
    path: str
    extension: str
    size: int
    etag: str
    last_modified: str
    media_kind: str


@dataclass
class AnalysisResult:
    path: str
    bpm: int
    source: str
    confidence: float
    status: str
    notes: str = ""


@dataclass
class PreflightCheck:
    name: str
    ok: bool
    details: str


@dataclass
class PublishMetrics:
    processed_rows: int
    upserted: int
    invalid_rows: int
    manual_protected: int
    duplicate_batch: bool
    batch_version: str


@dataclass
class DryRunMetrics:
    sampled: int
    detected: int
    manual_review: int
    invalid: int
    confidence_avg: Optional[float]

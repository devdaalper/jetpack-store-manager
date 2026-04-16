"""Data models used across the BPM Desktop application.

Defines dataclasses for credentials, catalog objects, analysis results,
preflight checks, and publish/dry-run metrics.
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import Optional


@dataclass
class Credentials:
    """Backblaze B2 and WordPress API credentials."""
    b2_key_id: str
    b2_app_key: str
    b2_bucket: str
    b2_prefix: str
    wp_base_url: str
    wp_desktop_token: str


@dataclass
class CatalogObject:
    """A file discovered in the B2 bucket catalog."""

    path: str
    extension: str
    size: int
    etag: str
    last_modified: str
    media_kind: str


@dataclass
class AnalysisResult:
    """Result of BPM analysis for a single audio file."""

    path: str
    bpm: int
    source: str
    confidence: float
    status: str
    notes: str = ""


@dataclass
class PreflightCheck:
    """Outcome of a single preflight validation step."""

    name: str
    ok: bool
    details: str


@dataclass
class PublishMetrics:
    """Metrics returned after publishing a BPM batch to WordPress."""

    processed_rows: int
    upserted: int
    invalid_rows: int
    manual_protected: int
    duplicate_batch: bool
    batch_version: str


@dataclass
class DryRunMetrics:
    """Metrics collected during a dry-run analysis pass."""

    sampled: int
    detected: int
    manual_review: int
    invalid: int
    confidence_avg: Optional[float]

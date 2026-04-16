"""Tests for data models."""

from __future__ import annotations

from dataclasses import asdict

from bpm_desktop.models import (
    AnalysisResult,
    CatalogObject,
    Credentials,
    DryRunMetrics,
    PreflightCheck,
    PublishMetrics,
)


def test_credentials_fields():
    c = Credentials(
        b2_key_id="k",
        b2_app_key="s",
        b2_bucket="b",
        b2_prefix="p/",
        wp_base_url="https://wp.test",
        wp_desktop_token="tok",
    )
    assert c.b2_key_id == "k"
    assert c.wp_base_url == "https://wp.test"


def test_catalog_object():
    obj = CatalogObject(
        path="music/track.mp3",
        extension="mp3",
        size=123456,
        etag="abc",
        last_modified="1234",
        media_kind="audio",
    )
    assert obj.path == "music/track.mp3"
    assert obj.media_kind == "audio"


def test_analysis_result_default_notes():
    r = AnalysisResult(path="x.mp3", bpm=120, source="path_pattern", confidence=0.72, status="done")
    assert r.notes == ""


def test_preflight_check_ok():
    check = PreflightCheck(name="disk", ok=True, details="5 GB free")
    assert check.ok is True


def test_publish_metrics():
    m = PublishMetrics(
        processed_rows=100,
        upserted=95,
        invalid_rows=5,
        manual_protected=0,
        duplicate_batch=False,
        batch_version="v1",
    )
    d = asdict(m)
    assert d["upserted"] == 95
    assert d["duplicate_batch"] is False


def test_dry_run_metrics_optional_avg():
    m = DryRunMetrics(sampled=50, detected=40, manual_review=5, invalid=5, confidence_avg=None)
    assert m.confidence_avg is None

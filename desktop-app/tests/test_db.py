"""Tests for the database layer."""

from __future__ import annotations

from pathlib import Path

from bpm_desktop.db import AppDB
from bpm_desktop.models import AnalysisResult, CatalogObject


def test_init_creates_tables(tmp_path: Path):
    db = AppDB(db_path=tmp_path / "test.sqlite")
    metrics = db.get_metrics()
    assert "audio_total" in metrics
    assert int(metrics["audio_total"]) == 0


def test_upsert_catalog_objects(tmp_path: Path):
    db = AppDB(db_path=tmp_path / "test.sqlite")
    objs = [
        CatalogObject(path="a.mp3", extension="mp3", size=100, etag="e1", last_modified="1", media_kind="audio"),
        CatalogObject(path="b.wav", extension="wav", size=200, etag="e2", last_modified="2", media_kind="audio"),
    ]
    inserted = db.upsert_catalog_objects(objs)
    assert inserted == 2
    metrics = db.get_metrics()
    assert int(metrics["audio_total"]) == 2


def test_upsert_is_idempotent(tmp_path: Path):
    db = AppDB(db_path=tmp_path / "test.sqlite")
    obj = CatalogObject(path="a.mp3", extension="mp3", size=100, etag="e1", last_modified="1", media_kind="audio")
    db.upsert_catalog_objects([obj])
    db.upsert_catalog_objects([obj])
    metrics = db.get_metrics()
    assert int(metrics["audio_total"]) == 1


def test_seed_queue_from_catalog(tmp_path: Path):
    db = AppDB(db_path=tmp_path / "test.sqlite")
    objs = [
        CatalogObject(path="a.mp3", extension="mp3", size=100, etag="e1", last_modified="1", media_kind="audio"),
        CatalogObject(path="b.wav", extension="wav", size=200, etag="e2", last_modified="2", media_kind="audio"),
    ]
    db.upsert_catalog_objects(objs)
    enqueued = db.seed_queue_from_catalog()
    assert enqueued == 2


def test_fetch_queue_batch(tmp_path: Path):
    db = AppDB(db_path=tmp_path / "test.sqlite")
    objs = [CatalogObject(path=f"track_{i}.mp3", extension="mp3", size=100, etag=f"e{i}", last_modified=str(i), media_kind="audio") for i in range(5)]
    db.upsert_catalog_objects(objs)
    db.seed_queue_from_catalog()
    batch = db.fetch_queue_batch(limit=3)
    assert len(batch) == 3


def test_save_result_and_publish_candidates(tmp_path: Path):
    db = AppDB(db_path=tmp_path / "test.sqlite")
    obj = CatalogObject(path="a.mp3", extension="mp3", size=100, etag="e1", last_modified="1", media_kind="audio")
    db.upsert_catalog_objects([obj])
    result = AnalysisResult(path="a.mp3", bpm=128, source="path_pattern", confidence=0.72, status="done")
    db.save_result(result)
    candidates = db.get_publish_candidates(limit=100)
    assert len(candidates) >= 1
    assert int(candidates[0]["bpm"]) == 128


def test_log_event_and_load(tmp_path: Path):
    db = AppDB(db_path=tmp_path / "test.sqlite")
    db.log_event("test_event", {"key": "value"})
    events = db.load_last_event_rows(limit=10)
    assert len(events) == 1
    assert events[0]["event_type"] == "test_event"


def test_recover_interrupted_processing(tmp_path: Path):
    db = AppDB(db_path=tmp_path / "test.sqlite")
    objs = [CatalogObject(path="a.mp3", extension="mp3", size=100, etag="e1", last_modified="1", media_kind="audio")]
    db.upsert_catalog_objects(objs)
    db.seed_queue_from_catalog()
    # Simulate an interrupted row
    db.mark_queue_state("a.mp3", "processing")
    recovered = db.recover_interrupted_processing()
    assert recovered == 1
    # After recovery, the row should be fetchable again
    batch = db.fetch_queue_batch(limit=10)
    assert any(r["path"] == "a.mp3" for r in batch)


def test_manual_review_flow(tmp_path: Path):
    db = AppDB(db_path=tmp_path / "test.sqlite")
    obj = CatalogObject(path="a.mp3", extension="mp3", size=100, etag="e1", last_modified="1", media_kind="audio")
    db.upsert_catalog_objects([obj])
    db.enqueue_manual_review("a.mp3", 128, 0.55)
    assert db.pending_review_count() == 1
    rows = db.get_manual_review_rows(limit=10)
    assert len(rows) == 1
    db.apply_manual_review("a.mp3", 130)
    assert db.pending_review_count() == 0

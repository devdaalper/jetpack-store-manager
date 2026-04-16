"""SQLite persistence layer for catalog, analysis queue, results, and publish batches."""

from __future__ import annotations

import json
import logging
import sqlite3
import threading
from contextlib import contextmanager
from pathlib import Path
from typing import Any, Iterable

from .constants import DB_PATH, EXPORTS_DIR, LOCAL_DATA_DIR, LOGS_DIR
from .models import AnalysisResult, CatalogObject

logger = logging.getLogger(__name__)


class AppDB:
    """Thread-safe SQLite database for the BPM Desktop application."""

    def __init__(self, db_path: Path = DB_PATH) -> None:
        self.db_path = db_path
        self._lock = threading.RLock()
        self._ensure_dirs()
        self._init_schema()
        logger.info("Database ready: %s", self.db_path)

    def _ensure_dirs(self) -> None:
        LOCAL_DATA_DIR.mkdir(parents=True, exist_ok=True)
        EXPORTS_DIR.mkdir(parents=True, exist_ok=True)
        LOGS_DIR.mkdir(parents=True, exist_ok=True)

    @contextmanager
    def _connect(self):
        con = sqlite3.connect(self.db_path)
        con.row_factory = sqlite3.Row
        con.execute("PRAGMA journal_mode=WAL;")
        con.execute("PRAGMA foreign_keys=ON;")
        try:
            yield con
            con.commit()
        finally:
            con.close()

    def _init_schema(self) -> None:
        with self._lock, self._connect() as con:
            con.executescript(
                """
                CREATE TABLE IF NOT EXISTS catalog_objects (
                    path TEXT PRIMARY KEY,
                    extension TEXT NOT NULL,
                    size INTEGER NOT NULL DEFAULT 0,
                    etag TEXT NOT NULL DEFAULT '',
                    last_modified TEXT NOT NULL DEFAULT '',
                    media_kind TEXT NOT NULL DEFAULT 'other',
                    discovered_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE IF NOT EXISTS analysis_queue (
                    path TEXT PRIMARY KEY,
                    status TEXT NOT NULL DEFAULT 'pending',
                    attempts INTEGER NOT NULL DEFAULT 0,
                    priority INTEGER NOT NULL DEFAULT 100,
                    last_error TEXT NOT NULL DEFAULT '',
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY(path) REFERENCES catalog_objects(path) ON DELETE CASCADE
                );

                CREATE TABLE IF NOT EXISTS analysis_results (
                    path TEXT PRIMARY KEY,
                    bpm INTEGER NOT NULL DEFAULT 0,
                    source TEXT NOT NULL DEFAULT '',
                    confidence REAL NOT NULL DEFAULT 0,
                    status TEXT NOT NULL DEFAULT 'pending',
                    notes TEXT NOT NULL DEFAULT '',
                    analyzed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    published_batch_id TEXT NOT NULL DEFAULT '',
                    FOREIGN KEY(path) REFERENCES catalog_objects(path) ON DELETE CASCADE
                );

                CREATE TABLE IF NOT EXISTS manual_review_queue (
                    path TEXT PRIMARY KEY,
                    suggested_bpm INTEGER NOT NULL DEFAULT 0,
                    suggested_confidence REAL NOT NULL DEFAULT 0,
                    reviewer_state TEXT NOT NULL DEFAULT 'pending',
                    chosen_bpm INTEGER NOT NULL DEFAULT 0,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY(path) REFERENCES catalog_objects(path) ON DELETE CASCADE
                );

                CREATE TABLE IF NOT EXISTS publish_batches (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    batch_id TEXT NOT NULL UNIQUE,
                    mode TEXT NOT NULL,
                    profile TEXT NOT NULL,
                    status TEXT NOT NULL,
                    payload_hash TEXT NOT NULL,
                    metrics_json TEXT NOT NULL DEFAULT '{}',
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE IF NOT EXISTS event_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    event_type TEXT NOT NULL,
                    payload_json TEXT NOT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
                """
            )

    def log_event(self, event_type: str, payload: dict[str, Any]) -> None:
        """Append an event to the event_log table."""
        with self._lock, self._connect() as con:
            con.execute(
                "INSERT INTO event_log(event_type, payload_json) VALUES(?, ?)",
                (event_type, json.dumps(payload, ensure_ascii=False)),
            )

    def upsert_catalog_objects(self, rows: Iterable[CatalogObject]) -> int:
        """Insert or update catalog objects, returning the number of rows processed."""
        inserted = 0
        with self._lock, self._connect() as con:
            for row in rows:
                con.execute(
                    """
                    INSERT INTO catalog_objects(path, extension, size, etag, last_modified, media_kind)
                    VALUES(?, ?, ?, ?, ?, ?)
                    ON CONFLICT(path) DO UPDATE SET
                      extension=excluded.extension,
                      size=excluded.size,
                      etag=excluded.etag,
                      last_modified=excluded.last_modified,
                      media_kind=excluded.media_kind,
                      discovered_at=CURRENT_TIMESTAMP
                    """,
                    (
                        row.path,
                        row.extension,
                        row.size,
                        row.etag,
                        row.last_modified,
                        row.media_kind,
                    ),
                )
                inserted += 1
        return inserted

    def seed_queue_from_catalog(self) -> int:
        """Populate the analysis queue with audio files that lack BPM results."""
        with self._lock, self._connect() as con:
            cur = con.execute(
                """
                INSERT INTO analysis_queue(path, status, priority)
                SELECT c.path, 'pending', 100
                FROM catalog_objects c
                LEFT JOIN analysis_results r ON r.path = c.path
                WHERE c.media_kind='audio'
                  AND (r.path IS NULL OR r.bpm = 0)
                ON CONFLICT(path) DO NOTHING
                """
            )
            return cur.rowcount if cur.rowcount != -1 else 0

    def fetch_queue_batch(self, limit: int = 200) -> list[sqlite3.Row]:
        """Fetch and mark a batch of pending/failed queue items as processing."""
        with self._lock, self._connect() as con:
            rows = con.execute(
                """
                SELECT q.path, q.status, q.attempts, c.extension
                FROM analysis_queue q
                JOIN catalog_objects c ON c.path = q.path
                WHERE q.status IN ('pending', 'failed')
                ORDER BY q.priority ASC, q.updated_at ASC
                LIMIT ?
                """,
                (max(1, int(limit)),),
            ).fetchall()
            for row in rows:
                con.execute(
                    "UPDATE analysis_queue SET status='processing', attempts=attempts+1, updated_at=CURRENT_TIMESTAMP WHERE path=?",
                    (row["path"],),
                )
            return rows

    def recover_interrupted_processing(self) -> int:
        """Reset rows stuck in 'processing' state back to 'pending'."""
        with self._lock, self._connect() as con:
            cur = con.execute(
                """
                UPDATE analysis_queue
                SET status='pending',
                    last_error='recovered_after_restart',
                    updated_at=CURRENT_TIMESTAMP
                WHERE status='processing'
                """
            )
            return int(cur.rowcount if cur.rowcount != -1 else 0)

    def mark_queue_state(self, path: str, status: str, last_error: str = "") -> None:
        """Update the status of a queue item by path."""
        with self._lock, self._connect() as con:
            con.execute(
                "UPDATE analysis_queue SET status=?, last_error=?, updated_at=CURRENT_TIMESTAMP WHERE path=?",
                (status, last_error, path),
            )

    def save_result(self, result: AnalysisResult) -> None:
        """Upsert a BPM analysis result."""
        with self._lock, self._connect() as con:
            con.execute(
                """
                INSERT INTO analysis_results(path, bpm, source, confidence, status, notes, analyzed_at)
                VALUES(?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                ON CONFLICT(path) DO UPDATE SET
                  bpm=excluded.bpm,
                  source=excluded.source,
                  confidence=excluded.confidence,
                  status=excluded.status,
                  notes=excluded.notes,
                  analyzed_at=CURRENT_TIMESTAMP
                """,
                (
                    result.path,
                    result.bpm,
                    result.source,
                    result.confidence,
                    result.status,
                    result.notes,
                ),
            )

    def enqueue_manual_review(self, path: str, suggested_bpm: int, suggested_confidence: float) -> None:
        """Add or update an item in the manual review queue."""
        with self._lock, self._connect() as con:
            con.execute(
                """
                INSERT INTO manual_review_queue(path, suggested_bpm, suggested_confidence, reviewer_state, chosen_bpm, updated_at)
                VALUES(?, ?, ?, 'pending', 0, CURRENT_TIMESTAMP)
                ON CONFLICT(path) DO UPDATE SET
                  suggested_bpm=excluded.suggested_bpm,
                  suggested_confidence=excluded.suggested_confidence,
                  reviewer_state='pending',
                  updated_at=CURRENT_TIMESTAMP
                """,
                (path, int(suggested_bpm), float(suggested_confidence)),
            )

    def get_manual_review_rows(self, limit: int = 1000) -> list[sqlite3.Row]:
        """Return pending manual-review items joined with their analysis results."""
        with self._lock, self._connect() as con:
            return con.execute(
                """
                SELECT mr.path, mr.suggested_bpm, mr.suggested_confidence, mr.reviewer_state, mr.chosen_bpm,
                       ar.bpm AS detected_bpm, ar.source, ar.confidence
                FROM manual_review_queue mr
                LEFT JOIN analysis_results ar ON ar.path = mr.path
                WHERE mr.reviewer_state='pending'
                ORDER BY mr.updated_at ASC
                LIMIT ?
                """,
                (max(1, int(limit)),),
            ).fetchall()

    def apply_manual_review(self, path: str, chosen_bpm: int) -> None:
        """Accept a manual review decision and update results and queue accordingly."""
        chosen_bpm = int(chosen_bpm)
        with self._lock, self._connect() as con:
            con.execute(
                "UPDATE manual_review_queue SET reviewer_state='approved', chosen_bpm=?, updated_at=CURRENT_TIMESTAMP WHERE path=?",
                (chosen_bpm, path),
            )
            con.execute(
                """
                INSERT INTO analysis_results(path, bpm, source, confidence, status, notes, analyzed_at)
                VALUES(?, ?, 'manual_review', 1.0, 'done', 'Revisión manual', CURRENT_TIMESTAMP)
                ON CONFLICT(path) DO UPDATE SET
                  bpm=excluded.bpm,
                  source=excluded.source,
                  confidence=excluded.confidence,
                  status=excluded.status,
                  notes=excluded.notes,
                  analyzed_at=CURRENT_TIMESTAMP
                """,
                (path, chosen_bpm),
            )
            con.execute(
                "UPDATE analysis_queue SET status='done', last_error='', updated_at=CURRENT_TIMESTAMP WHERE path=?",
                (path,),
            )

    def pending_review_count(self) -> int:
        """Return the number of items awaiting manual review."""
        with self._lock, self._connect() as con:
            row = con.execute("SELECT COUNT(*) AS c FROM manual_review_queue WHERE reviewer_state='pending'").fetchone()
            return int(row["c"]) if row else 0

    def get_metrics(self) -> dict[str, Any]:
        """Return aggregate metrics: audio total, queue pending, BPM coverage, etc."""
        with self._lock, self._connect() as con:
            totals = con.execute(
                """
                SELECT
                  (SELECT COUNT(*) FROM catalog_objects WHERE media_kind='audio') AS audio_total,
                  (SELECT COUNT(*) FROM analysis_queue WHERE status IN ('pending','processing','failed')) AS queue_pending,
                  (SELECT COUNT(*) FROM analysis_results WHERE bpm > 0) AS with_bpm,
                  (SELECT COUNT(*) FROM analysis_results WHERE status='manual_review') AS manual_review
                """
            ).fetchone()
            if not totals:
                return {
                    "audio_total": 0,
                    "queue_pending": 0,
                    "with_bpm": 0,
                    "manual_review": 0,
                    "coverage_pct": 0.0,
                }
            audio_total = int(totals["audio_total"])
            with_bpm = int(totals["with_bpm"])
            coverage = round((with_bpm / audio_total) * 100.0, 2) if audio_total > 0 else 0.0
            return {
                "audio_total": audio_total,
                "queue_pending": int(totals["queue_pending"]),
                "with_bpm": with_bpm,
                "manual_review": int(totals["manual_review"]),
                "coverage_pct": coverage,
            }

    def get_publish_candidates(self, limit: int = 5000) -> list[sqlite3.Row]:
        """Fetch analysis results with BPM > 0 that have not yet been published."""
        with self._lock, self._connect() as con:
            return con.execute(
                """
                SELECT path, bpm, source, confidence, analyzed_at
                FROM analysis_results
                WHERE bpm > 0
                  AND status='done'
                  AND (published_batch_id='' OR published_batch_id IS NULL)
                ORDER BY analyzed_at ASC
                LIMIT ?
                """,
                (max(1, int(limit)),),
            ).fetchall()

    def mark_rows_published(self, paths: Iterable[str], batch_id: str) -> int:
        """Tag analysis results as published under the given batch ID."""
        count = 0
        with self._lock, self._connect() as con:
            for path in paths:
                con.execute(
                    "UPDATE analysis_results SET published_batch_id=? WHERE path=?",
                    (batch_id, path),
                )
                count += 1
        return count

    def record_publish_batch(
        self,
        batch_id: str,
        mode: str,
        profile: str,
        status: str,
        payload_hash: str,
        metrics: dict[str, Any],
    ) -> None:
        """Record a publish batch with its metadata and metrics."""
        with self._lock, self._connect() as con:
            con.execute(
                """
                INSERT INTO publish_batches(batch_id, mode, profile, status, payload_hash, metrics_json, created_at)
                VALUES(?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                ON CONFLICT(batch_id) DO UPDATE SET
                  mode=excluded.mode,
                  profile=excluded.profile,
                  status=excluded.status,
                  payload_hash=excluded.payload_hash,
                  metrics_json=excluded.metrics_json
                """,
                (
                    batch_id,
                    mode,
                    profile,
                    status,
                    payload_hash,
                    json.dumps(metrics, ensure_ascii=False),
                ),
            )

    def update_publish_batch_status(self, batch_id: str, status: str, metrics: dict[str, Any] | None = None) -> None:
        """Update the status (and optionally metrics) of an existing publish batch."""
        with self._lock, self._connect() as con:
            fields = ["status=?"]
            params: list[Any] = [status]
            if metrics is not None:
                fields.append("metrics_json=?")
                params.append(json.dumps(metrics, ensure_ascii=False))
            params.append(batch_id)
            con.execute(
                f"UPDATE publish_batches SET {', '.join(fields)} WHERE batch_id=?",
                tuple(params),
            )

    def get_recent_publish_batches(self, limit: int = 50, mode: str | None = None) -> list[sqlite3.Row]:
        """Return the most recent publish batches, optionally filtered by mode."""
        with self._lock, self._connect() as con:
            base_sql = """
                SELECT batch_id, mode, profile, status, payload_hash, metrics_json, created_at
                FROM publish_batches
            """
            params: list[Any] = []
            if mode:
                base_sql += " WHERE mode=?"
                params.append(mode)
            base_sql += " ORDER BY id DESC LIMIT ?"
            params.append(max(1, int(limit)))
            return con.execute(base_sql, tuple(params)).fetchall()

    def load_last_event_rows(self, limit: int = 200) -> list[sqlite3.Row]:
        """Return the most recent event log entries in reverse chronological order."""
        with self._lock, self._connect() as con:
            return con.execute(
                "SELECT event_type, payload_json, created_at FROM event_log ORDER BY id DESC LIMIT ?",
                (max(1, int(limit)),),
            ).fetchall()

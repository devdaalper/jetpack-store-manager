"""Orchestrates the full BPM pipeline: catalog sync, analysis, dry-run, backfill, and publish."""

from __future__ import annotations

import hashlib
import logging
import uuid
from dataclasses import asdict
from typing import Any, Callable

from .b2_client import B2Client
from .bpm_engine import detect_bpm_from_text, estimate_bpm_acoustic, extract_bpm_from_mp3_head
from .constants import MAX_API_ROWS_PER_BATCH, PROFILE_SETTINGS
from .csv_exporter import export_bpm_rows_chunked
from .db import AppDB
from .models import AnalysisResult, DryRunMetrics
from .quality_gate import QualityGateResult, evaluate_quality_gate
from .wp_client import WordPressClient


logger = logging.getLogger(__name__)


class BPMPipeline:
    """End-to-end BPM processing pipeline from B2 catalog to WordPress publish."""

    def __init__(self, db: AppDB, b2_client: B2Client, wp_client: WordPressClient) -> None:
        self.db = db
        self.b2_client = b2_client
        self.wp_client = wp_client

    def sync_catalog(
        self,
        max_pages: int = 250,
        progress_callback: Callable[[dict[str, Any]], None] | None = None,
    ) -> dict[str, Any]:
        """Scan B2 for audio files and upsert them into the local catalog and queue."""
        max_pages = max(1, int(max_pages))
        count = 0
        inserted = 0
        pages = 0
        start_file_name = None
        rows_buffer = []

        if progress_callback is not None:
            progress_callback(
                {
                    "stage": "start",
                    "pages": 0,
                    "max_pages": max_pages,
                    "scanned_audio": 0,
                    "upserted": 0,
                }
            )

        while pages < max_pages:
            page_rows, next_file_name = self.b2_client.list_audio_page(
                start_file_name=start_file_name,
                max_file_count=1000,
            )
            pages += 1
            count += len(page_rows)
            rows_buffer.extend(page_rows)
            if len(rows_buffer) >= 1000:
                inserted += self.db.upsert_catalog_objects(rows_buffer)
                rows_buffer = []

            if progress_callback is not None:
                progress_callback(
                    {
                        "stage": "page",
                        "pages": pages,
                        "max_pages": max_pages,
                        "scanned_audio": count,
                        "upserted": inserted,
                    }
                )

            if not next_file_name:
                break
            start_file_name = next_file_name

        if rows_buffer:
            inserted += self.db.upsert_catalog_objects(rows_buffer)

        enqueued = self.db.seed_queue_from_catalog()
        if progress_callback is not None:
            progress_callback(
                {
                    "stage": "done",
                    "pages": pages,
                    "max_pages": max_pages,
                    "scanned_audio": count,
                    "upserted": inserted,
                    "enqueued": enqueued,
                }
            )

        logger.info("Catalog sync complete: %d audio scanned, %d upserted, %d enqueued (%d pages)", count, inserted, enqueued, pages)
        self.db.log_event(
            "catalog_sync",
            {
                "scanned_audio": count,
                "upserted": inserted,
                "enqueued": enqueued,
                "max_pages": max_pages,
                "pages_processed": pages,
            },
        )
        return {
            "scanned_audio": count,
            "upserted": inserted,
            "enqueued": enqueued,
            "pages_processed": pages,
            "max_pages": max_pages,
        }

    def _should_run_acoustic(self, path: str, profile: str) -> bool:
        cfg = PROFILE_SETTINGS.get(profile, PROFILE_SETTINGS["balanced"])
        ratio = float(cfg.get("acoustic_ratio", 0.6))
        h = hashlib.md5(path.encode("utf-8")).hexdigest()
        n = int(h[:4], 16) / 0xFFFF
        return n <= ratio

    def _analyze_path(self, path: str, profile: str, ffmpeg_path: str | None) -> AnalysisResult:
        extension = path.rsplit(".", 1)[-1].lower() if "." in path else ""

        if extension == "mp3":
            try:
                head = self.b2_client.fetch_head_bytes(path, max_bytes=262143)
                tagged = extract_bpm_from_mp3_head(head)
                if tagged is not None:
                    return AnalysisResult(
                        path=path,
                        bpm=tagged.bpm,
                        source=tagged.source,
                        confidence=tagged.confidence,
                        status="done",
                        notes=tagged.notes,
                    )
            except (OSError, ValueError) as exc:
                logger.debug("MP3 head extraction failed for %s: %s", path, exc)

        txt = detect_bpm_from_text(path)
        if txt is not None:
            return AnalysisResult(
                path=path,
                bpm=txt.bpm,
                source=txt.source,
                confidence=txt.confidence,
                status="done" if txt.confidence >= PROFILE_SETTINGS[profile]["confidence_floor"] else "manual_review",
                notes=txt.notes,
            )

        if ffmpeg_path and self._should_run_acoustic(path, profile):
            try:
                auth = self.b2_client.session or self.b2_client.authorize()
                url = self.b2_client.build_download_url(path)
                est = estimate_bpm_acoustic(
                    ffmpeg_path=ffmpeg_path,
                    url=url,
                    auth_token=auth.authorization_token,
                    sample_seconds=int(PROFILE_SETTINGS[profile]["sample_seconds"]),
                )
                if est is not None and est.bpm > 0:
                    status = "done" if est.confidence >= PROFILE_SETTINGS[profile]["confidence_floor"] else "manual_review"
                    return AnalysisResult(
                        path=path,
                        bpm=est.bpm,
                        source=est.source,
                        confidence=est.confidence,
                        status=status,
                        notes=est.notes,
                    )
            except Exception as exc:
                logger.warning("Acoustic analysis failed for %s: %s", path, exc)
                return AnalysisResult(
                    path=path,
                    bpm=0,
                    source="",
                    confidence=0.0,
                    status="failed",
                    notes=f"acoustic_error: {exc}",
                )

        return AnalysisResult(
            path=path,
            bpm=0,
            source="",
            confidence=0.0,
            status="manual_review",
            notes="Sin BPM automático confiable",
        )

    def run_dry_run(
        self,
        sample_size: int,
        profile: str,
        ffmpeg_path: str | None,
        progress_callback: Callable[[dict[str, Any]], None] | None = None,
    ) -> DryRunMetrics:
        """Analyze a sample of queued files without persisting results to validate settings."""
        sample_size = max(10, min(int(sample_size), 3000))
        rows = self.db.fetch_queue_batch(limit=sample_size)
        total = len(rows)

        detected = 0
        invalid = 0
        manual_review = 0
        confidence_sum = 0.0
        confidence_count = 0

        if progress_callback is not None:
            progress_callback(
                {
                    "stage": "start",
                    "processed": 0,
                    "total": total,
                    "detected": detected,
                    "manual_review": manual_review,
                    "invalid": invalid,
                    "path": "",
                }
            )

        for index, row in enumerate(rows, start=1):
            path = str(row["path"])
            if progress_callback is not None:
                progress_callback(
                    {
                        "stage": "processing",
                        "processed": index - 1,
                        "total": total,
                        "detected": detected,
                        "manual_review": manual_review,
                        "invalid": invalid,
                        "path": path,
                    }
                )

            result = self._analyze_path(path, profile, ffmpeg_path)
            if result.bpm > 0:
                detected += 1
                confidence_sum += result.confidence
                confidence_count += 1
            elif result.status == "failed":
                invalid += 1
            else:
                manual_review += 1

            self.db.mark_queue_state(path, "pending")
            if progress_callback is not None:
                progress_callback(
                    {
                        "stage": "processed",
                        "processed": index,
                        "total": total,
                        "detected": detected,
                        "manual_review": manual_review,
                        "invalid": invalid,
                        "path": path,
                    }
                )

        avg = (confidence_sum / confidence_count) if confidence_count > 0 else None
        metrics = DryRunMetrics(
            sampled=len(rows),
            detected=detected,
            manual_review=manual_review,
            invalid=invalid,
            confidence_avg=round(avg, 4) if avg is not None else None,
        )
        if progress_callback is not None:
            progress_callback(
                {
                    "stage": "done",
                    "processed": total,
                    "total": total,
                    "detected": detected,
                    "manual_review": manual_review,
                    "invalid": invalid,
                    "path": "",
                }
            )
        logger.info("Dry run done: %d sampled, %d detected, %d manual_review, %d invalid", total, detected, manual_review, invalid)
        self.db.log_event("dry_run", asdict(metrics))
        return metrics

    def process_backfill_batch(
        self,
        limit: int,
        profile: str,
        ffmpeg_path: str | None,
        progress_callback: Callable[[dict[str, Any]], None] | None = None,
    ) -> dict[str, Any]:
        """Analyze a batch of queued files and persist BPM results to the database."""
        rows = self.db.fetch_queue_batch(limit=limit)
        total = len(rows)
        processed = 0
        detected = 0
        manual_review = 0
        failed = 0

        if progress_callback is not None:
            progress_callback(
                {
                    "stage": "start",
                    "processed": 0,
                    "total": total,
                    "detected": detected,
                    "manual_review": manual_review,
                    "failed": failed,
                    "path": "",
                }
            )

        for row in rows:
            path = str(row["path"])
            processed += 1
            if progress_callback is not None:
                progress_callback(
                    {
                        "stage": "processing",
                        "processed": processed - 1,
                        "total": total,
                        "detected": detected,
                        "manual_review": manual_review,
                        "failed": failed,
                        "path": path,
                    }
                )
            result = self._analyze_path(path, profile, ffmpeg_path)

            if result.status == "done" and result.bpm > 0:
                detected += 1
                self.db.save_result(result)
                self.db.mark_queue_state(path, "done")
            elif result.status == "manual_review":
                manual_review += 1
                if result.bpm > 0:
                    self.db.save_result(result)
                self.db.enqueue_manual_review(path, result.bpm, result.confidence)
                self.db.mark_queue_state(path, "manual_review")
            else:
                failed += 1
                self.db.mark_queue_state(path, "failed", result.notes)
            if progress_callback is not None:
                progress_callback(
                    {
                        "stage": "processed",
                        "processed": processed,
                        "total": total,
                        "detected": detected,
                        "manual_review": manual_review,
                        "failed": failed,
                        "path": path,
                    }
                )

        metrics = self.db.get_metrics()
        payload = {
            "processed": processed,
            "detected": detected,
            "manual_review": manual_review,
            "failed": failed,
            "queue_pending": metrics["queue_pending"],
            "coverage_pct": metrics["coverage_pct"],
        }
        if progress_callback is not None:
            progress_callback(
                {
                    "stage": "done",
                    "processed": processed,
                    "total": total,
                    "detected": detected,
                    "manual_review": manual_review,
                    "failed": failed,
                    "path": "",
                    "queue_pending": metrics["queue_pending"],
                    "coverage_pct": metrics["coverage_pct"],
                }
            )
        logger.info(
            "Backfill batch: %d processed, %d detected, %d manual_review, %d failed (%.1f%% coverage)",
            processed, detected, manual_review, failed, metrics["coverage_pct"],
        )
        self.db.log_event("backfill_batch", payload)
        return payload

    def get_publish_rows(self, limit: int = 5000) -> list[dict[str, Any]]:
        """Return unpublished analysis results formatted for export or API publish."""
        rows = self.db.get_publish_candidates(limit=limit)
        out: list[dict[str, Any]] = []
        for row in rows:
            out.append(
                {
                    "path": str(row["path"]),
                    "bpm": int(row["bpm"]),
                    "source": str(row["source"] or "desktop_app"),
                    "confidence": float(row["confidence"]),
                    "analyzed_at": str(row["analyzed_at"]),
                }
            )
        return out

    def export_csv(
        self,
        rows: list[dict[str, Any]],
        progress_callback: Callable[[dict[str, Any]], None] | None = None,
    ) -> list[str]:
        """Export BPM rows to one or more chunked CSV files and return file paths."""
        if progress_callback is not None:
            progress_callback({"stage": "start", "rows": len(rows)})
        files = export_bpm_rows_chunked(rows)
        self.db.log_event(
            "csv_export",
            {
                "files": [str(p) for p in files],
                "rows": len(rows),
            },
        )
        if progress_callback is not None:
            progress_callback({"stage": "done", "rows": len(rows), "files": [str(p) for p in files]})
        return [str(p) for p in files]

    def publish_api(
        self,
        profile: str,
        rows: list[dict[str, Any]],
        progress_callback: Callable[[dict[str, Any]], None] | None = None,
    ) -> dict[str, Any]:
        """Publish BPM rows to WordPress via the API in chunked batches."""
        if not rows:
            return {
                "processed_rows": 0,
                "upserted": 0,
                "invalid_rows": 0,
                "manual_protected": 0,
                "duplicate_batch": False,
                "batch_version": "",
                "batch_ids": [],
                "chunks": 0,
            }

        chunk_size = max(1, int(MAX_API_ROWS_PER_BATCH))
        chunk_payloads: list[dict[str, Any]] = []
        processed_rows = 0
        upserted = 0
        invalid_rows = 0
        manual_protected = 0
        duplicate_batch = True
        batch_version = ""
        batch_ids: list[str] = []

        total_chunks = (len(rows) + chunk_size - 1) // chunk_size
        if progress_callback is not None:
            progress_callback({"stage": "start", "chunks_done": 0, "chunks_total": total_chunks, "rows_total": len(rows)})

        for chunk_index, i in enumerate(range(0, len(rows), chunk_size), start=1):
            chunk_rows = rows[i : i + chunk_size]
            batch_id = str(uuid.uuid4())
            payload_hash = self.wp_client.payload_hash(chunk_rows)
            if progress_callback is not None:
                progress_callback(
                    {
                        "stage": "chunk_start",
                        "chunk_index": chunk_index,
                        "chunks_total": total_chunks,
                        "rows_chunk": len(chunk_rows),
                        "rows_total": len(rows),
                        "batch_id": batch_id,
                    }
                )
            metrics = self.wp_client.import_batch(batch_id=batch_id, profile=profile, rows=chunk_rows)
            status = "duplicate" if metrics.duplicate_batch else "applied"

            self.db.record_publish_batch(
                batch_id=batch_id,
                mode="api",
                profile=profile,
                status=status,
                payload_hash=payload_hash,
                metrics=asdict(metrics),
            )
            if not metrics.duplicate_batch:
                self.db.mark_rows_published([row["path"] for row in chunk_rows], batch_id=batch_id)
                duplicate_batch = False

            batch_ids.append(batch_id)
            batch_version = metrics.batch_version or batch_version
            processed_rows += int(metrics.processed_rows)
            upserted += int(metrics.upserted)
            invalid_rows += int(metrics.invalid_rows)
            manual_protected += int(metrics.manual_protected)
            chunk_payloads.append(
                {
                    "batch_id": batch_id,
                    **asdict(metrics),
                }
            )
            if progress_callback is not None:
                progress_callback(
                    {
                        "stage": "chunk_done",
                        "chunk_index": chunk_index,
                        "chunks_total": total_chunks,
                        "rows_chunk": len(chunk_rows),
                        "processed_rows": processed_rows,
                        "upserted": upserted,
                        "invalid_rows": invalid_rows,
                        "manual_protected": manual_protected,
                        "batch_id": batch_id,
                    }
                )

        output = {
            "processed_rows": processed_rows,
            "upserted": upserted,
            "invalid_rows": invalid_rows,
            "manual_protected": manual_protected,
            "duplicate_batch": duplicate_batch,
            "batch_version": batch_version,
            "batch_ids": batch_ids,
            "chunks": len(chunk_payloads),
            "chunk_metrics": chunk_payloads,
        }
        if progress_callback is not None:
            progress_callback({"stage": "done", **output})
        logger.info(
            "Publish API: %d rows in %d chunks, %d upserted, %d invalid, duplicate=%s",
            processed_rows, len(chunk_payloads), upserted, invalid_rows, duplicate_batch,
        )
        self.db.log_event("publish_api", output)
        return output

    def rollback_api_batch(
        self,
        batch_id: str,
        progress_callback: Callable[[dict[str, Any]], None] | None = None,
    ) -> dict[str, Any]:
        """Roll back a previously published batch via the WordPress API."""
        if progress_callback is not None:
            progress_callback({"stage": "start", "batch_id": batch_id})
        data = self.wp_client.rollback_batch(batch_id=batch_id)
        metrics = {"response": data}
        self.db.update_publish_batch_status(batch_id=batch_id, status="rolled_back", metrics=metrics)
        logger.info("Rollback batch %s: %s", batch_id, data)
        self.db.log_event("publish_api_rollback", {"batch_id": batch_id, "response": data})
        if progress_callback is not None:
            progress_callback({"stage": "done", "batch_id": batch_id, "response": data})
        return data

    def evaluate_publish_quality(self, rows: list[dict[str, Any]]) -> QualityGateResult:
        """Run the quality gate on a set of rows to determine if they are safe to publish."""
        seen = set()
        duplicate = 0
        invalid = 0
        out_of_range = 0
        confidence_sum = 0.0
        confidence_count = 0

        for row in rows:
            path = str(row.get("path", "")).strip()
            bpm = int(row.get("bpm", 0) or 0)
            confidence = float(row.get("confidence", 0.0) or 0.0)

            if not path or bpm <= 0:
                invalid += 1
                continue
            if path in seen:
                duplicate += 1
            seen.add(path)

            if bpm < 40 or bpm > 260:
                out_of_range += 1

            confidence_sum += confidence
            confidence_count += 1

        avg = (confidence_sum / confidence_count) if confidence_count else 0.0
        global_metrics = self.db.get_metrics()
        audio_total = max(1, int(global_metrics.get("audio_total", 0) or 0))
        delta_coverage = len(seen) / audio_total if audio_total > 0 else 0.0
        return evaluate_quality_gate(
            {
                "processed_rows": len(rows),
                "invalid_rows": invalid,
                "duplicate_rows": duplicate,
                "rows_out_of_range": out_of_range,
                "confidence_avg": avg,
                "delta_coverage": delta_coverage,
            }
        )

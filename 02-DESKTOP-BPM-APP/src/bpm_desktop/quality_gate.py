from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from .constants import QUALITY_THRESHOLDS


@dataclass
class QualityGateResult:
    passed: bool
    reasons: list[str]
    metrics: dict[str, Any]


def evaluate_quality_gate(metrics: dict[str, Any], thresholds: dict[str, float] | None = None) -> QualityGateResult:
    t = dict(QUALITY_THRESHOLDS)
    if thresholds:
        t.update(thresholds)

    processed = max(1, int(metrics.get("processed_rows", 0)))
    invalid = max(0, int(metrics.get("invalid_rows", 0)))
    duplicates = max(0, int(metrics.get("duplicate_rows", 0)))
    out_of_range = max(0, int(metrics.get("rows_out_of_range", 0)))
    delta_coverage = max(0.0, float(metrics.get("delta_coverage", 0.0) or 0.0))
    confidence_avg = metrics.get("confidence_avg")
    confidence_avg = float(confidence_avg) if confidence_avg is not None else 0.0

    invalid_ratio = invalid / processed
    duplicate_ratio = duplicates / processed

    reasons: list[str] = []
    if invalid_ratio > float(t["invalid_ratio_max"]):
        reasons.append(
            f"invalid_ratio={invalid_ratio:.4f} supera {float(t['invalid_ratio_max']):.4f}"
        )
    if duplicate_ratio > float(t["duplicate_ratio_max"]):
        reasons.append(
            f"duplicate_ratio={duplicate_ratio:.4f} supera {float(t['duplicate_ratio_max']):.4f}"
        )
    if out_of_range > int(t["rows_out_of_range_max"]):
        reasons.append(f"rows_out_of_range={out_of_range} > {int(t['rows_out_of_range_max'])}")
    if delta_coverage > float(t["delta_coverage_max"]):
        reasons.append(
            f"delta_coverage={delta_coverage:.4f} supera {float(t['delta_coverage_max']):.4f}"
        )
    if confidence_avg < float(t["confidence_avg_min"]):
        reasons.append(
            f"confidence_avg={confidence_avg:.4f} por debajo de {float(t['confidence_avg_min']):.4f}"
        )

    return QualityGateResult(
        passed=len(reasons) == 0,
        reasons=reasons,
        metrics={
            "processed_rows": processed,
            "invalid_rows": invalid,
            "duplicate_rows": duplicates,
            "rows_out_of_range": out_of_range,
            "delta_coverage": delta_coverage,
            "confidence_avg": confidence_avg,
            "invalid_ratio": invalid_ratio,
            "duplicate_ratio": duplicate_ratio,
        },
    )

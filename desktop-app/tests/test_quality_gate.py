"""Tests for the quality gate evaluator."""

from __future__ import annotations

from bpm_desktop.quality_gate import evaluate_quality_gate


def _make_metrics(**overrides):
    defaults = {
        "processed_rows": 1000,
        "invalid_rows": 0,
        "duplicate_rows": 0,
        "rows_out_of_range": 0,
        "delta_coverage": 0.10,
        "confidence_avg": 0.80,
    }
    defaults.update(overrides)
    return defaults


def test_gate_passes_with_good_metrics():
    result = evaluate_quality_gate(_make_metrics())
    assert result.passed is True
    assert result.reasons == []


def test_gate_fails_on_high_invalid_ratio():
    result = evaluate_quality_gate(_make_metrics(invalid_rows=50))
    assert result.passed is False
    assert any("invalid_ratio" in r for r in result.reasons)


def test_gate_fails_on_high_duplicate_ratio():
    result = evaluate_quality_gate(_make_metrics(duplicate_rows=20))
    assert result.passed is False
    assert any("duplicate_ratio" in r for r in result.reasons)


def test_gate_fails_on_out_of_range():
    result = evaluate_quality_gate(_make_metrics(rows_out_of_range=1))
    assert result.passed is False
    assert any("rows_out_of_range" in r for r in result.reasons)


def test_gate_fails_on_low_confidence():
    result = evaluate_quality_gate(_make_metrics(confidence_avg=0.50))
    assert result.passed is False
    assert any("confidence_avg" in r for r in result.reasons)


def test_gate_fails_on_high_delta_coverage():
    result = evaluate_quality_gate(_make_metrics(delta_coverage=0.95))
    assert result.passed is False
    assert any("delta_coverage" in r for r in result.reasons)


def test_custom_thresholds_override_defaults():
    result = evaluate_quality_gate(
        _make_metrics(invalid_rows=50),
        thresholds={"invalid_ratio_max": 0.10},
    )
    assert result.passed is True


def test_multiple_failures_reported():
    result = evaluate_quality_gate(
        _make_metrics(invalid_rows=100, duplicate_rows=50, confidence_avg=0.30)
    )
    assert result.passed is False
    assert len(result.reasons) >= 3

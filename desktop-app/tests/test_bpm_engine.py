"""Tests for the BPM detection engine (non-acoustic functions)."""

from __future__ import annotations

from bpm_desktop.bpm_engine import (
    detect_bpm_from_text,
    extract_bpm_from_mp3_head,
    parse_bpm_number,
)


# ── parse_bpm_number ────────────────────────────────────────────────


def test_parse_valid_integer():
    assert parse_bpm_number("128") == 128


def test_parse_valid_float():
    assert parse_bpm_number("126.5") == 126  # rounded


def test_parse_out_of_range_low():
    assert parse_bpm_number("30") == 0


def test_parse_out_of_range_high():
    assert parse_bpm_number("300") == 0


def test_parse_no_number():
    assert parse_bpm_number("no-number") == 0


def test_parse_embedded_number():
    assert parse_bpm_number("track_128bpm_mix") == 128


# ── detect_bpm_from_text ────────────────────────────────────────────


def test_text_bpm_suffix():
    result = detect_bpm_from_text("music/track_128bpm.mp3")
    assert result is not None
    assert result.bpm == 128
    assert result.source == "path_pattern"
    assert result.confidence == 0.72


def test_text_tempo_prefix():
    result = detect_bpm_from_text("music/tempo-140_remix.wav")
    assert result is not None
    assert result.bpm == 140


def test_text_no_match():
    result = detect_bpm_from_text("music/some-song.mp3")
    assert result is None


def test_text_out_of_range_ignored():
    result = detect_bpm_from_text("track_300bpm.mp3")
    assert result is None


def test_text_bpm_with_spaces():
    result = detect_bpm_from_text("folder/track 120 bpm.flac")
    assert result is not None
    assert result.bpm == 120


# ── extract_bpm_from_mp3_head ───────────────────────────────────────


def test_head_returns_none_for_non_id3():
    result = extract_bpm_from_mp3_head(b"\xff\xfb\x90\x00" * 100)
    assert result is None


def test_head_returns_none_for_empty():
    result = extract_bpm_from_mp3_head(b"")
    assert result is None


def test_head_returns_none_for_short_blob():
    result = extract_bpm_from_mp3_head(b"ID3")
    assert result is None


def _build_id3_with_tbpm(bpm_text: str) -> bytes:
    """Build a minimal ID3v2.3 header with a TBPM frame."""
    bpm_bytes = b"\x03" + bpm_text.encode("utf-8")  # 0x03 = UTF-8 encoding byte
    frame = b"TBPM" + len(bpm_bytes).to_bytes(4, "big") + b"\x00\x00" + bpm_bytes
    # ID3 header: "ID3" + version 2.3 + flags + synchsafe tag size
    tag_size = len(frame)
    size_bytes = bytes([
        (tag_size >> 21) & 0x7F,
        (tag_size >> 14) & 0x7F,
        (tag_size >> 7) & 0x7F,
        tag_size & 0x7F,
    ])
    return b"ID3\x03\x00\x00" + size_bytes + frame


def test_head_extracts_tbpm():
    blob = _build_id3_with_tbpm("128")
    result = extract_bpm_from_mp3_head(blob)
    assert result is not None
    assert result.bpm == 128
    assert result.source == "metadata_tbpm"
    assert result.confidence == 0.93


def test_head_extracts_tbpm_float():
    blob = _build_id3_with_tbpm("140.0")
    result = extract_bpm_from_mp3_head(blob)
    assert result is not None
    assert result.bpm == 140


def test_head_ignores_invalid_tbpm():
    blob = _build_id3_with_tbpm("not-a-number")
    result = extract_bpm_from_mp3_head(blob)
    assert result is None

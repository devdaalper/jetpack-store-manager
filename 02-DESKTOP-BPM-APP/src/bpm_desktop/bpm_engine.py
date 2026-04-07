from __future__ import annotations

import math
import re
import shutil
import subprocess
from dataclasses import dataclass
from typing import Optional

import numpy as np

from .constants import MAX_BPM, MIN_BPM

try:
    import librosa
except Exception:  # pragma: no cover - optional dependency at runtime
    librosa = None

try:
    import aubio
except Exception:  # pragma: no cover - optional dependency at runtime
    aubio = None


@dataclass
class BPMEstimate:
    bpm: int
    source: str
    confidence: float
    notes: str = ""


BPM_PATTERNS = (
    re.compile(r"(?:^|[^0-9])([4-9][0-9]|1[0-9]{2}|2[0-5][0-9])\s*(?:bpm|tempo)(?:[^a-z0-9]|$)", re.I),
    re.compile(r"(?:bpm|tempo)\s*[:\-]?\s*([4-9][0-9]|1[0-9]{2}|2[0-5][0-9])", re.I),
)


def parse_bpm_number(raw: str) -> int:
    m = re.search(r"([0-9]{2,3}(?:\.[0-9]{1,2})?)", str(raw))
    if not m:
        return 0
    value = float(m.group(1))
    if value < MIN_BPM or value > MAX_BPM:
        return 0
    return int(round(value))


def detect_bpm_from_text(path: str) -> Optional[BPMEstimate]:
    text = str(path).lower()
    for pattern in BPM_PATTERNS:
        match = pattern.search(text)
        if not match:
            continue
        bpm = parse_bpm_number(match.group(1))
        if bpm <= 0:
            continue
        return BPMEstimate(
            bpm=bpm,
            source="path_pattern",
            confidence=0.72,
            notes="Detectado por patrón en path/nombre",
        )
    return None


def extract_bpm_from_mp3_head(head_bytes: bytes) -> Optional[BPMEstimate]:
    blob = bytes(head_bytes or b"")
    if len(blob) < 10 or not blob.startswith(b"ID3"):
        return None

    def _synchsafe_to_int(data: bytes) -> int:
        if len(data) != 4:
            return 0
        return (
            ((data[0] & 0x7F) << 21)
            | ((data[1] & 0x7F) << 14)
            | ((data[2] & 0x7F) << 7)
            | (data[3] & 0x7F)
        )

    tag_size = _synchsafe_to_int(blob[6:10])
    end = min(len(blob), 10 + max(0, int(tag_size)))
    pos = 10

    while pos + 10 <= end:
        frame_id = blob[pos : pos + 4]
        if frame_id in (b"\x00\x00\x00\x00", b""):
            break
        frame_size = int.from_bytes(blob[pos + 4 : pos + 8], byteorder="big", signed=False)
        if frame_size <= 0:
            break

        frame_start = pos + 10
        frame_end = min(frame_start + frame_size, end)
        frame_data = blob[frame_start:frame_end]

        if frame_id == b"TBPM" and frame_data:
            raw = frame_data[1:] if len(frame_data) > 1 else frame_data
            for encoding in ("utf-8", "latin-1", "utf-16"):
                try:
                    text = raw.decode(encoding, errors="ignore")
                except Exception:
                    continue
                bpm = parse_bpm_number(text)
                if bpm > 0:
                    return BPMEstimate(
                        bpm=bpm,
                        source="metadata_tbpm",
                        confidence=0.93,
                        notes="ID3 TBPM",
                    )

        pos = frame_start + frame_size

    return None


def _decode_pcm_with_ffmpeg(ffmpeg_bin: str, url: str, auth_token: str, sample_seconds: int) -> np.ndarray:
    headers = f"Authorization: {auth_token}\r\n"
    cmd = [
        ffmpeg_bin,
        "-hide_banner",
        "-loglevel",
        "error",
        "-nostdin",
        "-headers",
        headers,
        "-t",
        str(max(10, min(90, int(sample_seconds)))),
        "-i",
        url,
        "-ac",
        "1",
        "-ar",
        "22050",
        "-f",
        "f32le",
        "-",
    ]
    decode_timeout = max(25, min(180, int(sample_seconds) + 45))
    try:
        proc = subprocess.run(cmd, capture_output=True, check=False, timeout=decode_timeout)
    except subprocess.TimeoutExpired as exc:
        raise RuntimeError(f"ffmpeg timeout ({decode_timeout}s): {exc}") from exc
    if proc.returncode != 0:
        raise RuntimeError(proc.stderr.decode("utf-8", errors="ignore")[:200] or "ffmpeg decode failed")

    data = proc.stdout
    if not data:
        raise RuntimeError("ffmpeg devolvió buffer vacío")

    arr = np.frombuffer(data, dtype=np.float32)
    if arr.size < 22050 * 8:
        raise RuntimeError("audio insuficiente para estimar BPM")
    return arr


def _estimate_librosa(samples: np.ndarray, sample_rate: int = 22050) -> float:
    if librosa is None:
        return 0.0
    tempo, _ = librosa.beat.beat_track(y=samples, sr=sample_rate, units="time")
    return float(tempo if np.isscalar(tempo) else tempo[0])


def _estimate_aubio(samples: np.ndarray, sample_rate: int = 22050) -> float:
    if aubio is None:
        return 0.0

    win_s = 1024
    hop_s = 512
    tempo_obj = aubio.tempo("specdiff", win_s, hop_s, sample_rate)

    beats = []
    total_frames = len(samples)
    for i in range(0, total_frames - hop_s, hop_s):
        frame = samples[i : i + hop_s].astype(np.float32)
        is_beat = tempo_obj(frame)
        if is_beat:
            beats.append(float(tempo_obj.get_last_s()))

    if len(beats) < 2:
        return 0.0

    intervals = np.diff(np.array(beats, dtype=np.float32))
    intervals = intervals[intervals > 0]
    if intervals.size == 0:
        return 0.0

    bpm = 60.0 / float(np.median(intervals))
    return float(bpm)


def normalize_half_double(bpm: float) -> float:
    if bpm <= 0:
        return 0.0
    while bpm < 70:
        bpm *= 2
    while bpm > 180:
        bpm /= 2
    return bpm


def estimate_bpm_acoustic(ffmpeg_path: str, url: str, auth_token: str, sample_seconds: int) -> Optional[BPMEstimate]:
    ffmpeg_bin = shutil.which(ffmpeg_path) if "/" not in ffmpeg_path else ffmpeg_path
    if not ffmpeg_bin:
        return None

    samples = _decode_pcm_with_ffmpeg(ffmpeg_bin, url, auth_token, sample_seconds)

    l_est = normalize_half_double(_estimate_librosa(samples))
    a_est = normalize_half_double(_estimate_aubio(samples))

    candidates = [x for x in (l_est, a_est) if x > 0]
    if not candidates:
        return None

    if len(candidates) == 1:
        bpm = parse_bpm_number(str(round(candidates[0], 2)))
        if bpm <= 0:
            return None
        return BPMEstimate(
            bpm=bpm,
            source="acoustic_single_estimator",
            confidence=0.58,
            notes="Solo un estimador disponible",
        )

    diff = abs(candidates[0] - candidates[1])
    avg = (candidates[0] + candidates[1]) / 2.0
    bpm = parse_bpm_number(str(round(avg, 2)))
    if bpm <= 0:
        return None

    if diff <= 3.0:
        confidence = 0.86
        source = "acoustic_consensus"
    elif diff <= 7.0:
        confidence = 0.68
        source = "acoustic_soft_consensus"
    else:
        confidence = 0.42
        source = "acoustic_conflict"

    return BPMEstimate(
        bpm=bpm,
        source=source,
        confidence=confidence,
        notes=f"librosa={candidates[0]:.2f}, aubio={candidates[1]:.2f}, diff={diff:.2f}",
    )

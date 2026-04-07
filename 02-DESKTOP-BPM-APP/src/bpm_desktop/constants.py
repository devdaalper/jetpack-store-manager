from __future__ import annotations

from pathlib import Path

APP_NAME = "JPSM BPM Desktop"
APP_SERVICE_NAME = "jpsm-bpm-desktop"

ROOT_DIR = Path(__file__).resolve().parents[3]
APP_DIR = Path(__file__).resolve().parents[2]
LOCAL_DATA_DIR = APP_DIR / "local_data"
EXPORTS_DIR = LOCAL_DATA_DIR / "exports"
LOGS_DIR = LOCAL_DATA_DIR / "logs"
DB_PATH = LOCAL_DATA_DIR / "bpm_desktop.sqlite"

FFMPEG_BUNDLED_PATH = APP_DIR / "resources" / "bin" / "ffmpeg"

MIN_BPM = 40
MAX_BPM = 260
MAX_CSV_ROWS_PER_FILE = 50_000
MAX_API_ROWS_PER_BATCH = 5_000

PROFILE_FAST = "fast"
PROFILE_BALANCED = "balanced"
PROFILE_MAX_COVERAGE = "max_coverage"
PROFILES = (PROFILE_FAST, PROFILE_BALANCED, PROFILE_MAX_COVERAGE)

PROFILE_SETTINGS = {
    PROFILE_FAST: {
        "sample_seconds": 35,
        "acoustic_ratio": 0.25,
        "confidence_floor": 0.55,
    },
    PROFILE_BALANCED: {
        "sample_seconds": 55,
        "acoustic_ratio": 0.60,
        "confidence_floor": 0.62,
    },
    PROFILE_MAX_COVERAGE: {
        "sample_seconds": 75,
        "acoustic_ratio": 0.95,
        "confidence_floor": 0.68,
    },
}

QUALITY_THRESHOLDS = {
    "invalid_ratio_max": 0.03,
    "duplicate_ratio_max": 0.01,
    "confidence_avg_min": 0.62,
    "rows_out_of_range_max": 0,
    "delta_coverage_max": 0.80,
}

AUDIO_EXTENSIONS = {
    "mp3",
    "wav",
    "flac",
    "m4a",
    "ogg",
    "aac",
    "aif",
    "aiff",
    "alac",
    "wma",
}

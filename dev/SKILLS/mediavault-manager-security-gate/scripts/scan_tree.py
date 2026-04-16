#!/usr/bin/env python3
"""
Scan the repository working tree for high-signal security leaks.

Outputs finding locations without printing the matched values.
Exits non-zero if findings exist.
"""

from __future__ import annotations

import argparse
import os
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable

from patterns import (
    ALLOWED_EMAIL_DOMAINS,
    EMAIL_RE,
    FORBIDDEN_SUBSTRINGS,
    SENSITIVE_OPTION_DEFAULT_RE,
    SENSITIVE_OPTION_ECHO_RE,
    SENSITIVE_OPTION_LOCALIZE_RE,
    SKIP_CONTENT_PATH_FRAGMENTS,
    line_for_offset,
)


DEFAULT_EXCLUDE_DIRS = {
    ".git",
    ".github",
    ".phpunit.cache",
    "_ai_knowledge",
    "SKILLS",
    "docs",
    "tests",
    "dist",
    "vendor",
    "node_modules",
}

DEFAULT_EXCLUDE_FILES = {
    "composer.lock",
    ".DS_Store",
}

TEXT_EXTS = {
    ".php",
    ".js",
    ".css",
    ".json",
    ".yml",
    ".yaml",
    ".md",
    ".txt",
    ".sh",
    ".toml",
}


@dataclass(frozen=True)
class Finding:
    category: str
    path: str
    line: int
    detail: str


def iter_files(root: Path) -> Iterable[Path]:
    for dirpath, dirnames, filenames in os.walk(root):
        # Prune excluded directories in-place for os.walk.
        dirnames[:] = [d for d in dirnames if d not in DEFAULT_EXCLUDE_DIRS]

        for name in filenames:
            if name in DEFAULT_EXCLUDE_FILES:
                continue
            yield Path(dirpath) / name


def looks_like_text_file(path: Path) -> bool:
    if path.suffix.lower() in TEXT_EXTS:
        return True
    # Some WP plugin files have no suffix (rare). Keep this conservative.
    return False


def should_skip_content(rel_path: str) -> bool:
    rel_path = rel_path.replace("\\", "/")
    return any(fragment in rel_path for fragment in SKIP_CONTENT_PATH_FRAGMENTS)


def scan_text(text: str) -> list[tuple[str, int, str]]:
    out: list[tuple[str, int, str]] = []

    for m in SENSITIVE_OPTION_DEFAULT_RE.finditer(text):
        line = line_for_offset(text, m.start())
        option = (m.group("option") or "").strip()
        out.append(("hardcoded_option_default", line, f"option={option}"))

    for m in SENSITIVE_OPTION_ECHO_RE.finditer(text):
        line = line_for_offset(text, m.start())
        option = (m.group("option") or "").strip()
        out.append(("secret_echo_in_html", line, f"option={option}"))

    for m in SENSITIVE_OPTION_LOCALIZE_RE.finditer(text):
        line = line_for_offset(text, m.start())
        option = (m.group("option") or "").strip()
        out.append(("secret_localized_to_js", line, f"option={option}"))

    for m in EMAIL_RE.finditer(text):
        domain = (m.group("domain") or "").lower()
        if domain in ALLOWED_EMAIL_DOMAINS:
            continue
        line = line_for_offset(text, m.start())
        out.append(("hardcoded_email", line, "email_detected"))

    lower = text.lower()
    for needle in FORBIDDEN_SUBSTRINGS:
        start = 0
        needle_lower = needle.lower()
        while True:
            idx = lower.find(needle_lower, start)
            if idx == -1:
                break
            line = line_for_offset(text, idx)
            out.append(("forbidden_substring", line, f"substring={needle_lower}"))
            start = idx + len(needle_lower)

    return out


def main() -> int:
    parser = argparse.ArgumentParser(description="Scan repo tree for security leaks.")
    parser.add_argument(
        "--root",
        default=".",
        help="Repository root directory to scan (default: .)",
    )
    parser.add_argument(
        "--max-bytes",
        type=int,
        default=2_000_000,
        help="Skip files larger than this (default: 2,000,000 bytes).",
    )
    args = parser.parse_args()

    root = Path(args.root).resolve()
    if not root.exists() or not root.is_dir():
        print(f"[ERROR] root not found or not a directory: {root}", file=sys.stderr)
        return 2

    findings: set[Finding] = set()
    scanned_files = 0

    for path in iter_files(root):
        rel = str(path.relative_to(root)).replace("\\", "/")
        if should_skip_content("/" + rel + "/"):
            continue
        if not looks_like_text_file(path):
            continue

        try:
            size = path.stat().st_size
        except OSError:
            continue
        if size > args.max_bytes:
            continue

        try:
            data = path.read_bytes()
        except OSError:
            continue

        # Skip binary-like files quickly.
        if b"\x00" in data[:4096]:
            continue

        text = data.decode("utf-8", errors="ignore")
        scanned_files += 1

        for category, line, detail in scan_text(text):
            findings.add(Finding(category=category, path=rel, line=line, detail=detail))

    if not findings:
        print(f"[OK] scan_tree: {scanned_files} files scanned, 0 findings")
        return 0

    print(f"[FAIL] scan_tree: {scanned_files} files scanned, {len(findings)} findings")
    for f in sorted(findings, key=lambda x: (x.category, x.path, x.line, x.detail)):
        print(f"- {f.category}: {f.path}:{f.line} ({f.detail})")
    return 1


if __name__ == "__main__":
    raise SystemExit(main())

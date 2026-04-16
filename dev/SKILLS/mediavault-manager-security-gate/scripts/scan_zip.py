#!/usr/bin/env python3
"""
Scan a built dist ZIP for forbidden files and leak patterns.

Outputs finding locations without printing the matched values.
Exits non-zero if findings exist.
"""

from __future__ import annotations

import argparse
import sys
import zipfile
from dataclasses import dataclass
from pathlib import Path

from patterns import (
    FORBIDDEN_ZIP_PATH_FRAGMENTS,
    SKIP_CONTENT_PATH_FRAGMENTS,
    line_for_offset,
    ALLOWED_EMAIL_DOMAINS,
    EMAIL_RE,
    FORBIDDEN_SUBSTRINGS,
    SENSITIVE_OPTION_DEFAULT_RE,
    SENSITIVE_OPTION_ECHO_RE,
    SENSITIVE_OPTION_LOCALIZE_RE,
)


@dataclass(frozen=True)
class Finding:
    category: str
    path: str
    line: int
    detail: str


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


def should_skip_content(zip_path: str) -> bool:
    p = "/" + zip_path.strip("/").replace("\\", "/") + "/"
    return any(fragment in p for fragment in SKIP_CONTENT_PATH_FRAGMENTS)


def is_text_path(zip_path: str) -> bool:
    suffix = Path(zip_path).suffix.lower()
    return suffix in TEXT_EXTS


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
    parser = argparse.ArgumentParser(description="Scan a dist ZIP for security leaks.")
    parser.add_argument("--zip", required=True, help="Path to dist ZIP to scan.")
    parser.add_argument(
        "--max-bytes",
        type=int,
        default=2_000_000,
        help="Skip files larger than this (default: 2,000,000 bytes).",
    )
    args = parser.parse_args()

    zip_path = Path(args.zip).resolve()
    if not zip_path.exists() or not zip_path.is_file():
        print(f"[ERROR] zip not found: {zip_path}", file=sys.stderr)
        return 2

    findings: set[Finding] = set()
    file_count = 0

    try:
        zf = zipfile.ZipFile(zip_path, "r")
    except Exception as exc:
        print(f"[ERROR] cannot open zip: {zip_path} ({exc})", file=sys.stderr)
        return 2

    with zf:
        for zi in zf.infolist():
            name = zi.filename.replace("\\", "/")
            if not name or name.endswith("/"):
                continue
            file_count += 1

            wrapped = "/" + name.strip("/") + "/"
            for frag in FORBIDDEN_ZIP_PATH_FRAGMENTS:
                if frag in wrapped:
                    findings.add(Finding("forbidden_zip_path", name, 0, f"fragment={frag}"))

            if should_skip_content(name):
                continue
            if not is_text_path(name):
                continue
            if zi.file_size > args.max_bytes:
                continue

            try:
                data = zf.read(zi)
            except Exception:
                continue

            if b"\x00" in data[:4096]:
                continue

            text = data.decode("utf-8", errors="ignore")
            for category, line, detail in scan_text(text):
                findings.add(Finding(category, name, line, detail))

    if not findings:
        print(f"[OK] scan_zip: {file_count} files checked, 0 findings ({zip_path.name})")
        return 0

    print(f"[FAIL] scan_zip: {file_count} files checked, {len(findings)} findings ({zip_path.name})")
    for f in sorted(findings, key=lambda x: (x.category, x.path, x.line, x.detail)):
        if f.line:
            print(f"- {f.category}: {f.path}:{f.line} ({f.detail})")
        else:
            print(f"- {f.category}: {f.path} ({f.detail})")
    return 1


if __name__ == "__main__":
    raise SystemExit(main())

#!/usr/bin/env python3
"""
Shared patterns for the MediaVault Manager security gate scripts.

Design goal: detect leaks without printing secret values.
"""

from __future__ import annotations

import re

# Any real email address embedded in the distributed plugin is treated as a leak.
# We allow example domains for docs/tests only, but the scans exclude those dirs anyway.
ALLOWED_EMAIL_DOMAINS = {
    "example.com",
    "example.org",
    "example.net",
    "example.invalid",
    "example.test",
}

EMAIL_RE = re.compile(
    r"\b[A-Za-z0-9._%+-]+@(?P<domain>[A-Za-z0-9.-]+\.[A-Za-z]{2,})\b"
)

# Any non-empty string literal default for these options is considered a leak.
# Example (bad): get_option('jpsm_b2_app_key', '...')
SENSITIVE_OPTION_DEFAULT_RE = re.compile(
    r"get_option\(\s*['\"](?P<option>jpsm_(?:b2_key_id|b2_app_key|b2_bucket|b2_region|cloudflare_domain))['\"]\s*,\s*['\"](?P<default>[^'\"]+)['\"]",
    re.IGNORECASE,
)

# Rendering a secret option value into HTML (value=...) is a leak.
SENSITIVE_OPTION_ECHO_RE = re.compile(
    r"<input\b[^>]*\bname=['\"](?P<option>jpsm_(?:access_key|b2_key_id|b2_app_key))['\"][^>]*\bvalue\s*=\s*\"(?P<value>[^\"]*get_option\(\s*['\"](?P=option)['\"][^\"]*)\"",
    re.IGNORECASE,
)

# Localizing secret options into frontend JS is a leak.
SENSITIVE_OPTION_LOCALIZE_RE = re.compile(
    r"wp_localize_script\([\s\S]*?get_option\(\s*['\"](?P<option>jpsm_(?:access_key|b2_key_id|b2_app_key))['\"]",
    re.IGNORECASE,
)

# Owner-specific/branding identifiers that must not ship in a general plugin.
# Keep this list small and high-signal to avoid noise.
FORBIDDEN_SUBSTRINGS = [
    "jetpackstore.net",  # owner domain must never ship
    "antigravity.dev",  # plugin header links
]

# Files/folders that must never appear in the production ZIP.
FORBIDDEN_ZIP_PATH_FRAGMENTS = [
    "/.git/",
    "/.github/",
    "/.phpunit.cache/",
    "/_ai_knowledge/",
    "/SKILLS/",
    "/docs/",
    "/tests/",
    "/scripts/",
    "/dist/",
    "/node_modules/",
    "/debug_index.php",
    "/phpunit.xml",
    "/AGENTS.md",
    "/msg",
    "__MACOSX/",
    ".DS_Store",
]

# Reduce false positives by skipping dependency bundles in content scans.
SKIP_CONTENT_PATH_FRAGMENTS = [
    "/vendor/",
]


def line_for_offset(text: str, offset: int) -> int:
    # 1-based line number for a byte offset in a decoded string.
    return text.count("\n", 0, max(0, offset)) + 1

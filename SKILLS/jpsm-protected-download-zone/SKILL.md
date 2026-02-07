---
name: jpsm-protected-download-zone
description: Protect stable MediaVault download-engine scope; use when work touches downloader-related files, template routing, signed URLs, or module-level regressions.
---

# JPSM Protected Download Zone

Use this skill before any change that may impact MediaVault engine stability.

## Workflow
1. Load protected-scope reference:
- `docs/standards/protected-download-engine.md`

2. Apply change controls:
- Treat protected files as read-only unless explicitly authorized.
- Isolate changes and avoid mixing unrelated refactors.
- Verify routing, shortcode behavior, and signed URL generation remain stable.

3. Run focused checks:
- Full-screen template loading still works.
- Core download/play paths still return valid links.
- No unintended side effects in protected module boundaries.

## Output
- Minimal, isolated changes with clear regression evidence.
- Note any protected-scope file touched and why.

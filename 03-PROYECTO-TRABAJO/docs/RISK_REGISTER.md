# MediaVault Manager - Risk Register

Date: 2026-02-08
Purpose: enumerate "what can go wrong" during productization and the gates/mitigations that must exist before shipping.

Legend:
- Severity: P0 (critical) .. P3 (low)
- Likelihood: High/Med/Low
- Status: Open / Mitigated / Accepted

## P0/P1 Risks (Blockers Before Public Distribution)

| ID | Risk | Severity | Likelihood | What breaks | Mitigation (Gate) | Status |
|---|---|---:|---:|---|---|---|
| R-001 | Secret hardcoded in code or default fallbacks | P0 | Med | Credential compromise | Security gate: repo scan + ZIP scan (blocking) + rotate keys | Mitigated |
| R-002 | Secret rendered into HTML/JS (prefilled `value=`, `wp_localize_script`) | P0 | Med | Passive leak via browser/devtools/caches | Settings write-only + security gate detects patterns | Mitigated |
| R-003 | Presigned URLs returned to unauthorized users (demo/invitado) | P0 | Med | Direct exfiltration of content | Backend policy: sign only after entitlement check; add smoke/test assertion | Mitigated |
| R-004 | Secrets in URL querystring (`?key=...`) leak via logs/referrers | P0 | Low/Med | Secret reuse compromise | Remove/disable GET key path; if kept, make opt-in, POST/header only | Mitigated |
| R-005 | `dist/*.zip` contains dev-only folders (docs/tests/SKILLS/_ai_knowledge) | P1 | Med | Leaks internal notes + slower deploy | ZIP scan gate; build script excludes; release checklist requires gate | Mitigated |
| R-006 | Cache/CDN stores sensitive responses (HTML/AJAX/JSON) | P1 | Med | Cross-user data leakage | `Cache-Control: no-store` for sensitive endpoints; document caching exclusions | Mitigated |

## P1/P2 Risks (Operational / Support)

| ID | Risk | Severity | Likelihood | What breaks | Mitigation | Status |
|---|---|---:|---:|---|---|---|
| R-007 | Plugin update causes "missing config" downtime | P1 | Med | MediaVault not usable until config set | Fail-safe notices + migration of existing options + docs/upgrade guide | Open |
| R-008 | Slug/folder rename makes WP treat it as a new plugin | P1 | Med | Disable/lose settings temporarily | Do not rename slug in Option A; only in Option B with upgrade plan | Mitigated (by plan) |
| R-009 | XSS via file/folder names (S3 content is untrusted) | P1 | Med | Account takeover/actions | Escape/sanitize before HTML insertion; avoid `innerHTML` with untrusted strings | Open |
| R-010 | SSRF-like behavior via manipulated region/bucket/endpoint | P2 | Low | Unexpected outbound HTTP | Strict validation; keep B2 endpoint format fixed | Open |
| R-011 | Timeouts / memory spikes on index sync or huge folder listings | P2 | Med | Admin actions fail | Batch sync; paging; timeouts; clear error messages | Open |
| R-012 | Email delivery failures (SPF/DMARC) | P2 | Med | Sales flow feels broken | From/Reply-To rules + docs recommending SMTP plugin | Open |
| R-013 | PII leakage in logs (`error_log`) | P2 | Med | Privacy incident | Redact by default; allow debug logs only with opt-in and masking | Open |
| R-014 | Export/Import includes secrets by default | P1 | Low | Secret leak via backups | Export excludes secrets by default; explicit opt-in with warnings | Open |
| R-015 | Uninstall/purge not available when requested | P2 | Low | Compliance/support pain | Add uninstall/purge tooling + docs | Open |

## Acceptance Criteria (Planning Closure)
Planning is considered "closed" when:
1. `docs/PRODUCTIZATION_PLAN.md` exists with phased DoD and explicit Option B follow-up.
2. Security gate skill exists and is referenced in the release checklist.
3. This risk register exists and all P0/P1 blockers have explicit gates assigned.

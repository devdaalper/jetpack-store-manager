---
name: jpsm-release-smoke
description: Run the mandatory smoke-test checklist before and after refactors or releases; use to validate critical plugin flows and document pass/fail outcomes.
---

# JPSM Release Smoke

Use this skill before any release or after refactors.

## Smoke checklist
1. Plugin loads without PHP warnings or fatal errors.
2. Admin dashboard page renders.
3. Register sale flow works and log entry appears.
4. Resend email endpoint responds.
5. Access control page loads and returns user tier.
6. MediaVault renders and search returns results.
7. Index sync endpoint responds (admin only).

## Output
- Record results in `docs/SMOKE_TESTS.md` with date and pass/fail.
- If any step fails, stop and file a finding.

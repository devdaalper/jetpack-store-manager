---
name: jpsm-access-lock-rules
description: Preserve MediaVault access and lock behavior across tiers; use when changing permissions, folder visibility, demo limits, search filtering, or restriction-related UX.
---

# JPSM Access Lock Rules

Use this skill when touching access control behavior in MediaVault.

## Workflow
1. Load the reference rule set:
- `docs/standards/permission-lock.md`

2. Preserve non-negotiable behavior:
- Keep tier restrictions and lock logic intact unless explicitly requested.
- Ensure search, folder listing, and UI lock states remain consistent.
- Keep backend enforcement aligned with frontend visibility.

3. Regression checks:
- Demo user cannot perform restricted actions.
- Paid tiers cannot access unauthorized folders.
- Locked content remains discoverable only as teaser where required.

## Output
- Change that preserves restriction model.
- Explicit list of tested restriction scenarios.

# _ai_knowledge Note Template

Use this structure for every task closeout note and every good/bad practice capture.

```markdown
# <Short title>

- Date: YYYY-MM-DD HH:MM
- Symptom: <observable error/behavior/log>
- Root cause: <confirmed cause in one line>
- Impact: <user/data/performance/security impact>

## Context

What changed, why it mattered, and affected scope.

## What worked

List concrete approaches, commands, patterns, and decisions that succeeded.
Capture good practices that should become repeatable defaults.

## What failed

List dead ends, false assumptions, regressions, anti-patterns, or time sinks.

## Early detection

What signal, alert, or check would have revealed this earlier.

## Decision and tradeoff

What option was chosen and what was explicitly sacrificed.

## Prevention checklist

- Check 1
- Check 2
- Check 3

## Useful commands or queries

```bash
# exact command(s) that accelerated diagnosis/fix
```

## Evidence links

- /absolute/or/workspace/path/to/changed-file
- /absolute/or/workspace/path/to/related-note

## Next time

Write precise instructions for future execution: ordering, checks, and safeguards.
```

Keep each section short and technical. Treat this as cumulative engineering experience.

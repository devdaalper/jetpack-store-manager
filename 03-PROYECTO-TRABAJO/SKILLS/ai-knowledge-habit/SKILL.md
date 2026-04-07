---
name: ai-knowledge-habit
description: "Persist technical memory across repositories by enforcing a per-workspace `_ai_knowledge` journal. Use for any coding, debugging, refactor, migration, or architecture task in any project: (1) before editing code, read existing `_ai_knowledge` notes, and (2) after completing work or identifying a good or bad practice, write a concise technical note with wins, failures, and improvements."
---

# AI Knowledge Habit

## Overview

Build and reuse local memory so each new task starts from previous lessons instead of zero context.
Treat `_ai_knowledge` as mandatory working memory for implementation and debugging tasks.

## Required Workflow

1. Resolve workspace root:
   - Prefer `git rev-parse --show-toplevel`.
   - Fallback to current working directory if not inside a git repo.
2. Run the memory warm-up before writing code:
   - `python3 /Users/daalper/.codex/skills/ai-knowledge-habit/scripts/knowledge_note.py start --workspace "<workspace-root>" --limit 5`
   - Read `INDEX.md` plus the returned recent notes.
   - Extract 2-4 concrete constraints or lessons and apply them to the task plan.
3. Complete implementation/debugging work.
4. Run memory write-back when finishing a task, spotting a good practice, or spotting a bad practice:
   - `python3 /Users/daalper/.codex/skills/ai-knowledge-habit/scripts/knowledge_note.py finish --workspace "<workspace-root>" --title "<short task title>" --context "<what changed>" --worked "<what worked>" --failed "<what failed>" --improve "<how to do it better next time>"`
5. Confirm note creation path from script output and keep the note concise.

## Guardrails

- Never skip `start` before code edits.
- Never close implementation/debugging work without `finish`.
- Always write a note when you identify a strong pattern worth repeating.
- Always write a note when you detect a mistake, anti-pattern, or avoidable detour.
- Keep notes technical and future-facing; avoid narrative fluff.
- Record both successful and failed approaches every time.
- Write in the task language when possible (for example Spanish if the user works in Spanish).

## Files And Format

- Memory folder: `<workspace-root>/_ai_knowledge`
- Index: `<workspace-root>/_ai_knowledge/INDEX.md`
- Note filename: `YYYY-MM-DD-HHMM-<slug>.md`
- Sections (required): `Context`, `What worked`, `What failed`, `Next time`

See `references/note-template.md` for the exact note layout.

## Script Path

Use this skill-bundled script so behavior is consistent across repositories:

- `/Users/daalper/.codex/skills/ai-knowledge-habit/scripts/knowledge_note.py`

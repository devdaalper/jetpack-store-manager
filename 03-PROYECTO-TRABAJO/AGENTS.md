# AGENTS.md instructions for /Users/daalper/Documents/Proyectos Codex/Administrador JetPackStore

<INSTRUCTIONS>
## Skills
A skill is a set of local instructions to follow that is stored in a `SKILL.md` file. Below is the list of skills that can be used. Each entry includes a name, description, and file path so you can open the source for full instructions when using a specific skill.
### Available skills
- jpsm-architecture-audit: Audit this repo to map endpoints, data stores, sessions, and risks; update inventory docs. (file: /Users/daalper/Documents/Proyectos Codex/Administrador JetPackStore/SKILLS/jpsm-architecture-audit/SKILL.md)
- jpsm-refactor-guardrails: Refactor guardrails to prevent new monoliths and reduce fragility. (file: /Users/daalper/Documents/Proyectos Codex/Administrador JetPackStore/SKILLS/jpsm-refactor-guardrails/SKILL.md)
- jpsm-data-layer: Data layer and migration rules for JPSM tables and legacy options. (file: /Users/daalper/Documents/Proyectos Codex/Administrador JetPackStore/SKILLS/jpsm-data-layer/SKILL.md)
- jpsm-auth-permissions: Authentication and permissions rules for endpoints and sessions. (file: /Users/daalper/Documents/Proyectos Codex/Administrador JetPackStore/SKILLS/jpsm-auth-permissions/SKILL.md)
- jpsm-release-smoke: Minimal smoke tests before and after changes. (file: /Users/daalper/Documents/Proyectos Codex/Administrador JetPackStore/SKILLS/jpsm-release-smoke/SKILL.md)
- jpsm-bugfix-protocol: Protocolo para corregir bugs (delimitar UI vs backend, contrato JSON, manejo de errores, validación y notas). (file: /Users/daalper/Documents/Proyectos Codex/Administrador JetPackStore/SKILLS/jpsm-bugfix-protocol/SKILL.md)
- jpsm-domain-model: Single source of truth for packages, tiers, prices, and templates. (file: /Users/daalper/Documents/Proyectos Codex/Administrador JetPackStore/SKILLS/jpsm-domain-model/SKILL.md)
- jpsm-ui-design-standards: Apply JetPack UI design standards and component patterns; use when creating or modifying frontend screens, CSS tokens, layout behavior, and MediaVault-aligned UX. (file: /Users/daalper/Documents/Proyectos Codex/Administrador JetPackStore/SKILLS/jpsm-ui-design-standards/SKILL.md)
- jpsm-access-lock-rules: Preserve MediaVault access and lock behavior across tiers; use when changing permissions, folder visibility, demo limits, search filtering, or restriction-related UX. (file: /Users/daalper/Documents/Proyectos Codex/Administrador JetPackStore/SKILLS/jpsm-access-lock-rules/SKILL.md)
- jpsm-protected-download-zone: Protect stable MediaVault download-engine scope; use when work touches downloader-related files, template routing, signed URLs, or module-level regressions. (file: /Users/daalper/Documents/Proyectos Codex/Administrador JetPackStore/SKILLS/jpsm-protected-download-zone/SKILL.md)
- mediavault-manager-security-gate: Compuertas y checklist de seguridad para no filtrar secretos/PII y para validar el ZIP `dist/` antes de releases o cambios en settings/auth/endpoints/MediaVault. (file: /Users/daalper/Documents/Proyectos Codex/Administrador JetPackStore/SKILLS/mediavault-manager-security-gate/SKILL.md)
- ai-knowledge-habit: Persist per-workspace `_ai_knowledge` memory; read notes before coding and write a brief technical note when finishing tasks or hard bugs. (file: /Users/daalper/Documents/Proyectos Codex/Administrador JetPackStore/SKILLS/ai-knowledge-habit/SKILL.md)
### How to use skills
- Discovery: The list above is the skills available in this session (name + description + file path). Skill bodies live on disk at the listed paths.
- Trigger rules: If the user names a skill (with `$SkillName` or plain text) OR the task clearly matches a skill's description shown above, you must use that skill for that turn. Multiple mentions mean use them all. Do not carry skills across turns unless re-mentioned.
- Missing/blocked: If a named skill isn't in the list or the path can't be read, say so briefly and continue with the best fallback.
- How to use a skill (progressive disclosure):
  1) After deciding to use a skill, open its `SKILL.md`. Read only enough to follow the workflow.
  2) When `SKILL.md` references relative paths (e.g., `scripts/foo.py`), resolve them relative to the skill directory listed above first, and only consider other paths if needed.
  3) If `SKILL.md` points to extra folders such as `references/`, load only the specific files needed for the request; don't bulk-load everything.
  4) If `scripts/` exist, prefer running or patching them instead of retyping large code blocks.
  5) If `assets/` or templates exist, reuse them instead of recreating from scratch.
- Coordination and sequencing:
  - If multiple skills apply, choose the minimal set that covers the request and state the order you'll use them.
  - Announce which skill(s) you're using and why (one short line). If you skip an obvious skill, say why.
- Context hygiene:
  - Keep context small: summarize long sections instead of pasting them; only load extra files when needed.
  - Avoid deep reference-chasing: prefer opening only files directly linked from `SKILL.md` unless you're blocked.
  - When variants exist (frameworks, providers, domains), pick only the relevant reference file(s) and note that choice.
- Safety and fallback: If a skill can't be applied cleanly (missing files, unclear instructions), state the issue, pick the next-best approach, and continue.
</INSTRUCTIONS>

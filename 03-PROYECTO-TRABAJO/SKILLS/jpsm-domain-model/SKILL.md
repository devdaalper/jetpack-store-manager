---
name: jpsm-domain-model
description: Maintain a single source of truth for packages, tiers, prices, and templates; use when changing catalog rules, pricing behavior, or package-related UI mappings.
---

# JPSM Domain Model

Use this skill when editing packages, tiers, pricing, or email templates.

## Rules
- Do not use string matching for package logic in new code.
- All package rules must live in one registry or config file.
- UI labels should be derived from the registry.

## Required structure for registry
Each package entry must include:
- id (stable key)
- label (display name)
- tier (numeric)
- template option name
- price option name(s)

## Required updates
- Update the registry when adding or renaming packages.
- Update any UI that lists packages to read from the registry.
- Record decisions in `docs/REFRACTORING_PLAN.md` Technical Decisions Log.

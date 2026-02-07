---
name: jpsm-data-layer
description: Define and apply JPSM data-layer and migration rules for custom tables and legacy options; use when changing persistence, schema versions, performance-sensitive queries, or migration workflows.
---

# JPSM Data Layer

Use this skill when touching persistence, migrations, or performance of data storage.

## Principles
- New data must live in custom tables, not `wp_options`.
- All table access goes through a single data access class.
- Migrations are reversible and safe by default.

## Required steps for new tables
1. Create table with `dbDelta` and store schema version in an option.
2. Add a DAO/repository class with CRUD methods.
3. Add migration from legacy `wp_options` if applicable.
4. Keep a fallback read path until migration is verified.

## Required metadata
- Update `docs/DATA_STORES.md` with table name, schema, and usage.
- Record migration steps in `docs/REFRACTORING_PLAN.md` under Technical Decisions Log.

## Safety checks
- Use prepared SQL for all queries.
- Avoid full table scans in hot paths.
- Log migration progress and errors.

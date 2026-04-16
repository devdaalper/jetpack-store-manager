---
name: jpsm-auth-permissions
description: Apply authentication and authorization rules for sessions and endpoints; use when implementing or reviewing AJAX/REST handlers, nonce checks, cookies, and access-control logic.
---

# JPSM Auth and Permissions

Use this skill for any endpoint, session, or access control work.

## Rules
- No secret keys in URLs or frontend JS.
- All AJAX endpoints must validate nonce and auth.
- Auth must be centralized in a single helper or service.
- Sessions must be signed and verified server side.

## Required checks for endpoints
1. `current_user_can('manage_options')` for admin endpoints.
2. Nonce validation for all requests.
3. Signed session token for non-admin access.

## Session rules
- Cookies must be httpOnly and secure when possible.
- Never trust raw email in a cookie.
- Session validation must happen server side.

## Required documentation
- Update `docs/ENDPOINTS.md` with auth rules.
- Update `docs/SESSIONS.md` when session logic changes.

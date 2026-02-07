# JPSM Session and Cookie Map

Date: 2026-02-06
Scope: Current session model after Phase 1 hardening.

## Session Architecture (Phase 1)
- Central auth/session helper: `/includes/class-jpsm-auth.php` (`JPSM_Auth`).
- Cookies are now signed tokens validated server-side.
- Legacy raw email cookie values are migrated in place on first valid read.

## Cookie Inventory

| Cookie | Purpose | Value Type | TTL | Set By | Validation | Clear Path |
|---|---|---|---|---|---|---|
| `jpsm_auth_session` | Admin session for dashboard/admin-level actions. | Signed token (`type=admin`, `exp`, `key_hash`, HMAC signature). | 14 days | `JPSM_Auth::set_admin_session_from_key()` via `jpsm_login`. | `JPSM_Auth::verify_admin_session()` (signature, expiry, key hash). | `JPSM_Auth::clear_admin_session_cookie()` via `jpsm_logout`. |
| `jdd_access_token` | End-user session for MediaVault and related signed-session checks. | Signed token (`type=user`, `email`, `exp`, HMAC signature). Legacy plain email is auto-migrated. | 30 days | `JPSM_Auth::set_user_session_cookie()` via `JPSM_Access_Manager::set_access_cookie()`. | `JPSM_Auth::get_current_user_email()` (signature, expiry, email). | `JPSM_Auth::clear_user_session_cookie()` / `mv_logout`. |

## Cookie Attributes

| Cookie | Secure | HttpOnly | SameSite |
|---|---|---|---|
| `jpsm_auth_session` | `is_ssl()` | `true` | `Lax` (PHP >= 7.3 path) |
| `jdd_access_token` | `is_ssl()` | `true` | `Lax` (PHP >= 7.3 path) |

Implementation reference: `/includes/class-jpsm-auth.php` (`set_cookie()`).

## Session Path Map

### 1) Admin Login Path
1. Client submits `jpsm_login` with `key` + nonce (`jpsm_login_nonce` or `jpsm_nonce`).
2. `JPSM_Auth::set_admin_session_from_key()` validates key server-side.
3. Signed `jpsm_auth_session` cookie is set.
4. Privileged handlers validate via `JPSM_Auth::verify_admin_session()` or `JPSM_Auth::is_admin_authenticated()`.

## 2) MediaVault/User Login Path
1. User submits email (or guest flow creates synthetic email).
2. `JPSM_Access_Manager::set_access_cookie()` processes tier/lead logic.
3. `JPSM_Auth::set_user_session_cookie()` stores signed `jdd_access_token`.
4. Session identity is resolved with `JPSM_Auth::get_current_user_email()`.

## 3) Legacy Cookie Migration Path
1. If `jdd_access_token` holds raw email (legacy format), helper detects valid email.
2. Helper re-issues signed token cookie immediately.
3. Subsequent requests use signed format only.

## 4) Secret-Key Path (Narrowed)
- Secret key remains server-side and is only accepted in endpoints explicitly configured with `allow_secret_key=true`.
- No automatic secret-key exposure in frontend-localized JS or URL-based access links.

## Endpoint-Level Session Rules
- Admin-level endpoints: nonce required + privileged auth (`WP admin` / signed admin session; optional secret-key only where enabled).
- User-level playback endpoint (`jpsm_log_play`): nonce required + signed user session (or admin auth).
- MediaVault query actions: nonce required + signed session checks, with admin checks on management actions.

## References
- `/includes/class-jpsm-auth.php`
- `/includes/class-access-manager.php`
- `/includes/class-jpsm-sales.php`
- `/includes/modules/mediavault/template-vault.php`

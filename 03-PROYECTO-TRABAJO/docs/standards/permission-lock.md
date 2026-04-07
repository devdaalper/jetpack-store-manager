---
description: Definitive guide to MediaVault's access control, demo limitations, navigation rules, and restriction logic.
---

# MediaVault Core Restrictions & Logic

This skill documents the **immutable restriction logic** of the MediaVault system. These rules determine how content is secured, monetized, and delivered.

**⚠️ WARNING**: Do not modify any logic described below unless explicitly requested. Any change to these rules requires full regression testing.

---

## 1. Demo User Restrictions (Tier 0)
**Goal**: "Try before you buy" (Prueba limitada).

### 1.1 Playback Limits (GLOBAL)
-   **Goal**: Ensure MediaVault is used for downloads, not streaming.
-   **Rule**: ALL users (including Tier 5) have a **60-second preview limit** per session.
-   **Demo Specific**: Demo users trigger a "Limit Reached" modal at 15 plays. Paid users have unlimited *starts*, but each play is capped at 60s.
    -   **Logic**: `mediavault-client.js` enforces `checkLimit` regardless of user tier.
    -   **UX**: Displays overlay "Límite de Vista Previa Alcanzado".
    -   **Backend hardening (Option A / A3)**: demo play limits are also enforced server-side via `mv_get_preview_url`, and locked/demo previews use a byte-capped proxy (`mv_stream_preview`) so previews do not leak full downloadable URLs.

### 1.2 Download Blocking
-   **Status**: Strictly Forbidden.
-   **Logic**: 
    -   **Frontend**: Download buttons are hidden (`display: none`) or not rendered for Tier 0.
    -   **Sticky Bar**: A persistent footer bar invites users to upgrade.

---

## 2. Tiered Access Restrictions (Tiers 1-5)
**Goal**: Monetization ladder (Escalera de valor).

### 2.0 Funnel Visibility (Conversion Strategy)
-   **Rule**: ALL tiers (0-5) can **browse and preview all content** (folders + files).
-   **Reason**: Discovery drives upgrades. Restrictions apply only to **downloads**, not to browsing.
-   **Implementation**:
    - Sidebar and global search MUST NOT hide folders/files based on download tier.
    - `applyFolderLocks()` marks content as locked/unlocked and swaps "Descargar" into an upgrade CTA when needed.

### 2.1 Access Hierarchy
Users can only access permissions matching their tier.
-   **Tier 1**: Basic
-   **Tier 2**: VIP Basic
-   **Tier 3**: VIP Videos
-   **Tier 4**: VIP Movies
-   **Tier 5**: Full (God Mode)

### 2.2 Partial Visibility (The "Teaser")
-   **Concept**: Users see higher-tier folders/files but downloads are **locked**.
-   **Logic**: `applyFolderLocks()` in `mediavault-client.js` checks `allowedTiers.includes(userTier)`.
-   **UX**:
    -   **Icon**: Displays a Lock 🔒.
    -   **Action**: Button changes from "Descargar" to **"🚀 Mejorar Plan"** (or "Desbloquear").
    -   **Redirect**: Opens WhatsApp with a pre-filled message requesting that specific Tier upgrade.

### 2.3 Backend Enforcement
-   **Scope**: Enforce permissions on **downloads**, not on browsing.
-   **Download Endpoints**:
    - `mv_list_folder` in `template-vault.php` (folder downloads).
    - `mv_get_presigned_url` in `template-vault.php` (single-file download URL issuance).
    - Any other endpoint that returns signed URLs for downloads MUST also enforce.
-   **Check**:
    - Tier 0: always blocked for downloads.
    - Tier 1-5: `JPSM_Access_Manager::user_can_access($path, $tier)` must return `true` (Tier 5 bypass).
-   **Result**: Returns JSON Error "⛔ Acceso Denegado" if unauthorized to download.

### 2.4 Preview Hardening (Locked/Demo)
- Preview can remain visible as funnel, but locked/demo users must not receive direct presigned download URLs.
- Enforced by:
  - `mv_get_preview_url` (nonce + signed session) -> returns either direct URL (entitled) or proxy URL (locked/demo).
  - `mv_stream_preview` (token + signed session) -> streams only a capped byte-range.

---

## 3. Navigation & Structure Integrity
**Goal**: Prevent browsing the raw bucket structure.

### 3.1 Root Bypass
-   **Rule**: Users typically cannot see the bucket root.
-   **Logic**:
    - Backend (`template-vault.php`) detects a **junction folder** (drilling wrapper folders where needed).
    - If **no `folder` is specified**, MediaVault renders a **"Pantalla de inicio"** (junction view) showing the same top-level folders as the sidebar.
    - The order of those folders is **admin-defined** (drag & drop) and must be consistent between sidebar and home screen.

### 3.2 Drilled Views (Wrapper Folders)
-   **Rule**: If a folder solely contains another folder (1 child, 0 files), the system automatically "drills down" to the content level.

### 3.3 Sanitized Breadcrumbs
-   **Rule**: Use "Junction-relative" paths.
-   **Logic**: Removes the path prefix of the junction folder.
    -   *Raw*: `CONTENIDO / Musica / Reggaeton`
    -   *Display*: `Musica / Reggaeton`
-   **Visuals**: "Inicio" (Home) links are removed to prevent navigating up to the forbidden root.

---

## 4. Operational Security
**Goal**: Prevent data loss and unauthorized entry.

### 4.1 Download Protection
-   **Rule**: Prevent accidental page closes during downloads.
-   **Logic**: `window.onbeforeunload` checks `DownloadManager.hasActiveDownloads()`.
-   **UX**: Browser native confirm dialog "Se cancelarán las descargas".

### 4.2 Search Scoping
-   **Rule**: Global search MUST NOT hide locked content (visibility funnel).
-   **Logic**: `mv_search_global` returns results regardless of tier (no URL fields); UI applies lock state and disables download actions.

### 4.3 Session Validation
-   **Rule**: No PHP execution without session.
-   **Logic**: `JPSM_Access_Manager::check_current_session()` is the first gatekeeper in `render()` and all AJAX handlers.

---

## Modification Protocol
If you need to edit files affecting these rules:
1.  **Reference this Skill**.
2.  **Preserve the Behavior**: Ensure your change keeps "see/preview all" while gating downloads correctly.
3.  **Test**:
    - Tier 0 can browse and preview but cannot download.
    - Tier 1-4 can browse and preview everything; locked items show upgrade CTA and downloads are blocked.
    - Tier 5 can download everything.

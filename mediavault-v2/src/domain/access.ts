/**
 * MediaVault v2 — Access Control (Pure Domain Logic)
 *
 * All authorization decisions live here. These are pure functions
 * with zero external dependencies — they receive data, return booleans.
 *
 * The authorization model is hybrid RBAC + ABAC:
 * - Each folder has an explicit list of allowed tier integers
 * - Tier 5 (FULL) always has access regardless of folder permissions
 * - Demo (tier 0) can browse and preview, but cannot download
 * - Play limits are per-email, per-month (15 plays for demo tier)
 */

import { DEMO_PLAY_LIMIT } from "./types";
import type { TierValue } from "./schemas";

// ─── Folder Access ──────────────────────────────────────────────────

/**
 * Check if a user with the given tier can access a folder.
 *
 * Rules:
 * - Tier 5 (FULL) always has access
 * - If allowedTiers is empty, the folder is open to all tiers
 * - Otherwise, the user's tier must be in the allowed list
 */
export function canAccessFolder(
  userTier: TierValue,
  allowedTiers: TierValue[],
): boolean {
  // Tier 5 (FULL) bypasses all restrictions
  if (userTier === 5) return true;

  // Empty permissions = open to all
  if (allowedTiers.length === 0) return true;

  return allowedTiers.includes(userTier);
}

/**
 * Resolve the allowed tiers for a specific folder path,
 * walking up the folder tree to find the most specific permission.
 *
 * Example: if permissions exist for "Music/" but not "Music/Rock/",
 * "Music/Rock/" inherits from "Music/".
 */
export function resolveAllowedTiers(
  folderPath: string,
  permissionsMap: Record<string, TierValue[]>,
): TierValue[] {
  // Normalize: ensure trailing slash
  const normalized = folderPath.endsWith("/") ? folderPath : `${folderPath}/`;

  // Check exact match first
  const exact = permissionsMap[normalized];
  if (exact !== undefined) return exact;

  // Walk up the folder tree
  const segments = normalized.split("/").filter(Boolean);
  for (let i = segments.length - 1; i >= 0; i--) {
    const parentPath = segments.slice(0, i).join("/") + "/";
    const parentPerms = permissionsMap[parentPath];
    if (parentPerms !== undefined) return parentPerms;
  }

  // Root-level check
  const rootPerms = permissionsMap[""];
  if (rootPerms !== undefined) return rootPerms;

  // No permissions defined = open to all
  return [];
}

// ─── Download Access ────────────────────────────────────────────────

/**
 * Check if a user can download files from a folder.
 *
 * Rules:
 * - Demo (tier 0) can NEVER download
 * - Paid users (tier >= 1) can download if they have folder access
 */
export function canDownload(
  userTier: TierValue,
  allowedTiers: TierValue[],
): boolean {
  // Demo users cannot download anything
  if (userTier === 0) return false;

  return canAccessFolder(userTier, allowedTiers);
}

// ─── Preview/Play Access ────────────────────────────────────────────

/**
 * Check if a user can play a preview.
 *
 * Rules:
 * - Demo (tier 0): can play if they have remaining plays (< DEMO_PLAY_LIMIT)
 * - Paid users (tier >= 1): unlimited previews
 */
export function canPlay(
  userTier: TierValue,
  monthlyPlayCount: number,
): boolean {
  // Paid users have unlimited previews
  if (userTier >= 1) return true;

  // Demo: check against monthly limit
  return monthlyPlayCount < DEMO_PLAY_LIMIT;
}

/**
 * Calculate remaining plays for a user.
 *
 * Returns:
 * - -1 for paid users (unlimited)
 * - 0..DEMO_PLAY_LIMIT for demo users
 */
export function getRemainingPlays(
  userTier: TierValue,
  monthlyPlayCount: number,
): number {
  if (userTier >= 1) return -1; // unlimited
  return Math.max(0, DEMO_PLAY_LIMIT - monthlyPlayCount);
}

// ─── Admin Access ───────────────────────────────────────────────────

/**
 * Check if a user has admin privileges.
 * Simple boolean check — admin status is stored in the profile.
 */
export function isAdmin(userIsAdmin: boolean): boolean {
  return userIsAdmin;
}

// ─── Tier Resolution ────────────────────────────────────────────────

/**
 * Determine the tier a user should have based on their sales history.
 *
 * Rules:
 * - If user has any completed sale, use the highest tier from their packages
 * - If no sales, user is demo (tier 0)
 * - "full" package → tier 5, "vip_pelis" → 4, "vip_videos" → 3,
 *   "vip_basic" → 2, "basic" → 1
 */
export function resolveTierFromSales(
  sales: Array<{ package: string; status: string }>,
): TierValue {
  const PACKAGE_TIER_MAP: Record<string, TierValue> = {
    full: 5,
    vip_pelis: 4,
    vip_videos: 3,
    vip_basic: 2,
    basic: 1,
  };

  let maxTier: TierValue = 0;

  for (const sale of sales) {
    if (sale.status !== "Completado") continue;

    const pkg = sale.package.toLowerCase();

    // Exact match
    const exactTier = PACKAGE_TIER_MAP[pkg];
    if (exactTier !== undefined) {
      maxTier = Math.max(maxTier, exactTier) as TierValue;
      continue;
    }

    // Fuzzy match (legacy package names from prototype)
    if (pkg.includes("full") || pkg.includes("active")) {
      maxTier = Math.max(maxTier, 5) as TierValue;
    } else if (pkg.includes("pelis") || pkg.includes("películas")) {
      maxTier = Math.max(maxTier, 4) as TierValue;
    } else if (pkg.includes("videos")) {
      maxTier = Math.max(maxTier, 3) as TierValue;
    } else if (pkg.includes("vip")) {
      maxTier = Math.max(maxTier, 2) as TierValue;
    } else if (pkg.includes("basic") || pkg.includes("básico")) {
      maxTier = Math.max(maxTier, 1) as TierValue;
    }
  }

  return maxTier;
}

// ─── Media Type Detection ───────────────────────────────────────────

import { AUDIO_EXTENSIONS, VIDEO_EXTENSIONS } from "./types";

/**
 * Determine the media kind from a file extension.
 */
export function getMediaKind(extension: string): "audio" | "video" | "other" {
  const ext = extension.toLowerCase().replace(".", "");
  if ((AUDIO_EXTENSIONS as readonly string[]).includes(ext)) return "audio";
  if ((VIDEO_EXTENSIONS as readonly string[]).includes(ext)) return "video";
  return "other";
}

/**
 * Extract file extension from a filename.
 */
export function getFileExtension(filename: string): string {
  const lastDot = filename.lastIndexOf(".");
  if (lastDot === -1 || lastDot === filename.length - 1) return "";
  return filename.slice(lastDot + 1).toLowerCase();
}

/**
 * MediaVault v2 — Domain Types
 *
 * Single source of truth for all domain entities.
 * These types are DERIVED from Zod schemas in schemas.ts.
 * Never define types manually — always infer from Zod.
 */

export { type Tier, type TierValue, type PackageId, type Region, type Currency } from "./schemas";
export { type UserProfile } from "./schemas";
export { type FolderPermission } from "./schemas";
export { type MediaFile, type MediaKind } from "./schemas";
export { type Sale, type SaleStatus } from "./schemas";
export { type Lead } from "./schemas";
export { type PlayCount } from "./schemas";
export { type BehaviorEvent, type DeviceClass } from "./schemas";
export { type AppConfig } from "./schemas";
export { type ApiResponse, type ApiError, type PaginatedResponse } from "./schemas";

// ─── Constants ───────────────────────────────────────────────────────

/** Tier numeric values mapped to human-readable names */
export const TIER_NAMES: Record<number, string> = {
  0: "Demo",
  1: "Básico",
  2: "VIP + Básico",
  3: "VIP + Videos",
  4: "VIP + Películas",
  5: "Full",
} as const;

/** Demo tier play limit per month */
export const DEMO_PLAY_LIMIT = 15;

/** Preview duration limit in seconds (all tiers) */
export const PREVIEW_DURATION_LIMIT_SECONDS = 60;

/** Presigned URL TTL in seconds */
export const PRESIGNED_URL_TTL = {
  preview: 900, // 15 minutes
  download: 3600, // 60 minutes
} as const;

/** Daily bandwidth limit in bytes (50 GB) */
export const DAILY_BANDWIDTH_LIMIT = 53_687_091_200;

/** Audio file extensions */
export const AUDIO_EXTENSIONS = [
  "mp3",
  "wav",
  "flac",
  "m4a",
  "ogg",
  "aac",
] as const;

/** Video file extensions */
export const VIDEO_EXTENSIONS = [
  "mp4",
  "mov",
  "mkv",
  "avi",
  "webm",
  "wmv",
] as const;

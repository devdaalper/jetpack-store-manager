/**
 * MediaVault v2 — Zod Schemas
 *
 * THE canonical source of truth for all domain types.
 * TypeScript types are DERIVED from these schemas using z.infer<>.
 * Runtime validation happens at API boundaries using these schemas.
 */

import { z } from "zod/v4";

// ─── Enums & Value Objects ──────────────────────────────────────────

export const TierSchema = z.enum(["DEMO", "BASIC", "VIP_BASIC", "VIP_VIDEOS", "VIP_PELIS", "FULL"]);
export type Tier = z.infer<typeof TierSchema>;

/** Numeric tier value (0-5) used in DB and access checks */
export const TierValueSchema = z.number().int().min(0).max(5);
export type TierValue = z.infer<typeof TierValueSchema>;

export const PackageIdSchema = z.enum(["basic", "vip_basic", "vip_videos", "vip_pelis", "full"]);
export type PackageId = z.infer<typeof PackageIdSchema>;

export const RegionSchema = z.enum(["national", "international"]);
export type Region = z.infer<typeof RegionSchema>;

export const CurrencySchema = z.enum(["MXN", "USD"]);
export type Currency = z.infer<typeof CurrencySchema>;

export const MediaKindSchema = z.enum(["audio", "video", "other"]);
export type MediaKind = z.infer<typeof MediaKindSchema>;

export const DeviceClassSchema = z.enum(["mobile", "tablet", "desktop", "unknown"]);
export type DeviceClass = z.infer<typeof DeviceClassSchema>;

export const SaleStatusSchema = z.enum(["Completado", "Falló"]);
export type SaleStatus = z.infer<typeof SaleStatusSchema>;

// ─── Value Objects (validated strings) ──────────────────────────────

/** Email: lowercase, trimmed */
export const EmailSchema = z.string().email().transform((e) => e.toLowerCase().trim());

/**
 * Folder path: normalized with trailing slash, no dangerous characters.
 * Prevents path traversal (../) and injection.
 */
export const FolderPathSchema = z
  .string()
  .max(500)
  .regex(/^[^<>&"']*$/, "Path contains invalid characters")
  .refine((p) => !p.includes("../"), "Path traversal not allowed")
  .transform((p) => (p.endsWith("/") || p === "" ? p : `${p}/`));

/** File path: no trailing slash, same security constraints */
export const FilePathSchema = z
  .string()
  .max(500)
  .regex(/^[^<>&"']*$/, "Path contains invalid characters")
  .refine((p) => !p.includes("../"), "Path traversal not allowed");

// ─── Core Entities ──────────────────────────────────────────────────

export const UserProfileSchema = z.object({
  id: z.string().uuid(),
  email: EmailSchema,
  tier: TierValueSchema,
  isAdmin: z.boolean(),
  createdAt: z.string().datetime(),
});
export type UserProfile = z.infer<typeof UserProfileSchema>;

export const FolderPermissionSchema = z.object({
  folderPath: FolderPathSchema,
  allowedTiers: z.array(TierValueSchema),
});
export type FolderPermission = z.infer<typeof FolderPermissionSchema>;

export const MediaFileSchema = z.object({
  id: z.number().int().positive(),
  path: z.string(),
  name: z.string(),
  folder: z.string(),
  size: z.number().int().nonnegative(),
  extension: z.string(),
  mediaKind: MediaKindSchema,
  lastModified: z.string().datetime().nullable(),
  depth: z.number().int().nonnegative(),
});
export type MediaFile = z.infer<typeof MediaFileSchema>;

export const LeadSchema = z.object({
  email: EmailSchema,
  registeredAt: z.string().datetime(),
  source: z.string().default(""),
});
export type Lead = z.infer<typeof LeadSchema>;

export const PlayCountSchema = z.object({
  email: EmailSchema,
  playCount: z.number().int().nonnegative(),
  updatedAt: z.string().datetime(),
});
export type PlayCount = z.infer<typeof PlayCountSchema>;

export const SaleSchema = z.object({
  id: z.number().int().positive(),
  saleUid: z.string().min(1),
  saleTime: z.string().datetime(),
  email: EmailSchema,
  package: PackageIdSchema,
  region: RegionSchema,
  amount: z.number().nonnegative(),
  currency: CurrencySchema,
  status: SaleStatusSchema,
});
export type Sale = z.infer<typeof SaleSchema>;

export const BehaviorEventSchema = z.object({
  eventUuid: z.string().uuid(),
  eventTime: z.string().datetime(),
  eventName: z.string().min(1).max(64),
  sessionIdHash: z.string().optional(),
  userIdHash: z.string().optional(),
  tier: TierValueSchema.optional(),
  region: z.string().optional(),
  deviceClass: DeviceClassSchema.optional(),
  queryNorm: z.string().optional(),
  objectPathNorm: z.string().optional(),
  status: z.string().optional(),
  filesCount: z.number().int().optional(),
  bytesAuthorized: z.number().int().optional(),
  bytesObserved: z.number().int().optional(),
  resultCount: z.number().int().optional(),
  objectType: z.string().optional(),
  metaJson: z.record(z.string(), z.unknown()).optional(),
});
export type BehaviorEvent = z.infer<typeof BehaviorEventSchema>;

export const AppConfigSchema = z.object({
  key: z.string().min(1),
  value: z.unknown(),
});
export type AppConfig = z.infer<typeof AppConfigSchema>;

// ─── API Request Schemas ────────────────────────────────────────────

export const BrowseFolderRequestSchema = z.object({
  path: FolderPathSchema.default(""),
});

export const SearchRequestSchema = z.object({
  q: z.string().min(1).max(200),
  type: MediaKindSchema.optional(),
  limit: z.number().int().min(1).max(100).default(50),
  offset: z.number().int().nonnegative().default(0),
});

export const PresignedUrlRequestSchema = z.object({
  path: FilePathSchema,
  intent: z.enum(["download", "preview"]),
});

export const PlayRequestSchema = z.object({
  path: FilePathSchema,
});

export const UpdateTierRequestSchema = z.object({
  email: EmailSchema,
  tier: TierValueSchema,
});

export const UpdateFolderPermissionRequestSchema = z.object({
  folderPath: FolderPathSchema,
  allowedTiers: z.array(TierValueSchema),
});

// ─── API Response Envelope ──────────────────────────────────────────

export const ApiErrorSchema = z.object({
  code: z.string(),
  message: z.string(),
});
export type ApiError = z.infer<typeof ApiErrorSchema>;

export type ApiResponse<T> =
  | { ok: true; data: T }
  | { ok: false; error: ApiError };

export type PaginatedResponse<T> = {
  ok: true;
  data: T[];
  pagination: {
    total: number;
    hasMore: boolean;
    nextCursor?: string | undefined;
  };
};

// ─── Tier ↔ Package Mapping ─────────────────────────────────────────

/** Map package ID to tier value */
export const PACKAGE_TO_TIER: Record<PackageId, TierValue> = {
  basic: 1,
  vip_basic: 2,
  vip_videos: 3,
  vip_pelis: 4,
  full: 5,
};

/** Map tier value to tier name */
export const TIER_TO_NAME: Record<number, Tier> = {
  0: "DEMO",
  1: "BASIC",
  2: "VIP_BASIC",
  3: "VIP_VIDEOS",
  4: "VIP_PELIS",
  5: "FULL",
};

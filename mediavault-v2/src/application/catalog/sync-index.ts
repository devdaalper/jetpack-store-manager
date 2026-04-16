/**
 * Sync Index — Use Case
 *
 * Scans the B2 bucket and builds/updates the file index in Supabase.
 * Uses atomic version swap: writes to inactive version, then flips.
 *
 * Designed to run in batches (Vercel 10s timeout per request).
 * The admin UI calls this in a loop until completion.
 */

import { createServiceClient } from "@/infrastructure/supabase/server";
import { listObjects } from "@/infrastructure/backblaze/s3-client";
import { getFileExtension, getMediaKind } from "@/domain/access";
import { createHash } from "crypto";

// ─── Types ──────────────────────────────────────────────────────────

export interface SyncBatchResult {
  scanned: number;
  inserted: number;
  status: "in_progress" | "completed";
  nextToken: string | undefined;
}

export interface SyncState {
  syncId: string;
  targetVersion: number;
  status: "idle" | "in_progress" | "completed";
  scanned: number;
  inserted: number;
  startedAt: string;
  nextToken: string | undefined;
}

// ─── Use Case ───────────────────────────────────────────────────────

/**
 * Start a new sync or continue an existing one.
 *
 * Each call processes one page of S3 objects (~1000 files).
 * Returns the batch result so the caller can continue if needed.
 */
export async function syncIndexBatch(
  prefix = "",
  continuationToken?: string,
): Promise<SyncBatchResult> {
  const supabase = createServiceClient();

  // Determine target version (inactive)
  const { data: configRow } = await supabase
    .from("app_config")
    .select("value")
    .eq("key", "active_index_version")
    .single();

  const activeVersion = Number(configRow?.value ?? 1);
  const targetVersion = activeVersion === 1 ? 2 : 1;

  // If starting fresh (no continuation token), clear target version
  if (!continuationToken) {
    await supabase.from("file_index").delete().eq("version", targetVersion);
  }

  // Fetch one page from B2
  const result = await listObjects(prefix, continuationToken, 1000);

  // Prepare rows for insertion
  const rows = result.objects
    .filter((obj) => !obj.key.endsWith("/")) // Skip folder markers
    .map((obj) => {
      const name = obj.key.split("/").pop() ?? obj.key;
      const folder = obj.key.slice(0, obj.key.lastIndexOf("/") + 1);
      const ext = getFileExtension(name);
      const pathHash = createHash("md5").update(obj.key).digest("hex");

      return {
        version: targetVersion,
        path: obj.key,
        path_hash: pathHash,
        path_norm: normalizeSearchText(obj.key),
        name,
        name_norm: normalizeSearchText(name),
        folder,
        folder_norm: normalizeSearchText(folder),
        size: obj.size,
        extension: ext,
        media_kind: getMediaKind(ext),
        last_modified: obj.lastModified?.toISOString() ?? null,
        etag: obj.etag,
        depth: folder.split("/").filter(Boolean).length,
        synced_at: new Date().toISOString(),
      };
    });

  // Batch insert (upsert to handle duplicates within same sync)
  let inserted = 0;
  if (rows.length > 0) {
    const { error } = await supabase.from("file_index").upsert(rows, {
      onConflict: "version,path_hash",
      ignoreDuplicates: false,
    });

    if (error) {
      console.error("[sync] Insert error:", error.message);
    } else {
      inserted = rows.length;
    }
  }

  // If no more pages, flip the active version
  if (!result.isTruncated) {
    await supabase
      .from("app_config")
      .update({
        value: targetVersion,
        updated_at: new Date().toISOString(),
      })
      .eq("key", "active_index_version");

    // Clean up old version
    await supabase.from("file_index").delete().eq("version", activeVersion);

    return {
      scanned: result.objects.length,
      inserted,
      status: "completed",
      nextToken: undefined,
    };
  }

  return {
    scanned: result.objects.length,
    inserted,
    status: "in_progress",
    nextToken: result.nextContinuationToken,
  };
}

// ─── Helpers ────────────────────────────────────────────────────────

/**
 * Normalize text for search: lowercase, remove accents, strip special chars.
 */
function normalizeSearchText(text: string): string {
  return text
    .toLowerCase()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "") // Remove accents
    .replace(/[^a-z0-9\s/._-]/g, "") // Keep alphanumeric + path chars
    .trim();
}

/**
 * Behavior Analytics — Use Cases
 *
 * Track user behavior events and generate aggregate reports.
 */

import { createServiceClient } from "@/infrastructure/supabase/server";
import { createHash } from "crypto";

// ─── Track Event ────────────────────────────────────────────────────

export interface TrackEventInput {
  eventName: string;
  userEmail: string;
  sessionId?: string | undefined;
  tier?: number | undefined;
  region?: string | undefined;
  deviceClass?: string | undefined;
  queryNorm?: string | undefined;
  objectPathNorm?: string | undefined;
  objectType?: string | undefined;
  status?: string | undefined;
  filesCount?: number | undefined;
  bytesAuthorized?: number | undefined;
  bytesObserved?: number | undefined;
  resultCount?: number | undefined;
  meta?: Record<string, unknown> | undefined;
}

/**
 * Track a behavior event.
 * PII (email, session) is hashed before storage — never stored in plain text.
 */
export async function trackBehaviorEvent(input: TrackEventInput): Promise<void> {
  const supabase = createServiceClient();

  const eventUuid = crypto.randomUUID();
  const userIdHash = hashIdentity(input.userEmail);
  const sessionIdHash = input.sessionId ? hashIdentity(input.sessionId) : null;

  await supabase.from("behavior_events").insert({
    event_uuid: eventUuid,
    event_time: new Date().toISOString(),
    event_name: input.eventName,
    user_id_hash: userIdHash,
    session_id_hash: sessionIdHash,
    tier: input.tier ?? null,
    region: input.region ?? null,
    device_class: input.deviceClass ?? null,
    query_norm: input.queryNorm ?? null,
    object_path_norm: input.objectPathNorm ?? null,
    object_type: input.objectType ?? null,
    status: input.status ?? null,
    files_count: input.filesCount ?? null,
    bytes_authorized: input.bytesAuthorized ?? null,
    bytes_observed: input.bytesObserved ?? null,
    result_count: input.resultCount ?? null,
    meta_json: input.meta ?? null,
  });
}

// ─── Behavior Report ────────────────────────────────────────────────

export interface BehaviorReportRow {
  dayDate: string;
  eventName: string;
  count: number;
  tier?: number;
  deviceClass?: string;
}

/**
 * Generate a behavior report for a date range.
 * Uses the pre-aggregated behavior_daily table.
 */
export async function getBehaviorReport(
  startDate: string,
  endDate: string,
): Promise<BehaviorReportRow[]> {
  const supabase = createServiceClient();

  const { data } = await supabase
    .from("behavior_daily")
    .select("day_date, metric_key, metric_count, tier, device_class")
    .gte("day_date", startDate)
    .lte("day_date", endDate)
    .order("day_date", { ascending: false })
    .limit(500);

  return (data ?? []).map((row) => ({
    dayDate: row.day_date,
    eventName: row.metric_key,
    count: Number(row.metric_count),
    tier: row.tier ?? undefined,
    deviceClass: row.device_class ?? undefined,
  }));
}

// ─── Download Report ────────────────────────────────────────────────

export interface TopFolder {
  folderPath: string;
  folderName: string;
  downloadCount: number;
}

/**
 * Get the top downloaded folders.
 */
export async function getTopDownloadedFolders(
  limit = 30,
): Promise<TopFolder[]> {
  const supabase = createServiceClient();

  // Group by folder_path and count
  const { data } = await supabase
    .from("folder_download_events")
    .select("folder_path, folder_name")
    .order("downloaded_at", { ascending: false })
    .limit(5000);

  if (!data) return [];

  // Manual aggregation (Supabase doesn't support GROUP BY natively in select)
  const counts = new Map<string, { name: string; count: number }>();
  for (const row of data) {
    const existing = counts.get(row.folder_path);
    if (existing) {
      existing.count += 1;
    } else {
      counts.set(row.folder_path, { name: row.folder_name, count: 1 });
    }
  }

  return Array.from(counts.entries())
    .map(([path, { name, count }]) => ({
      folderPath: path,
      folderName: name,
      downloadCount: count,
    }))
    .sort((a, b) => b.downloadCount - a.downloadCount)
    .slice(0, limit);
}

// ─── Helpers ────────────────────────────────────────────────────────

/**
 * Hash PII for analytics storage. One-way, consistent.
 */
function hashIdentity(value: string): string {
  return createHash("sha256").update(value.toLowerCase().trim()).digest("hex").slice(0, 16);
}

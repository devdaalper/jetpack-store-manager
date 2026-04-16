/**
 * Generate Presigned URL — Use Case
 *
 * Generates a short-lived presigned URL for downloading or previewing a file.
 * Enforces tier-based access control before generating the URL.
 */

import { createServiceClient } from "@/infrastructure/supabase/server";
import { generatePresignedUrl } from "@/infrastructure/backblaze/presigned-urls";
import { canDownload, canPlay, resolveAllowedTiers, getRemainingPlays } from "@/domain/access";
import type { TierValue } from "@/domain/schemas";

// ─── Types ──────────────────────────────────────────────────────────

export interface PresignedUrlResult {
  url: string;
  intent: "download" | "preview";
  remainingPlays: number; // -1 for unlimited (paid users)
}

export interface PresignedUrlError {
  code: "TIER_INSUFFICIENT" | "PLAY_LIMIT_REACHED" | "FILE_NOT_FOUND";
  message: string;
}

// ─── Use Case ───────────────────────────────────────────────────────

export async function getPresignedUrl(
  filePath: string,
  intent: "download" | "preview",
  userEmail: string,
  userTier: TierValue,
): Promise<{ ok: true; data: PresignedUrlResult } | { ok: false; error: PresignedUrlError }> {
  const supabase = createServiceClient();

  // 1. Verify file exists in index
  const { data: configRow } = await supabase
    .from("app_config")
    .select("value")
    .eq("key", "active_index_version")
    .single();

  const activeVersion = Number(configRow?.value ?? 1);

  const { data: fileRow } = await supabase
    .from("file_index")
    .select("folder")
    .eq("version", activeVersion)
    .eq("path", filePath)
    .single();

  if (!fileRow) {
    return { ok: false, error: { code: "FILE_NOT_FOUND", message: "Archivo no encontrado" } };
  }

  // 2. Check folder permissions
  const { data: permRows } = await supabase
    .from("folder_permissions")
    .select("folder_path, allowed_tiers");

  const permissionsMap: Record<string, TierValue[]> = {};
  for (const row of permRows ?? []) {
    permissionsMap[row.folder_path] = (row.allowed_tiers ?? []) as TierValue[];
  }

  const allowedTiers = resolveAllowedTiers(fileRow.folder, permissionsMap);

  // 3. Access control based on intent
  if (intent === "download") {
    if (!canDownload(userTier, allowedTiers)) {
      return {
        ok: false,
        error: { code: "TIER_INSUFFICIENT", message: "Necesitas una suscripción para descargar este archivo" },
      };
    }
  }

  // 4. For preview: check play limits (demo users only)
  let remainingPlays = -1;

  if (intent === "preview" && userTier === 0) {
    const currentMonth = new Date().toISOString().slice(0, 7); // YYYY-MM

    const { data: playRow } = await supabase
      .from("play_counts")
      .select("play_count, month")
      .eq("email", userEmail.toLowerCase())
      .single();

    const monthlyCount =
      playRow && playRow.month === currentMonth ? playRow.play_count : 0;

    if (!canPlay(userTier, monthlyCount)) {
      return {
        ok: false,
        error: { code: "PLAY_LIMIT_REACHED", message: "Has alcanzado el límite de reproducciones este mes" },
      };
    }

    remainingPlays = getRemainingPlays(userTier, monthlyCount + 1); // +1 because we're about to increment
  }

  // 5. Generate presigned URL
  const url = await generatePresignedUrl(filePath, intent);

  return { ok: true, data: { url, intent, remainingPlays } };
}

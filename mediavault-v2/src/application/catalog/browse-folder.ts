/**
 * Browse Folder — Use Case (Optimized)
 *
 * Uses precomputed folder_tree from app_config for instant subfolder lookup.
 * Total queries per browse: 2 (config batch + files).
 * Previous version: 10-60 queries, 5-10 seconds.
 */

import { cache } from "react";
import { createServiceClient } from "@/infrastructure/supabase/server";
import {
  canAccessFolder,
  canDownload,
  resolveAllowedTiers,
  getMediaKind,
  getFileExtension,
} from "@/domain/access";
import type { TierValue } from "@/domain/schemas";

// ─── Types ──────────────────────────────────────────────────────────

export interface BrowseFolder {
  name: string;
  path: string;
  canDownload: boolean;
}

export interface BrowseFile {
  name: string;
  path: string;
  size: number;
  sizeFmt: string;
  extension: string;
  mediaKind: "audio" | "video" | "other";
  lastModified: string | null;
  canDownload: boolean;
}

export interface BrowseResult {
  folders: BrowseFolder[];
  files: BrowseFile[];
  currentFolder: string;
  breadcrumbs: Array<{ name: string; path: string }>;
  totalFiles: number;
}

// ─── Cached config loader (deduplicates within same request) ────────

interface AppConfigs {
  activeVersion: number;
  junction: string;
  folderTree: Record<string, string[]>;
  permissionsMap: Record<string, TierValue[]>;
}

const getAppConfigs = cache(async (): Promise<AppConfigs> => {
  const supabase = createServiceClient();

  // Single batch query for ALL config + permissions (parallel)
  const [configRes, permRes] = await Promise.all([
    supabase
      .from("app_config")
      .select("key, value")
      .in("key", ["active_index_version", "sidebar_folders", "folder_tree"]),
    supabase
      .from("folder_permissions")
      .select("folder_path, allowed_tiers"),
  ]);

  const configs: Record<string, unknown> = {};
  for (const row of configRes.data ?? []) {
    configs[row.key] = row.value;
  }

  const permissionsMap: Record<string, TierValue[]> = {};
  for (const row of permRes.data ?? []) {
    permissionsMap[row.folder_path] = (row.allowed_tiers ?? []) as TierValue[];
  }

  const sidebarConfig = configs["sidebar_folders"] as { junction?: string } | undefined;

  return {
    activeVersion: Number(configs["active_index_version"] ?? 1),
    junction: sidebarConfig?.junction ?? "",
    folderTree: (configs["folder_tree"] ?? {}) as Record<string, string[]>,
    permissionsMap,
  };
});

// ─── Use Case ───────────────────────────────────────────────────────

export async function browseFolder(
  folderPath: string,
  userTier: TierValue,
): Promise<BrowseResult> {
  const { activeVersion, junction, folderTree, permissionsMap } = await getAppConfigs();

  // Junction: when browsing root, use the precomputed junction path
  const effectivePath = folderPath === "" ? junction : folderPath;

  const normalizedPath = effectivePath.endsWith("/") || effectivePath === ""
    ? effectivePath
    : `${effectivePath}/`;

  // Instant subfolder lookup from precomputed tree (0 queries)
  const childNames = folderTree[normalizedPath] ?? [];

  // Build folder list with access control
  const folders: BrowseFolder[] = [];
  for (const name of childNames) {
    const subPath = `${normalizedPath}${name}/`;
    const allowedTiers = resolveAllowedTiers(subPath, permissionsMap);

    if (!canAccessFolder(userTier, allowedTiers)) continue;

    folders.push({
      name,
      path: subPath,
      canDownload: canDownload(userTier, allowedTiers),
    });
  }

  // Query files directly in this folder (1 query, indexed)
  const supabase = createServiceClient();
  const { data: fileRows, count } = await supabase
    .from("file_index")
    .select("name, path, size, extension, media_kind, last_modified", {
      count: "exact",
    })
    .eq("version", activeVersion)
    .eq("folder", normalizedPath)
    .order("name")
    .limit(500);

  const allowedTiers = resolveAllowedTiers(normalizedPath, permissionsMap);
  const userCanDownload = canDownload(userTier, allowedTiers);

  const files: BrowseFile[] = (fileRows ?? []).map((row) => {
    const ext = row.extension || getFileExtension(row.name);
    return {
      name: row.name,
      path: row.path,
      size: row.size,
      sizeFmt: formatFileSize(row.size),
      extension: ext,
      mediaKind: (row.media_kind as "audio" | "video" | "other") || getMediaKind(ext),
      lastModified: row.last_modified,
      canDownload: userCanDownload,
    };
  });

  const breadcrumbs = buildBreadcrumbs(normalizedPath);

  return {
    folders,
    files,
    currentFolder: normalizedPath,
    breadcrumbs,
    totalFiles: count ?? files.length,
  };
}

// ─── Helpers ────────────────────────────────────────────────────────

function buildBreadcrumbs(
  folderPath: string,
): Array<{ name: string; path: string }> {
  const crumbs: Array<{ name: string; path: string }> = [
    { name: "Inicio", path: "" },
  ];

  if (!folderPath) return crumbs;

  const segments = folderPath.split("/").filter(Boolean);
  let currentPath = "";

  for (const segment of segments) {
    currentPath += `${segment}/`;
    crumbs.push({ name: segment, path: currentPath });
  }

  return crumbs;
}

function formatFileSize(bytes: number): string {
  if (bytes === 0) return "0 B";
  const units = ["B", "KB", "MB", "GB"];
  const i = Math.floor(Math.log(bytes) / Math.log(1024));
  const size = bytes / Math.pow(1024, i);
  return `${size.toFixed(i > 0 ? 1 : 0)} ${units[i]}`;
}

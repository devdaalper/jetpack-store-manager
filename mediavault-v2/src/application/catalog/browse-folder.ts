/**
 * Browse Folder — Use Case
 *
 * Lists the contents of a folder from the file index,
 * applying access control based on user tier.
 */

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

// ─── Use Case ───────────────────────────────────────────────────────

export async function browseFolder(
  folderPath: string,
  userTier: TierValue,
): Promise<BrowseResult> {
  const supabase = createServiceClient();

  const { data: configRow } = await supabase
    .from("app_config")
    .select("value")
    .eq("key", "active_index_version")
    .single();

  const activeVersion = Number(configRow?.value ?? 1);

  // Junction auto-drilling for root
  let effectivePath = folderPath;
  if (folderPath === "") {
    effectivePath = await detectJunction(supabase, activeVersion);
  }

  const normalizedPath = effectivePath.endsWith("/") || effectivePath === ""
    ? effectivePath
    : `${effectivePath}/`;

  // Get folder permissions
  const { data: permRows } = await supabase
    .from("folder_permissions")
    .select("folder_path, allowed_tiers");

  const permissionsMap: Record<string, TierValue[]> = {};
  for (const row of permRows ?? []) {
    permissionsMap[row.folder_path] = (row.allowed_tiers ?? []) as TierValue[];
  }

  // Find immediate subfolders using distributed sampling
  const subfolders = await findSubfolders(supabase, activeVersion, normalizedPath);

  // Build folder list with access control
  const folders: BrowseFolder[] = [];
  for (const subfolder of subfolders.sort()) {
    const subPath = `${normalizedPath}${subfolder}/`;
    const allowedTiers = resolveAllowedTiers(subPath, permissionsMap);

    if (!canAccessFolder(userTier, allowedTiers)) continue;

    folders.push({
      name: subfolder,
      path: subPath,
      canDownload: canDownload(userTier, allowedTiers),
    });
  }

  // Query files directly in this folder (not subfolders)
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

/**
 * Find immediate subfolders of a given prefix by sampling the index.
 * More efficient than scanning all 291K rows.
 */
async function findSubfolders(
  supabase: ReturnType<typeof createServiceClient>,
  activeVersion: number,
  prefix: string,
): Promise<string[]> {
  // Get a sample of folders under this prefix
  const { data, count: totalUnder } = await supabase
    .from("file_index")
    .select("folder", { count: "exact" })
    .eq("version", activeVersion)
    .like("folder", `${prefix}%`)
    .neq("folder", prefix)
    .limit(2000);

  const total = totalUnder ?? 0;
  const children = new Set<string>();

  // Extract immediate children from first batch
  for (const r of data ?? []) {
    const relative = r.folder.slice(prefix.length);
    const slash = relative.indexOf("/");
    if (slash > 0) {
      children.add(relative.slice(0, slash));
    }
  }

  // If there are more rows and we might be missing children, sample more
  if (total > 2000 && children.size < 20) {
    const batchSize = 2000;
    for (let offset = 2000; offset < Math.min(total, 20000); offset += batchSize) {
      const { data: more } = await supabase
        .from("file_index")
        .select("folder")
        .eq("version", activeVersion)
        .like("folder", `${prefix}%`)
        .neq("folder", prefix)
        .range(offset, offset + batchSize - 1);

      for (const r of more ?? []) {
        const relative = r.folder.slice(prefix.length);
        const slash = relative.indexOf("/");
        if (slash > 0) {
          children.add(relative.slice(0, slash));
        }
      }

      // Early exit if we've found enough unique children
      if (children.size >= 50) break;
    }
  }

  return [...children];
}

/**
 * Detect junction: find the common prefix wrapper folder.
 * Uses sampling to efficiently find the natural display root.
 */
async function detectJunction(
  supabase: ReturnType<typeof createServiceClient>,
  activeVersion: number,
): Promise<string> {
  // Get a diverse sample of folders
  const { count: total } = await supabase
    .from("file_index")
    .select("id", { count: "exact", head: true })
    .eq("version", activeVersion);

  if (!total || total === 0) return "";

  const folders = new Set<string>();
  const sampleSize = 500;
  const numSamples = Math.min(5, Math.ceil(total / sampleSize));

  for (let i = 0; i < numSamples; i++) {
    const offset = Math.floor((i / numSamples) * total);
    const { data } = await supabase
      .from("file_index")
      .select("folder")
      .eq("version", activeVersion)
      .range(offset, offset + sampleSize - 1);

    for (const r of data ?? []) {
      folders.add(r.folder);
    }
  }

  const folderList = [...folders];
  if (folderList.length === 0) return "";

  // Find common prefix
  const first = folderList[0] ?? "";
  let prefix = "";
  for (let i = 0; i < first.length; i++) {
    const char = first[i];
    if (folderList.every((p) => p[i] === char)) {
      prefix += char;
    } else {
      break;
    }
  }

  // Trim to last complete folder segment
  const lastSlash = prefix.lastIndexOf("/");
  if (lastSlash > 0) {
    prefix = prefix.slice(0, lastSlash + 1);
  } else {
    prefix = "";
  }

  // Now check if there's only one child at this level — if so, drill deeper
  if (prefix) {
    const children = await findSubfolders(supabase, activeVersion, prefix);
    if (children.length === 1 && children[0]) {
      // Single child — drill into it
      return `${prefix}${children[0]}/`;
    }
  }

  return prefix;
}

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

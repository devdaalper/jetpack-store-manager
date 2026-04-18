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

/**
 * Browse the contents of a folder.
 *
 * @param folderPath - Folder to browse (empty string = root)
 * @param userTier - Current user's tier for access control
 * @param rootPrefix - Junction folder / root prefix configured in settings
 */
export async function browseFolder(
  folderPath: string,
  userTier: TierValue,
  rootPrefix = "",
): Promise<BrowseResult> {
  const supabase = createServiceClient();

  // Get active index version
  const { data: configRow } = await supabase
    .from("app_config")
    .select("value")
    .eq("key", "active_index_version")
    .single();

  const activeVersion = Number(configRow?.value ?? 1);

  // Junction auto-drilling: if browsing root and there's a single wrapper folder,
  // drill through it automatically (up to 10 levels) to find the natural junction.
  let effectivePath = folderPath;
  if (folderPath === "" && !rootPrefix) {
    effectivePath = await detectJunction(supabase, activeVersion);
  }

  // Normalize folder path
  const normalizedPath = effectivePath.endsWith("/") || effectivePath === ""
    ? effectivePath
    : `${effectivePath}/`;
  const fullPrefix = rootPrefix ? `${rootPrefix}${normalizedPath}` : normalizedPath;

  // Get folder permissions for access control
  const { data: permRows } = await supabase
    .from("folder_permissions")
    .select("folder_path, allowed_tiers");

  const permissionsMap: Record<string, TierValue[]> = {};
  for (const row of permRows ?? []) {
    permissionsMap[row.folder_path] = (row.allowed_tiers ?? []) as TierValue[];
  }

  // Query subfolders: distinct folder values one level deeper
  const { data: folderRows } = await supabase
    .from("file_index")
    .select("folder")
    .eq("version", activeVersion)
    .like("folder", `${fullPrefix}%`)
    .order("folder");

  // Extract unique immediate subfolders
  const subfolderSet = new Set<string>();
  for (const row of folderRows ?? []) {
    const relative = row.folder.slice(fullPrefix.length);
    const firstSlash = relative.indexOf("/");
    if (firstSlash > 0) {
      subfolderSet.add(relative.slice(0, firstSlash + 1));
    }
  }

  // Build folder list with access control
  const folders: BrowseFolder[] = [];
  for (const subfolder of Array.from(subfolderSet).sort()) {
    const subPath = `${normalizedPath}${subfolder}`;
    const fullSubPath = `${fullPrefix}${subfolder}`;
    const allowedTiers = resolveAllowedTiers(fullSubPath, permissionsMap);

    // Only show folders the user can access
    if (!canAccessFolder(userTier, allowedTiers)) continue;

    folders.push({
      name: subfolder.replace(/\/$/, ""),
      path: subPath,
      canDownload: canDownload(userTier, allowedTiers),
    });
  }

  // Query files in this exact folder
  const { data: fileRows, count } = await supabase
    .from("file_index")
    .select("name, path, size, extension, media_kind, last_modified", {
      count: "exact",
    })
    .eq("version", activeVersion)
    .eq("folder", fullPrefix)
    .order("name");

  const allowedTiers = resolveAllowedTiers(fullPrefix, permissionsMap);
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

  // Build breadcrumbs
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

/**
 * Junction auto-drilling: finds the natural display level by
 * drilling through single-child wrapper folders (up to 10 levels).
 *
 * Example: If the bucket has "Full Pack [JetPack Store]/" as the only
 * top-level folder, this drills into it automatically so the user
 * sees the content categories directly.
 */
async function detectJunction(
  supabase: ReturnType<typeof createServiceClient>,
  activeVersion: number,
  maxDepth = 10,
): Promise<string> {
  let currentPrefix = "";

  for (let depth = 0; depth < maxDepth; depth++) {
    // Get distinct immediate subfolders at this level
    const { data: rows } = await supabase
      .from("file_index")
      .select("folder")
      .eq("version", activeVersion)
      .eq("depth", depth + 1)
      .limit(100);

    if (!rows || rows.length === 0) break;

    // Get unique folders at this depth that are children of currentPrefix
    const children = new Set<string>();
    for (const row of rows) {
      const folder = row.folder;
      if (currentPrefix && !folder.startsWith(currentPrefix)) continue;
      // Extract the immediate child segment
      const relative = folder.slice(currentPrefix.length);
      const firstSlash = relative.indexOf("/");
      if (firstSlash > 0) {
        children.add(currentPrefix + relative.slice(0, firstSlash + 1));
      }
    }

    // If more than 1 child or 0 children, this is the junction
    if (children.size !== 1) break;

    // Single child — drill into it
    currentPrefix = Array.from(children)[0] ?? "";
  }

  return currentPrefix;
}

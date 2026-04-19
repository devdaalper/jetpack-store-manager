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

  // Junction: read precomputed junction from app_config
  let effectivePath = folderPath;
  if (folderPath === "") {
    const { data: junctionRow } = await supabase
      .from("app_config")
      .select("value")
      .eq("key", "sidebar_folders")
      .single();
    const junction = (junctionRow?.value as { junction?: string } | null)?.junction ?? "";
    effectivePath = junction;
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
 * Find immediate subfolders of a given prefix using cursor-based scanning.
 * Ordered by folder name so we can efficiently discover all unique children.
 */
async function findSubfolders(
  supabase: ReturnType<typeof createServiceClient>,
  activeVersion: number,
  prefix: string,
): Promise<string[]> {
  const children = new Set<string>();
  let lastFolder = prefix;
  let batchCount = 0;
  const maxBatches = 50; // Safety limit

  while (batchCount < maxBatches) {
    const { data } = await supabase
      .from("file_index")
      .select("folder")
      .eq("version", activeVersion)
      .like("folder", `${prefix}%`)
      .gt("folder", lastFolder)
      .order("folder", { ascending: true })
      .limit(500);

    if (!data || data.length === 0) break;

    for (const r of data) {
      const relative = r.folder.slice(prefix.length);
      const slash = relative.indexOf("/");
      if (slash > 0) {
        const child = relative.slice(0, slash);
        if (!children.has(child)) {
          children.add(child);
          // Skip ahead past this child's content
          lastFolder = `${prefix}${child}/\uffff`;
          break; // Restart the query to jump to next child
        }
      }
    }

    // If we didn't find a new child in this batch, advance past it
    if (data.length > 0) {
      const lastRow = data[data.length - 1];
      if (lastFolder < (lastRow?.folder ?? "")) {
        lastFolder = lastRow?.folder ?? lastFolder;
      }
    }

    batchCount++;
  }

  return [...children];
}

// Junction detection is now precomputed and stored in app_config.sidebar_folders.
// See sync-index.ts for how it's computed during B2 sync.

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

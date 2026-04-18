/**
 * Search Catalog — Use Case
 *
 * Full-text search across the file index with type filtering and scoring.
 * Uses normalized fields (name_norm, folder_norm) for accent-insensitive matching.
 */

import { createServiceClient } from "@/infrastructure/supabase/server";
import { getFileExtension, getMediaKind } from "@/domain/access";

// ─── Types ──────────────────────────────────────────────────────────

export interface SearchResult {
  name: string;
  path: string;
  folder: string;
  size: number;
  sizeFmt: string;
  extension: string;
  mediaKind: "audio" | "video" | "other";
  score: number;
}

export interface SearchFolder {
  name: string;
  path: string;
  fullPath: string;
}

export interface SearchResponse {
  folders: SearchFolder[];
  items: SearchResult[];
  total: number;
  query: string;
}

// ─── Use Case ───────────────────────────────────────────────────────

/**
 * Search the file index.
 *
 * @param query - Search query (tokenized by spaces)
 * @param type - Filter by media type (audio, video, or undefined for all)
 * @param limit - Max results (default 50)
 * @param offset - Pagination offset (default 0)
 */
export async function searchCatalog(
  query: string,
  type?: "audio" | "video" | "other",
  limit = 50,
  offset = 0,
): Promise<SearchResponse> {
  const supabase = createServiceClient();

  // Get active index version
  const { data: configRow } = await supabase
    .from("app_config")
    .select("value")
    .eq("key", "active_index_version")
    .single();

  const activeVersion = Number(configRow?.value ?? 1);

  // Normalize query for matching
  const normalizedQuery = normalizeSearchText(query);
  const tokens = normalizedQuery.split(/\s+/).filter((t) => t.length > 0);

  if (tokens.length === 0) {
    return { folders: [], items: [], total: 0, query };
  }

  // Search folders: find distinct folders matching the query
  const { data: folderRows } = await supabase
    .from("file_index")
    .select("folder")
    .eq("version", activeVersion)
    .ilike("folder_norm", `%${normalizedQuery}%`)
    .limit(500);

  const folderSet = new Map<string, SearchFolder>();
  for (const row of folderRows ?? []) {
    const folder = row.folder;
    if (folderSet.has(folder)) continue;
    const name = folder.replace(/\/$/, "").split("/").pop() ?? folder;
    folderSet.set(folder, { name, path: folder, fullPath: folder });
  }
  const folders = Array.from(folderSet.values()).slice(0, 20);

  // Build query — search across name_norm and folder_norm
  // Use ilike for each token (all must match)
  let dbQuery = supabase
    .from("file_index")
    .select("name, path, folder, size, extension, media_kind", { count: "exact" })
    .eq("version", activeVersion);

  // Each token must appear in either name_norm or path_norm
  for (const token of tokens) {
    dbQuery = dbQuery.or(`name_norm.ilike.%${token}%,folder_norm.ilike.%${token}%,path_norm.ilike.%${token}%`);
  }

  // Type filter
  if (type) {
    dbQuery = dbQuery.eq("media_kind", type);
  }

  // Pagination
  dbQuery = dbQuery
    .order("name")
    .range(offset, offset + limit - 1);

  const { data: rows, count } = await dbQuery;

  // Score results — files where ALL tokens match name_norm rank highest
  const items: SearchResult[] = (rows ?? []).map((row) => {
    const ext = row.extension || getFileExtension(row.name);
    const nameNorm = normalizeSearchText(row.name);

    // Scoring: name matches score higher than folder matches
    let score = 0;
    for (const token of tokens) {
      if (nameNorm.includes(token)) score += 10;
      if (nameNorm.startsWith(token)) score += 5;
    }

    return {
      name: row.name,
      path: row.path,
      folder: row.folder,
      size: row.size,
      sizeFmt: formatFileSize(row.size),
      extension: ext,
      mediaKind: (row.media_kind as "audio" | "video" | "other") || getMediaKind(ext),
      score,
    };
  });

  // Sort by score descending, then name ascending
  items.sort((a, b) => b.score - a.score || a.name.localeCompare(b.name));

  return {
    folders,
    items,
    total: count ?? items.length,
    query,
  };
}

// ─── Helpers ────────────────────────────────────────────────────────

function normalizeSearchText(text: string): string {
  return text
    .toLowerCase()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/[^a-z0-9\s._-]/g, "")
    .trim();
}

function formatFileSize(bytes: number): string {
  if (bytes === 0) return "0 B";
  const units = ["B", "KB", "MB", "GB"];
  const i = Math.floor(Math.log(bytes) / Math.log(1024));
  const size = bytes / Math.pow(1024, i);
  return `${size.toFixed(i > 0 ? 1 : 0)} ${units[i] ?? "B"}`;
}

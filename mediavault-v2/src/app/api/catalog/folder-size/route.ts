/**
 * GET /api/catalog/folder-size?path=Music/Rock/
 *
 * Returns the estimated total size and file count for a folder
 * (including all subfolders recursively).
 * Used by the download flow to show a warning before large downloads.
 */

import { NextResponse, type NextRequest } from "next/server";
import { getSessionUser } from "@/lib/auth";
import { createServiceClient } from "@/infrastructure/supabase/server";

export async function GET(request: NextRequest) {
  // 1. Auth
  const user = await getSessionUser();
  if (!user) {
    return NextResponse.json(
      { ok: false, error: { code: "UNAUTHORIZED", message: "Authentication required" } },
      { status: 401 },
    );
  }

  // 2. Get folder path
  const folderPath = request.nextUrl.searchParams.get("path") ?? "";
  if (!folderPath) {
    return NextResponse.json(
      { ok: false, error: { code: "VALIDATION_ERROR", message: "Path is required" } },
      { status: 400 },
    );
  }

  // Normalize path to end with /
  const normalizedPath = folderPath.endsWith("/") ? folderPath : `${folderPath}/`;

  try {
    const supabase = createServiceClient();

    // Get active version
    const { data: configRow } = await supabase
      .from("app_config")
      .select("value")
      .eq("key", "active_index_version")
      .single();

    const activeVersion = Number(configRow?.value ?? 1);

    // Sum size and count files recursively using LIKE prefix match
    // folder LIKE 'Music/Rock/%' matches all subfolders
    const { data, error } = await supabase
      .from("file_index")
      .select("size")
      .eq("version", activeVersion)
      .like("folder", `${normalizedPath}%`);

    if (error) throw error;

    const files = data ?? [];
    const totalSize = files.reduce((sum, f) => sum + (f.size ?? 0), 0);
    const totalFiles = files.length;

    return NextResponse.json({
      ok: true,
      data: {
        path: normalizedPath,
        totalSize,
        totalFiles,
        totalSizeFmt: formatFileSize(totalSize),
      },
    });
  } catch (err) {
    console.error("[folder-size] Error:", err);
    return NextResponse.json(
      { ok: false, error: { code: "INTERNAL_ERROR", message: "Failed to estimate folder size" } },
      { status: 500 },
    );
  }
}

function formatFileSize(bytes: number): string {
  if (bytes === 0) return "0 B";
  const units = ["B", "KB", "MB", "GB", "TB"];
  const i = Math.floor(Math.log(bytes) / Math.log(1024));
  const size = bytes / Math.pow(1024, i);
  return `${size.toFixed(i > 0 ? 1 : 0)} ${units[i]}`;
}

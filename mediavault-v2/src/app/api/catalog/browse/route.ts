/**
 * GET /api/catalog/browse?path=Music/Rock/
 *
 * Returns folder contents (subfolders + files) with access control.
 * Requires authentication.
 */

import { NextResponse, type NextRequest } from "next/server";
import { getSessionUser } from "@/lib/auth";
import { browseFolder } from "@/application/catalog/browse-folder";
import { BrowseFolderRequestSchema } from "@/domain/schemas";

export async function GET(request: NextRequest) {
  // 1. Auth
  const user = await getSessionUser();
  if (!user) {
    return NextResponse.json(
      { ok: false, error: { code: "UNAUTHORIZED", message: "Authentication required" } },
      { status: 401 },
    );
  }

  // 2. Validate input
  const rawPath = request.nextUrl.searchParams.get("path") ?? "";
  const parsed = BrowseFolderRequestSchema.safeParse({ path: rawPath });

  if (!parsed.success) {
    return NextResponse.json(
      { ok: false, error: { code: "VALIDATION_ERROR", message: "Invalid folder path" } },
      { status: 400 },
    );
  }

  // 3. Execute use case
  try {
    const result = await browseFolder(parsed.data.path, user.tier);
    return NextResponse.json({ ok: true, data: result });
  } catch (err) {
    console.error("[browse] Error:", err);
    return NextResponse.json(
      { ok: false, error: { code: "INTERNAL_ERROR", message: "Failed to browse folder" } },
      { status: 500 },
    );
  }
}

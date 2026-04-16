/**
 * PUT /api/admin/folders
 *
 * Update folder permissions. Admin only.
 * Body: { folderPath: string, allowedTiers: number[] }
 */

import { NextResponse, type NextRequest } from "next/server";
import { getSessionUser } from "@/lib/auth";
import { createServiceClient } from "@/infrastructure/supabase/server";
import { UpdateFolderPermissionRequestSchema } from "@/domain/schemas";

export async function PUT(request: NextRequest) {
  const user = await getSessionUser();
  if (!user?.isAdmin) {
    return NextResponse.json(
      { ok: false, error: { code: "FORBIDDEN", message: "Admin access required" } },
      { status: 403 },
    );
  }

  let parsed;
  try {
    const body = await request.json();
    parsed = UpdateFolderPermissionRequestSchema.parse(body);
  } catch {
    return NextResponse.json(
      { ok: false, error: { code: "VALIDATION_ERROR", message: "Invalid request" } },
      { status: 400 },
    );
  }

  const supabase = createServiceClient();

  // Upsert: create or update
  const { error } = await supabase
    .from("folder_permissions")
    .upsert(
      {
        folder_path: parsed.folderPath,
        allowed_tiers: parsed.allowedTiers,
        updated_at: new Date().toISOString(),
      },
      { onConflict: "folder_path" },
    );

  if (error) {
    return NextResponse.json(
      { ok: false, error: { code: "UPDATE_FAILED", message: error.message } },
      { status: 500 },
    );
  }

  return NextResponse.json({
    ok: true,
    data: { folderPath: parsed.folderPath, allowedTiers: parsed.allowedTiers },
  });
}

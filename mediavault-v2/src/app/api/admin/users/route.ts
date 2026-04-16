/**
 * PUT /api/admin/users
 *
 * Update a user's tier. Admin only.
 * Body: { email: string, tier: number }
 */

import { NextResponse, type NextRequest } from "next/server";
import { getSessionUser } from "@/lib/auth";
import { createServiceClient } from "@/infrastructure/supabase/server";
import { UpdateTierRequestSchema } from "@/domain/schemas";

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
    parsed = UpdateTierRequestSchema.parse(body);
  } catch {
    return NextResponse.json(
      { ok: false, error: { code: "VALIDATION_ERROR", message: "Invalid request" } },
      { status: 400 },
    );
  }

  const supabase = createServiceClient();

  const { error } = await supabase
    .from("profiles")
    .update({ tier: parsed.tier, updated_at: new Date().toISOString() })
    .eq("email", parsed.email);

  if (error) {
    return NextResponse.json(
      { ok: false, error: { code: "UPDATE_FAILED", message: error.message } },
      { status: 500 },
    );
  }

  return NextResponse.json({ ok: true, data: { email: parsed.email, tier: parsed.tier } });
}

/**
 * POST /api/admin/sync
 *
 * Triggers a batch of the S3 → Supabase index sync.
 * Admin only. Call in a loop until status === "completed".
 *
 * Body: { prefix?: string, continuationToken?: string }
 */

import { NextResponse, type NextRequest } from "next/server";
import { getSessionUser } from "@/lib/auth";
import { syncIndexBatch } from "@/application/catalog/sync-index";

export async function POST(request: NextRequest) {
  // 1. Auth — admin only
  const user = await getSessionUser();
  if (!user) {
    return NextResponse.json(
      { ok: false, error: { code: "UNAUTHORIZED", message: "Authentication required" } },
      { status: 401 },
    );
  }
  if (!user.isAdmin) {
    return NextResponse.json(
      { ok: false, error: { code: "FORBIDDEN", message: "Admin access required" } },
      { status: 403 },
    );
  }

  // 2. Parse body
  let prefix = "";
  let continuationToken: string | undefined;

  try {
    const body = (await request.json()) as Record<string, unknown>;
    prefix = typeof body["prefix"] === "string" ? body["prefix"] : "";
    continuationToken =
      typeof body["continuationToken"] === "string"
        ? body["continuationToken"]
        : undefined;
  } catch {
    // Empty body is fine — start fresh sync
  }

  // 3. Execute batch
  try {
    const result = await syncIndexBatch(prefix, continuationToken);
    return NextResponse.json({ ok: true, data: result });
  } catch (err) {
    console.error("[sync] Error:", err);
    return NextResponse.json(
      { ok: false, error: { code: "SYNC_ERROR", message: "Sync batch failed" } },
      { status: 500 },
    );
  }
}

/**
 * POST /api/media/presigned-url
 *
 * Generate a presigned URL for downloading or previewing a file.
 * Enforces tier-based access control.
 *
 * Body: { path: string, intent: "download" | "preview" }
 */

import { NextResponse, type NextRequest } from "next/server";
import { getSessionUser } from "@/lib/auth";
import { getPresignedUrl } from "@/application/media/generate-presigned-url";
import { PresignedUrlRequestSchema } from "@/domain/schemas";

export async function POST(request: NextRequest) {
  const user = await getSessionUser();
  if (!user) {
    return NextResponse.json(
      { ok: false, error: { code: "UNAUTHORIZED", message: "Authentication required" } },
      { status: 401 },
    );
  }

  // Validate input
  let parsed;
  try {
    const body = await request.json();
    parsed = PresignedUrlRequestSchema.parse(body);
  } catch {
    return NextResponse.json(
      { ok: false, error: { code: "VALIDATION_ERROR", message: "Invalid request body" } },
      { status: 400 },
    );
  }

  // Execute use case
  const result = await getPresignedUrl(parsed.path, parsed.intent, user.email, user.tier);

  if (!result.ok) {
    const status = result.error.code === "TIER_INSUFFICIENT" ? 403
      : result.error.code === "PLAY_LIMIT_REACHED" ? 429
      : 404;
    return NextResponse.json({ ok: false, error: result.error }, { status });
  }

  return NextResponse.json({ ok: true, data: result.data });
}

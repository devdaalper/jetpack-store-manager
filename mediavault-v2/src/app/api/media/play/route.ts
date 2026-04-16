/**
 * POST /api/media/play
 *
 * Track a play event. Increments demo user play count.
 * Called when the user starts playback.
 *
 * Body: { path: string }
 */

import { NextResponse, type NextRequest } from "next/server";
import { getSessionUser } from "@/lib/auth";
import { trackPlay } from "@/application/media/track-play";
import { PlayRequestSchema } from "@/domain/schemas";

export async function POST(request: NextRequest) {
  const user = await getSessionUser();
  if (!user) {
    return NextResponse.json(
      { ok: false, error: { code: "UNAUTHORIZED", message: "Authentication required" } },
      { status: 401 },
    );
  }

  // Validate
  try {
    const body = await request.json();
    PlayRequestSchema.parse(body);
  } catch {
    return NextResponse.json(
      { ok: false, error: { code: "VALIDATION_ERROR", message: "Invalid request" } },
      { status: 400 },
    );
  }

  // Track play
  const result = await trackPlay(user.email, user.tier);

  return NextResponse.json({ ok: true, data: result });
}

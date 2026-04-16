/**
 * POST /api/track-event
 *
 * Track a user behavior event. Authenticated users only.
 * Fail-open: errors never block the UI.
 */

import { NextResponse, type NextRequest } from "next/server";
import { getSessionUser } from "@/lib/auth";
import { trackBehaviorEvent } from "@/application/analytics/track-behavior";

export async function POST(request: NextRequest) {
  const user = await getSessionUser();
  if (!user) {
    return NextResponse.json({ ok: false }, { status: 401 });
  }

  try {
    const body = (await request.json()) as Record<string, unknown>;

    await trackBehaviorEvent({
      eventName: String(body["eventName"] ?? "unknown"),
      userEmail: user.email,
      sessionId: typeof body["sessionId"] === "string" ? body["sessionId"] : undefined,
      tier: user.tier,
      region: typeof body["region"] === "string" ? body["region"] : undefined,
      deviceClass: typeof body["deviceClass"] === "string" ? body["deviceClass"] : undefined,
      queryNorm: typeof body["queryNorm"] === "string" ? body["queryNorm"] : undefined,
      objectPathNorm: typeof body["objectPathNorm"] === "string" ? body["objectPathNorm"] : undefined,
      objectType: typeof body["objectType"] === "string" ? body["objectType"] : undefined,
      status: typeof body["status"] === "string" ? body["status"] : undefined,
      filesCount: typeof body["filesCount"] === "number" ? body["filesCount"] : undefined,
      bytesAuthorized: typeof body["bytesAuthorized"] === "number" ? body["bytesAuthorized"] : undefined,
      bytesObserved: typeof body["bytesObserved"] === "number" ? body["bytesObserved"] : undefined,
      resultCount: typeof body["resultCount"] === "number" ? body["resultCount"] : undefined,
    });

    return NextResponse.json({ ok: true });
  } catch {
    // Fail-open: analytics errors never block the user
    return NextResponse.json({ ok: true });
  }
}

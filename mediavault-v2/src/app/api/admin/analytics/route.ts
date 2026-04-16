/**
 * /api/admin/analytics
 *
 * GET — Behavior report + top downloaded folders
 */

import { NextResponse, type NextRequest } from "next/server";
import { getSessionUser } from "@/lib/auth";
import { getBehaviorReport, getTopDownloadedFolders } from "@/application/analytics/track-behavior";

export async function GET(request: NextRequest) {
  const user = await getSessionUser();
  if (!user?.isAdmin) {
    return NextResponse.json(
      { ok: false, error: { code: "FORBIDDEN", message: "Admin access required" } },
      { status: 403 },
    );
  }

  const startDate = request.nextUrl.searchParams.get("start") ?? getMonthStart();
  const endDate = request.nextUrl.searchParams.get("end") ?? new Date().toISOString().slice(0, 10);

  try {
    const [behavior, topFolders] = await Promise.all([
      getBehaviorReport(startDate, endDate),
      getTopDownloadedFolders(30),
    ]);

    return NextResponse.json({
      ok: true,
      data: { behavior, topFolders, period: { start: startDate, end: endDate } },
    });
  } catch (err) {
    return NextResponse.json(
      { ok: false, error: { code: "INTERNAL_ERROR", message: (err as Error).message } },
      { status: 500 },
    );
  }
}

function getMonthStart(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-01`;
}

/**
 * GET /api/catalog/search?q=reggaeton&type=audio&limit=50&offset=0
 *
 * Search the file catalog with optional type filter.
 */

import { NextResponse, type NextRequest } from "next/server";
import { getSessionUser } from "@/lib/auth";
import { searchCatalog } from "@/application/catalog/search-catalog";
import { SearchRequestSchema } from "@/domain/schemas";

export async function GET(request: NextRequest) {
  const user = await getSessionUser();
  if (!user) {
    return NextResponse.json(
      { ok: false, error: { code: "UNAUTHORIZED", message: "Authentication required" } },
      { status: 401 },
    );
  }

  // Validate
  const params = request.nextUrl.searchParams;
  const parsed = SearchRequestSchema.safeParse({
    q: params.get("q") ?? "",
    type: params.get("type") || undefined,
    limit: params.get("limit") ? Number(params.get("limit")) : undefined,
    offset: params.get("offset") ? Number(params.get("offset")) : undefined,
  });

  if (!parsed.success) {
    return NextResponse.json(
      { ok: false, error: { code: "VALIDATION_ERROR", message: "Invalid search parameters" } },
      { status: 400 },
    );
  }

  try {
    const result = await searchCatalog(
      parsed.data.q,
      parsed.data.type as "audio" | "video" | "other" | undefined,
      parsed.data.limit,
      parsed.data.offset,
    );

    return NextResponse.json({
      ok: true,
      data: result.items,
      pagination: {
        total: result.total,
        hasMore: parsed.data.offset + parsed.data.limit < result.total,
      },
    });
  } catch (err) {
    console.error("[search] Error:", err);
    return NextResponse.json(
      { ok: false, error: { code: "INTERNAL_ERROR", message: "Search failed" } },
      { status: 500 },
    );
  }
}

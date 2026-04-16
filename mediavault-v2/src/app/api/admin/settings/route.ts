/**
 * /api/admin/settings
 *
 * GET  — Get all config values
 * PUT  — Update config values
 */

import { NextResponse, type NextRequest } from "next/server";
import { getSessionUser } from "@/lib/auth";
import { getAllConfig, setConfigBatch, type ConfigKey, CONFIG_KEYS } from "@/application/config/manage-config";

export async function GET() {
  const user = await getSessionUser();
  if (!user?.isAdmin) {
    return NextResponse.json(
      { ok: false, error: { code: "FORBIDDEN", message: "Admin access required" } },
      { status: 403 },
    );
  }

  const config = await getAllConfig();
  return NextResponse.json({ ok: true, data: config });
}

export async function PUT(request: NextRequest) {
  const user = await getSessionUser();
  if (!user?.isAdmin) {
    return NextResponse.json(
      { ok: false, error: { code: "FORBIDDEN", message: "Admin access required" } },
      { status: 403 },
    );
  }

  let body: Record<string, unknown>;
  try {
    body = (await request.json()) as Record<string, unknown>;
  } catch {
    return NextResponse.json(
      { ok: false, error: { code: "VALIDATION_ERROR", message: "Invalid JSON" } },
      { status: 400 },
    );
  }

  // Only accept known config keys
  const validKeys = Object.keys(CONFIG_KEYS);
  const entries: Array<{ key: ConfigKey; value: unknown }> = [];

  for (const [key, value] of Object.entries(body)) {
    if (validKeys.includes(key)) {
      entries.push({ key: key as ConfigKey, value });
    }
  }

  if (entries.length === 0) {
    return NextResponse.json(
      { ok: false, error: { code: "VALIDATION_ERROR", message: "No valid config keys" } },
      { status: 400 },
    );
  }

  await setConfigBatch(entries);

  return NextResponse.json({ ok: true, data: { updated: entries.length } });
}

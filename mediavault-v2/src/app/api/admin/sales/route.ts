/**
 * /api/admin/sales
 *
 * GET  — List sales (paginated)
 * POST — Create a new sale
 */

import { NextResponse, type NextRequest } from "next/server";
import { getSessionUser } from "@/lib/auth";
import { createSale, listSales, type CreateSaleInput } from "@/application/sales/create-sale";
import { z } from "zod/v4";
import { PackageIdSchema, RegionSchema, CurrencySchema, EmailSchema } from "@/domain/schemas";

const CreateSaleRequestSchema = z.object({
  email: EmailSchema,
  package: PackageIdSchema,
  region: RegionSchema,
  amount: z.number().nonnegative(),
  currency: CurrencySchema,
});

export async function GET(request: NextRequest) {
  const user = await getSessionUser();
  if (!user?.isAdmin) {
    return NextResponse.json(
      { ok: false, error: { code: "FORBIDDEN", message: "Admin access required" } },
      { status: 403 },
    );
  }

  const page = Number(request.nextUrl.searchParams.get("page") ?? "1");
  const pageSize = Number(request.nextUrl.searchParams.get("pageSize") ?? "25");

  const result = await listSales(page, pageSize);

  return NextResponse.json({
    ok: true,
    data: result.sales,
    pagination: { total: result.total, page, pageSize, hasMore: page * pageSize < result.total },
  });
}

export async function POST(request: NextRequest) {
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
    parsed = CreateSaleRequestSchema.parse(body);
  } catch {
    return NextResponse.json(
      { ok: false, error: { code: "VALIDATION_ERROR", message: "Invalid sale data" } },
      { status: 400 },
    );
  }

  try {
    const result = await createSale(parsed as CreateSaleInput);
    return NextResponse.json({ ok: true, data: result });
  } catch (err) {
    return NextResponse.json(
      { ok: false, error: { code: "CREATE_FAILED", message: (err as Error).message } },
      { status: 500 },
    );
  }
}

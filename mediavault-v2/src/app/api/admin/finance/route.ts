/**
 * /api/admin/finance
 *
 * GET — Finance summary + settlements + expenses
 */

import { NextResponse } from "next/server";
import { getSessionUser } from "@/lib/auth";
import {
  getFinanceSummary,
  listSettlements,
  listExpenses,
} from "@/application/finance/manage-finance";

export async function GET() {
  const user = await getSessionUser();
  if (!user?.isAdmin) {
    return NextResponse.json(
      { ok: false, error: { code: "FORBIDDEN", message: "Admin access required" } },
      { status: 403 },
    );
  }

  try {
    const [summary, settlements, expenses] = await Promise.all([
      getFinanceSummary(),
      listSettlements(50),
      listExpenses(50),
    ]);

    return NextResponse.json({
      ok: true,
      data: { summary, settlements: settlements.settlements, expenses: expenses.expenses },
    });
  } catch (err) {
    return NextResponse.json(
      { ok: false, error: { code: "INTERNAL_ERROR", message: (err as Error).message } },
      { status: 500 },
    );
  }
}

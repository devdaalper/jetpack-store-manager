/**
 * Finance Management — Use Cases
 *
 * Settlements and expenses CRUD + summary reports.
 */

import { createServiceClient } from "@/infrastructure/supabase/server";

// ─── Types ──────────────────────────────────────────────────────────

export interface SettlementInput {
  settlementDate: string; // ISO date
  market: string;
  channel: string;
  currency: string;
  grossAmount: number;
  feeAmount: number;
  netAmount: number;
  fxRate?: number;
  netAmountMxn?: number;
  salesCount: number;
  bankAccount?: string;
  externalRef?: string;
  notes?: string;
}

export interface ExpenseInput {
  expenseDate: string;
  category: string;
  vendor?: string;
  description?: string;
  amount: number;
  currency: string;
  fxRate?: number;
  amountMxn?: number;
  accountLabel?: string;
  notes?: string;
}

export interface FinanceSummary {
  totalRevenueMxn: number;
  totalExpensesMxn: number;
  netIncomeMxn: number;
  settlementCount: number;
  expenseCount: number;
}

// ─── Settlements ────────────────────────────────────────────────────

export async function createSettlement(input: SettlementInput) {
  const supabase = createServiceClient();
  const uid = `stl_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;

  const { error } = await supabase.from("finance_settlements").insert({
    settlement_uid: uid,
    settlement_date: input.settlementDate,
    market: input.market,
    channel: input.channel,
    currency: input.currency,
    gross_amount: input.grossAmount,
    fee_amount: input.feeAmount,
    net_amount: input.netAmount,
    fx_rate: input.fxRate ?? null,
    net_amount_mxn: input.netAmountMxn ?? input.netAmount,
    sales_count: input.salesCount,
    bank_account: input.bankAccount ?? "",
    external_ref: input.externalRef ?? "",
    notes: input.notes ?? "",
  });

  if (error) throw new Error(`Settlement insert failed: ${error.message}`);
  return { settlementUid: uid };
}

export async function listSettlements(limit = 50) {
  const supabase = createServiceClient();

  const { data, count } = await supabase
    .from("finance_settlements")
    .select("*", { count: "exact" })
    .order("settlement_date", { ascending: false })
    .limit(limit);

  return { settlements: data ?? [], total: count ?? 0 };
}

// ─── Expenses ───────────────────────────────────────────────────────

export async function createExpense(input: ExpenseInput) {
  const supabase = createServiceClient();
  const uid = `exp_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;

  const { error } = await supabase.from("finance_expenses").insert({
    expense_uid: uid,
    expense_date: input.expenseDate,
    category: input.category,
    vendor: input.vendor ?? "",
    description: input.description ?? "",
    amount: input.amount,
    currency: input.currency,
    fx_rate: input.fxRate ?? null,
    amount_mxn: input.amountMxn ?? input.amount,
    account_label: input.accountLabel ?? "",
    notes: input.notes ?? "",
    status: "confirmed",
  });

  if (error) throw new Error(`Expense insert failed: ${error.message}`);
  return { expenseUid: uid };
}

export async function listExpenses(limit = 50) {
  const supabase = createServiceClient();

  const { data, count } = await supabase
    .from("finance_expenses")
    .select("*", { count: "exact" })
    .order("expense_date", { ascending: false })
    .limit(limit);

  return { expenses: data ?? [], total: count ?? 0 };
}

// ─── Summary ────────────────────────────────────────────────────────

export async function getFinanceSummary(): Promise<FinanceSummary> {
  const supabase = createServiceClient();

  const { data: settlements } = await supabase
    .from("finance_settlements")
    .select("net_amount_mxn");

  const { data: expenses } = await supabase
    .from("finance_expenses")
    .select("amount_mxn");

  const totalRevenueMxn = (settlements ?? []).reduce(
    (sum, s) => sum + Number(s.net_amount_mxn ?? 0),
    0,
  );

  const totalExpensesMxn = (expenses ?? []).reduce(
    (sum, e) => sum + Number(e.amount_mxn ?? 0),
    0,
  );

  return {
    totalRevenueMxn,
    totalExpensesMxn,
    netIncomeMxn: totalRevenueMxn - totalExpensesMxn,
    settlementCount: settlements?.length ?? 0,
    expenseCount: expenses?.length ?? 0,
  };
}

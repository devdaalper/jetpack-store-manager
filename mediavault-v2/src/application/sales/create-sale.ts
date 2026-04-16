/**
 * Create Sale — Use Case
 *
 * Records a sale, auto-upgrades the user's tier, and optionally
 * registers the user as a lead if they're new.
 */

import { createServiceClient } from "@/infrastructure/supabase/server";
import { resolveTierFromSales } from "@/domain/access";
import { PACKAGE_TO_TIER } from "@/domain/schemas";
import type { PackageId, Currency, Region } from "@/domain/schemas";

export interface CreateSaleInput {
  email: string;
  package: PackageId;
  region: Region;
  amount: number;
  currency: Currency;
}

export interface CreateSaleResult {
  saleUid: string;
  email: string;
  package: PackageId;
  tier: number;
  isNewUser: boolean;
}

export async function createSale(input: CreateSaleInput): Promise<CreateSaleResult> {
  const supabase = createServiceClient();
  const email = input.email.toLowerCase().trim();
  const saleUid = `sale_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;

  // 1. Insert sale record
  await supabase.from("sales").insert({
    sale_uid: saleUid,
    sale_time: new Date().toISOString(),
    email,
    package: input.package,
    region: input.region,
    amount: input.amount,
    currency: input.currency,
    status: "Completado",
  });

  // 2. Get all sales for this email to resolve tier
  const { data: allSales } = await supabase
    .from("sales")
    .select("package, status")
    .eq("email", email);

  const newTier = resolveTierFromSales(allSales ?? []);

  // 3. Update user tier (or create profile if doesn't exist)
  const { data: existingProfile } = await supabase
    .from("profiles")
    .select("id")
    .eq("email", email)
    .single();

  let isNewUser = false;

  if (existingProfile) {
    await supabase
      .from("profiles")
      .update({ tier: newTier, updated_at: new Date().toISOString() })
      .eq("email", email);
  } else {
    // Create profile without user_id (will be linked on first login)
    await supabase.from("profiles").insert({
      email,
      tier: newTier,
      is_admin: false,
    });
    isNewUser = true;
  }

  // 4. Register as lead if new
  if (isNewUser) {
    await supabase.from("leads").upsert(
      { email, registered_at: new Date().toISOString(), source: "sale" },
      { onConflict: "email" },
    );
  }

  return {
    saleUid,
    email,
    package: input.package,
    tier: newTier,
    isNewUser,
  };
}

/**
 * List sales with pagination.
 */
export async function listSales(
  page = 1,
  pageSize = 25,
): Promise<{ sales: SaleRow[]; total: number }> {
  const supabase = createServiceClient();
  const offset = (page - 1) * pageSize;

  const { data, count } = await supabase
    .from("sales")
    .select("*", { count: "exact" })
    .order("sale_time", { ascending: false })
    .range(offset, offset + pageSize - 1);

  return { sales: (data ?? []) as SaleRow[], total: count ?? 0 };
}

export interface SaleRow {
  id: number;
  sale_uid: string;
  sale_time: string;
  email: string;
  package: string;
  region: string;
  amount: number;
  currency: string;
  status: string;
}

/**
 * Auth callback — handles the magic link redirect from Supabase.
 *
 * After Supabase verifies the magic link, it redirects here with
 * a code parameter. We exchange it for a session and redirect
 * the user to the vault.
 */

import { NextResponse, type NextRequest } from "next/server";
import { createClient } from "@/infrastructure/supabase/server";

export async function GET(request: NextRequest) {
  const { searchParams, origin } = request.nextUrl;
  const code = searchParams.get("code");
  const redirect = searchParams.get("redirect") ?? "/vault";

  if (code) {
    const supabase = await createClient();
    const { error } = await supabase.auth.exchangeCodeForSession(code);

    if (!error) {
      // Check if user has a profile, auto-resolve tier from sales
      const {
        data: { user },
      } = await supabase.auth.getUser();

      if (user?.email) {
        await autoResolveTier(user.email);
      }

      return NextResponse.redirect(`${origin}${redirect}`);
    }
  }

  // Auth failed — redirect to login with error
  return NextResponse.redirect(`${origin}/login?error=auth_failed`);
}

/**
 * Auto-resolve user tier from sales history on first login.
 * Uses service client to bypass RLS.
 */
async function autoResolveTier(email: string) {
  // Dynamic import to avoid loading service client in every request
  const { createServiceClient } = await import(
    "@/infrastructure/supabase/server"
  );
  const { resolveTierFromSales } = await import("@/domain/access");

  const supabase = createServiceClient();

  // Get all completed sales for this email
  const { data: sales } = await supabase
    .from("sales")
    .select("package, status")
    .eq("email", email.toLowerCase());

  if (!sales || sales.length === 0) return;

  // Resolve tier
  const tier = resolveTierFromSales(sales);

  if (tier > 0) {
    // Update profile tier
    await supabase
      .from("profiles")
      .update({ tier, updated_at: new Date().toISOString() })
      .eq("email", email.toLowerCase());
  }
}

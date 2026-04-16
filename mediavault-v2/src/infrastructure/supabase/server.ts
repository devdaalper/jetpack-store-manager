/**
 * Supabase server client — for use in Server Components, API routes, middleware.
 *
 * Two clients:
 * - createClient(): respects RLS, scoped to current user session
 * - createServiceClient(): bypasses RLS, for admin operations
 */

import { createServerClient } from "@supabase/ssr";
import { createClient as createSupabaseClient } from "@supabase/supabase-js";
import { cookies } from "next/headers";

const SUPABASE_URL = process.env["NEXT_PUBLIC_SUPABASE_URL"] ?? "";
const SUPABASE_ANON_KEY = process.env["NEXT_PUBLIC_SUPABASE_ANON_KEY"] ?? "";
const SUPABASE_SERVICE_ROLE_KEY = process.env["SUPABASE_SERVICE_ROLE_KEY"] ?? "";

/**
 * Create a Supabase client that respects RLS.
 * Uses the current user's session from cookies.
 * Use this for user-facing operations.
 */
export async function createClient() {
  const cookieStore = await cookies();

  return createServerClient(SUPABASE_URL, SUPABASE_ANON_KEY, {
    cookies: {
      getAll() {
        return cookieStore.getAll();
      },
      setAll(cookiesToSet) {
        for (const { name, value, options } of cookiesToSet) {
          cookieStore.set(name, value, options);
        }
      },
    },
  });
}

/**
 * Create a Supabase client that bypasses RLS.
 * Uses the service_role key — NEVER expose to the browser.
 * Use this for admin operations and background jobs.
 */
export function createServiceClient() {
  return createSupabaseClient(SUPABASE_URL, SUPABASE_SERVICE_ROLE_KEY, {
    auth: { persistSession: false },
  });
}

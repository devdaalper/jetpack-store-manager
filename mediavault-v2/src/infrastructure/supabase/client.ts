/**
 * Supabase browser client — for use in Client Components.
 *
 * Uses the anon key (safe to expose) and respects RLS.
 * Session is managed via cookies automatically by @supabase/ssr.
 */

import { createBrowserClient } from "@supabase/ssr";

const SUPABASE_URL = process.env["NEXT_PUBLIC_SUPABASE_URL"] ?? "";
const SUPABASE_ANON_KEY = process.env["NEXT_PUBLIC_SUPABASE_ANON_KEY"] ?? "";

export function createClient() {
  return createBrowserClient(SUPABASE_URL, SUPABASE_ANON_KEY);
}

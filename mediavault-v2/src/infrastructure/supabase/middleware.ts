/**
 * Supabase middleware client — for use in Next.js middleware.
 *
 * Refreshes the auth session on every request and writes
 * updated cookies to the response.
 */

import { createServerClient } from "@supabase/ssr";
import { type NextRequest, NextResponse } from "next/server";

const SUPABASE_URL = process.env["NEXT_PUBLIC_SUPABASE_URL"] ?? "";
const SUPABASE_ANON_KEY = process.env["NEXT_PUBLIC_SUPABASE_ANON_KEY"] ?? "";

export async function updateSession(request: NextRequest) {
  let supabaseResponse = NextResponse.next({ request });

  const supabase = createServerClient(SUPABASE_URL, SUPABASE_ANON_KEY, {
    cookies: {
      getAll() {
        return request.cookies.getAll();
      },
      setAll(cookiesToSet) {
        for (const { name, value } of cookiesToSet) {
          request.cookies.set(name, value);
        }
        supabaseResponse = NextResponse.next({ request });
        for (const { name, value, options } of cookiesToSet) {
          supabaseResponse.cookies.set(name, value, options);
        }
      },
    },
  });

  // Refresh session — this writes updated cookies if needed
  const {
    data: { user },
  } = await supabase.auth.getUser();

  return { user, supabaseResponse };
}

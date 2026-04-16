/**
 * Next.js Middleware — Auth guard.
 *
 * Runs on every request. Refreshes Supabase session and
 * redirects unauthenticated users to /login for protected routes.
 */

import { type NextRequest, NextResponse } from "next/server";
import { updateSession } from "@/infrastructure/supabase/middleware";

/** Routes that require authentication */
const PROTECTED_PREFIXES = ["/vault", "/admin", "/api/catalog", "/api/media", "/api/admin"];

/** Routes that are always public */
const PUBLIC_ROUTES = ["/login", "/auth/callback", "/api/auth"];

export async function middleware(request: NextRequest) {
  const { pathname } = request.nextUrl;

  // Always allow public routes
  if (PUBLIC_ROUTES.some((route) => pathname.startsWith(route))) {
    const { supabaseResponse } = await updateSession(request);
    return supabaseResponse;
  }

  // Check if this is a protected route
  const isProtected = PROTECTED_PREFIXES.some((prefix) =>
    pathname.startsWith(prefix),
  );

  if (!isProtected) {
    const { supabaseResponse } = await updateSession(request);
    return supabaseResponse;
  }

  // Protected route — require auth
  const { user, supabaseResponse } = await updateSession(request);

  if (!user) {
    // API routes return 401, pages redirect to login
    if (pathname.startsWith("/api/")) {
      return NextResponse.json(
        { ok: false, error: { code: "UNAUTHORIZED", message: "Authentication required" } },
        { status: 401 },
      );
    }

    const loginUrl = request.nextUrl.clone();
    loginUrl.pathname = "/login";
    loginUrl.searchParams.set("redirect", pathname);
    return NextResponse.redirect(loginUrl);
  }

  return supabaseResponse;
}

export const config = {
  matcher: [
    /*
     * Match all request paths except:
     * - _next/static (static files)
     * - _next/image (image optimization)
     * - favicon.ico
     * - public files (images, etc.)
     */
    "/((?!_next/static|_next/image|favicon.ico|.*\\.(?:svg|png|jpg|jpeg|gif|webp)$).*)",
  ],
};

/**
 * Auth helpers — server-side utilities for getting user info.
 * Uses React.cache() to deduplicate auth calls within the same request.
 */

import { cache } from "react";
import { createClient } from "@/infrastructure/supabase/server";
import type { TierValue } from "@/domain/schemas";

export interface SessionUser {
  id: string;
  email: string;
  tier: TierValue;
  isAdmin: boolean;
}

/**
 * Get the current authenticated user with their profile.
 * Deduplicated per-request via React.cache() — safe to call multiple times.
 */
export const getSessionUser = cache(async (): Promise<SessionUser | null> => {
  const supabase = await createClient();

  const {
    data: { user },
  } = await supabase.auth.getUser();

  if (!user) return null;

  // Fetch profile with tier info
  const { data: profile } = await supabase
    .from("profiles")
    .select("tier, is_admin")
    .eq("user_id", user.id)
    .single();

  return {
    id: user.id,
    email: user.email ?? "",
    tier: (profile?.tier ?? 0) as TierValue,
    isAdmin: profile?.is_admin ?? false,
  };
});

/**
 * Require authentication — throws redirect if not logged in.
 * Use in Server Components.
 */
export async function requireAuth(): Promise<SessionUser> {
  const user = await getSessionUser();
  if (!user) {
    // This will be caught by Next.js and trigger a redirect
    throw new Error("UNAUTHORIZED");
  }
  return user;
}

/**
 * Require admin access — throws if not admin.
 */
export async function requireAdmin(): Promise<SessionUser> {
  const user = await requireAuth();
  if (!user.isAdmin) {
    throw new Error("FORBIDDEN");
  }
  return user;
}

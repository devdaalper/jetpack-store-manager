/**
 * Track Play — Use Case
 *
 * Increments the play count for a demo user.
 * Called AFTER a preview URL is generated and the user starts playback.
 * Server-side only — play counts can never be decremented from the client.
 */

import { createServiceClient } from "@/infrastructure/supabase/server";
import { getRemainingPlays } from "@/domain/access";
import type { TierValue } from "@/domain/schemas";

export interface TrackPlayResult {
  playCount: number;
  remainingPlays: number;
}

/**
 * Increment the play count for a user.
 * Only affects demo users (tier 0). Paid users are no-ops.
 */
export async function trackPlay(
  userEmail: string,
  userTier: TierValue,
): Promise<TrackPlayResult> {
  // Paid users: unlimited, no tracking needed
  if (userTier >= 1) {
    return { playCount: 0, remainingPlays: -1 };
  }

  const supabase = createServiceClient();
  const email = userEmail.toLowerCase();
  const currentMonth = new Date().toISOString().slice(0, 7);

  // Upsert: increment if same month, reset if new month
  const { data: existing } = await supabase
    .from("play_counts")
    .select("play_count, month")
    .eq("email", email)
    .single();

  let newCount: number;

  if (!existing) {
    // First play ever
    await supabase.from("play_counts").insert({
      email,
      play_count: 1,
      month: currentMonth,
      updated_at: new Date().toISOString(),
    });
    newCount = 1;
  } else if (existing.month !== currentMonth) {
    // New month — reset counter
    await supabase
      .from("play_counts")
      .update({
        play_count: 1,
        month: currentMonth,
        updated_at: new Date().toISOString(),
      })
      .eq("email", email);
    newCount = 1;
  } else {
    // Same month — increment
    newCount = existing.play_count + 1;
    await supabase
      .from("play_counts")
      .update({
        play_count: newCount,
        updated_at: new Date().toISOString(),
      })
      .eq("email", email);
  }

  return {
    playCount: newCount,
    remainingPlays: getRemainingPlays(userTier, newCount),
  };
}

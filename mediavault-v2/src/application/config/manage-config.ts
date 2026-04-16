/**
 * App Configuration — Use Case
 *
 * Manages key-value config stored in the app_config table.
 * Replaces WordPress wp_options for app-specific settings.
 */

import { createServiceClient } from "@/infrastructure/supabase/server";

// ─── Config Keys ────────────────────────────────────────────────────

/** All known configuration keys with their types */
export const CONFIG_KEYS = {
  // B2 Storage (set via env vars, shown read-only in UI)
  active_index_version: "number",

  // Cloudflare
  cloudflare_domain: "string",

  // WhatsApp
  whatsapp_number: "string",

  // Sidebar order
  sidebar_order: "json",

  // Pricing (MXN)
  price_mxn_basic: "number",
  price_mxn_vip_basic: "number",
  price_mxn_vip_videos: "number",
  price_mxn_vip_pelis: "number",
  price_mxn_full: "number",

  // Pricing (USD)
  price_usd_vip_videos: "number",
  price_usd_vip_pelis: "number",
  price_usd_full: "number",

  // Email templates (HTML)
  email_template_basic: "html",
  email_template_vip_basic: "html",
  email_template_vip_videos: "html",
  email_template_vip_pelis: "html",
  email_template_full: "html",

  // Email settings
  notify_emails: "json",
  reply_to_email: "string",
} as const;

export type ConfigKey = keyof typeof CONFIG_KEYS;

// ─── Read ───────────────────────────────────────────────────────────

/**
 * Get all config values as a flat object.
 */
export async function getAllConfig(): Promise<Record<string, unknown>> {
  const supabase = createServiceClient();

  const { data } = await supabase
    .from("app_config")
    .select("key, value")
    .order("key");

  const config: Record<string, unknown> = {};
  for (const row of data ?? []) {
    config[row.key] = row.value;
  }
  return config;
}

/**
 * Get a single config value.
 */
export async function getConfig<T = unknown>(key: ConfigKey): Promise<T | null> {
  const supabase = createServiceClient();

  const { data } = await supabase
    .from("app_config")
    .select("value")
    .eq("key", key)
    .single();

  return (data?.value as T) ?? null;
}

// ─── Write ──────────────────────────────────────────────────────────

/**
 * Set a config value. Creates or updates.
 */
export async function setConfig(key: ConfigKey, value: unknown): Promise<void> {
  const supabase = createServiceClient();

  await supabase.from("app_config").upsert(
    {
      key,
      value: value as Record<string, unknown>,
      updated_at: new Date().toISOString(),
    },
    { onConflict: "key" },
  );
}

/**
 * Set multiple config values at once.
 */
export async function setConfigBatch(
  entries: Array<{ key: ConfigKey; value: unknown }>,
): Promise<void> {
  const supabase = createServiceClient();

  const rows = entries.map((e) => ({
    key: e.key,
    value: e.value as Record<string, unknown>,
    updated_at: new Date().toISOString(),
  }));

  await supabase.from("app_config").upsert(rows, { onConflict: "key" });
}

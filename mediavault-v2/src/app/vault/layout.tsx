/**
 * Vault Layout — Optimized: 2 queries total (batch config + auth).
 * Server Component: fetches precomputed sidebar data and user profile.
 */

import { redirect } from "next/navigation";
import { getSessionUser } from "@/lib/auth";
import { createServiceClient } from "@/infrastructure/supabase/server";
import { VaultShell } from "./vault-shell";

interface SidebarConfig {
  junction: string;
  folders: Array<{ name: string; path: string }>;
}

export default async function VaultLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  // Auth (deduplicated via React.cache — free if page also calls it)
  const user = await getSessionUser();
  if (!user) redirect("/login?redirect=/vault");

  // Single batch query for all config values
  const supabase = createServiceClient();
  const { data: configs } = await supabase
    .from("app_config")
    .select("key, value")
    .in("key", ["sidebar_folders", "whatsapp_number"]);

  const configMap: Record<string, unknown> = {};
  for (const row of configs ?? []) {
    configMap[row.key] = row.value;
  }

  const sidebarConfig = configMap["sidebar_folders"] as SidebarConfig | null;
  const sidebarFolders = sidebarConfig?.folders ?? [];

  const rawWa = configMap["whatsapp_number"];
  const whatsappNumber = rawWa ? String(rawWa).replace(/"/g, "") : undefined;

  return (
    <VaultShell
      folders={sidebarFolders}
      userEmail={user.email}
      userTier={user.tier}
      whatsappNumber={whatsappNumber || undefined}
    >
      {children}
    </VaultShell>
  );
}

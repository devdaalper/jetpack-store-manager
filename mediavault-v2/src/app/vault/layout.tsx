/**
 * Vault Layout — Sidebar + Toolbar + Player Bar + Content.
 * Server Component: fetches precomputed sidebar data and user profile.
 * Sidebar folders are read from app_config (precomputed during sync).
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
  const user = await getSessionUser();
  if (!user) redirect("/login?redirect=/vault");

  const supabase = createServiceClient();

  // Read precomputed sidebar folders (instant — no index scan needed)
  const { data: sidebarRow } = await supabase
    .from("app_config")
    .select("value")
    .eq("key", "sidebar_folders")
    .single();

  const sidebarConfig = sidebarRow?.value as SidebarConfig | null;
  const sidebarFolders = sidebarConfig?.folders ?? [];

  // Get WhatsApp number
  const { data: waRow } = await supabase
    .from("app_config")
    .select("value")
    .eq("key", "whatsapp_number")
    .single();

  const whatsappNumber = waRow?.value ? String(waRow.value).replace(/"/g, "") : undefined;

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

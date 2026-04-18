/**
 * Vault Layout — Sidebar + Toolbar + Player Bar + Content.
 * Server Component: fetches sidebar data and user profile.
 * Client wrapper handles sidebar collapse state + player state.
 */

import { redirect } from "next/navigation";
import { getSessionUser } from "@/lib/auth";
import { createServiceClient } from "@/infrastructure/supabase/server";
import { VaultShell } from "./vault-shell";

export default async function VaultLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  const user = await getSessionUser();
  if (!user) redirect("/login?redirect=/vault");

  // Fetch sidebar folders from index
  const supabase = createServiceClient();

  const { data: configRow } = await supabase
    .from("app_config")
    .select("value")
    .eq("key", "active_index_version")
    .single();

  const activeVersion = Number(configRow?.value ?? 1);

  // Get top-level folders
  const { data: folderRows } = await supabase
    .from("file_index")
    .select("folder")
    .eq("version", activeVersion)
    .eq("depth", 1)
    .order("folder");

  const sidebarFolders = [
    ...new Set((folderRows ?? []).map((r) => r.folder)),
  ].map((f) => ({
    name: f.replace(/\/$/, "").split("/").pop() ?? f,
    path: f,
  }));

  // Get WhatsApp number from config
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

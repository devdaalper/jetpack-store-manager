/**
 * Vault Layout — Sidebar + Toolbar + Player Bar + Content.
 * Server Component: fetches sidebar data and user profile.
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

  const supabase = createServiceClient();

  const { data: configRow } = await supabase
    .from("app_config")
    .select("value")
    .eq("key", "active_index_version")
    .single();

  const activeVersion = Number(configRow?.value ?? 1);

  // Detect sidebar folders by finding the junction point and its children.
  // The junction is the deepest single-child wrapper folder.
  // Strategy: get a diverse sample of folder paths and extract unique segments.
  const sidebarFolders = await getSidebarFolders(supabase, activeVersion);

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

/**
 * Get sidebar folders by sampling the index to find the junction children.
 * Uses distributed sampling to find all top-level categories even in large indexes.
 */
async function getSidebarFolders(
  supabase: ReturnType<typeof createServiceClient>,
  activeVersion: number,
): Promise<Array<{ name: string; path: string }>> {
  // Get total count to calculate sampling offsets
  const { count: totalCount } = await supabase
    .from("file_index")
    .select("id", { count: "exact", head: true })
    .eq("version", activeVersion);

  const total = totalCount ?? 0;
  if (total === 0) return [];

  // Sample folders from distributed positions across the index
  const allFolders = new Set<string>();
  const sampleSize = 1000;
  const numSamples = Math.min(10, Math.ceil(total / sampleSize));

  for (let i = 0; i < numSamples; i++) {
    const offset = Math.floor((i / numSamples) * total);
    const { data } = await supabase
      .from("file_index")
      .select("folder")
      .eq("version", activeVersion)
      .range(offset, offset + sampleSize - 1);

    for (const r of data ?? []) {
      allFolders.add(r.folder);
    }
  }

  // Find the junction: the common prefix that all folders share
  const folderList = [...allFolders];
  if (folderList.length === 0) return [];

  const junction = findCommonPrefix(folderList);

  // Extract unique children at junction level
  const childrenSet = new Set<string>();
  for (const folder of folderList) {
    const relative = folder.slice(junction.length);
    const slash = relative.indexOf("/");
    if (slash > 0) {
      childrenSet.add(relative.slice(0, slash));
    } else if (relative.length > 0 && !relative.includes("/")) {
      childrenSet.add(relative);
    }
  }

  return [...childrenSet]
    .sort()
    .map((name) => ({
      name,
      path: `${junction}${name}/`,
    }));
}

/**
 * Find the longest common prefix of all folder paths.
 * Used to detect the junction folder (e.g., "Full Pack [JetPack Store]/").
 */
function findCommonPrefix(paths: string[]): string {
  if (paths.length === 0) return "";
  if (paths.length === 1) {
    // Single path: return up to last slash
    const lastSlash = paths[0]?.lastIndexOf("/") ?? -1;
    return lastSlash > 0 ? (paths[0]?.slice(0, lastSlash + 1) ?? "") : "";
  }

  const first = paths[0] ?? "";
  let prefix = "";

  for (let i = 0; i < first.length; i++) {
    const char = first[i];
    if (paths.every((p) => p[i] === char)) {
      prefix += char;
    } else {
      break;
    }
  }

  // Trim to last complete folder segment
  const lastSlash = prefix.lastIndexOf("/");
  return lastSlash >= 0 ? prefix.slice(0, lastSlash + 1) : "";
}

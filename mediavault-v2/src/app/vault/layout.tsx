/**
 * Vault Layout — Sidebar + Toolbar + Content area.
 * Server Component: fetches sidebar data and user profile.
 */

import { redirect } from "next/navigation";
import { getSessionUser } from "@/lib/auth";
import { Sidebar } from "@/components/vault/sidebar";
import { SearchBar } from "@/components/vault/search-bar";
import { createServiceClient } from "@/infrastructure/supabase/server";
import { DEMO_PLAY_LIMIT } from "@/domain/types";

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

  // Get remaining plays for demo users
  let remainingPlays = -1;
  if (user.tier === 0) {
    const currentMonth = new Date().toISOString().slice(0, 7);
    const { data: playRow } = await supabase
      .from("play_counts")
      .select("play_count, month")
      .eq("email", user.email.toLowerCase())
      .single();

    const monthlyCount =
      playRow && playRow.month === currentMonth ? playRow.play_count : 0;
    remainingPlays = Math.max(0, DEMO_PLAY_LIMIT - monthlyCount);
  }

  return (
    <div className="flex min-h-screen bg-neutral-50">
      {/* Sidebar — hidden on mobile */}
      <aside className="hidden md:flex w-60 flex-col border-r border-neutral-200 bg-white">
        <div className="p-4 border-b border-neutral-100">
          <h1 className="text-lg font-bold text-neutral-900 tracking-tight">
            MediaVault
          </h1>
          <p className="text-xs text-neutral-400 mt-0.5">{user.email}</p>
        </div>
        <Sidebar folders={sidebarFolders} userEmail={user.email} />
      </aside>

      {/* Main content */}
      <div className="flex-1 min-w-0 flex flex-col">
        {/* Toolbar */}
        <header className="flex items-center justify-between px-6 md:px-8 py-3 border-b border-neutral-100 bg-white">
          <SearchBar />

          <div className="flex items-center gap-3 ml-4">
            {/* Demo plays badge */}
            {remainingPlays >= 0 && (
              <span className="text-xs px-3 py-1 rounded-full bg-green-50 text-green-700 font-medium">
                {remainingPlays} reproducciones
              </span>
            )}

            {/* Tier badge */}
            <span
              className={`text-xs px-3 py-1 rounded-full font-medium ${
                user.tier === 0
                  ? "bg-neutral-100 text-neutral-600"
                  : "bg-orange-50 text-orange-700"
              }`}
            >
              {user.tier === 0
                ? "Demo"
                : user.tier === 5
                  ? "Full"
                  : `Tier ${user.tier}`}
            </span>
          </div>
        </header>

        {/* Page content */}
        <main className="flex-1">{children}</main>
      </div>
    </div>
  );
}

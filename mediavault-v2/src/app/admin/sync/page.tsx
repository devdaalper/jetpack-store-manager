/**
 * Admin: Index Sync Controls
 */

import { createServiceClient } from "@/infrastructure/supabase/server";
import { SyncPanel } from "@/components/admin/sync-panel";

export default async function AdminSyncPage() {
  const supabase = createServiceClient();

  // Get current index stats
  const { data: configRow } = await supabase
    .from("app_config")
    .select("value")
    .eq("key", "active_index_version")
    .single();

  const activeVersion = Number(configRow?.value ?? 1);

  const { count: totalFiles } = await supabase
    .from("file_index")
    .select("id", { count: "exact", head: true })
    .eq("version", activeVersion);

  const { count: audioFiles } = await supabase
    .from("file_index")
    .select("id", { count: "exact", head: true })
    .eq("version", activeVersion)
    .eq("media_kind", "audio");

  const { count: videoFiles } = await supabase
    .from("file_index")
    .select("id", { count: "exact", head: true })
    .eq("version", activeVersion)
    .eq("media_kind", "video");

  return (
    <div>
      <div className="mb-6">
        <h2 className="text-xl font-bold text-neutral-900">Index Sync</h2>
        <p className="text-sm text-neutral-500 mt-1">
          Sincroniza el catálogo desde Backblaze B2.
        </p>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-3 gap-4 mb-8">
        <div className="bg-white rounded-xl border border-neutral-200 p-4">
          <p className="text-2xl font-bold text-neutral-900">{totalFiles ?? 0}</p>
          <p className="text-xs text-neutral-500 mt-1">Archivos indexados</p>
        </div>
        <div className="bg-white rounded-xl border border-neutral-200 p-4">
          <p className="text-2xl font-bold text-neutral-900">{audioFiles ?? 0}</p>
          <p className="text-xs text-neutral-500 mt-1">Audio</p>
        </div>
        <div className="bg-white rounded-xl border border-neutral-200 p-4">
          <p className="text-2xl font-bold text-neutral-900">{videoFiles ?? 0}</p>
          <p className="text-xs text-neutral-500 mt-1">Video</p>
        </div>
      </div>

      {/* Sync controls */}
      <SyncPanel />
    </div>
  );
}

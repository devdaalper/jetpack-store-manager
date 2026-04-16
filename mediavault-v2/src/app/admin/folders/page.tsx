/**
 * Admin: Folder Permissions
 */

import { createServiceClient } from "@/infrastructure/supabase/server";
import { FolderPermEditor } from "@/components/admin/folder-perm-editor";

export default async function AdminFoldersPage() {
  const supabase = createServiceClient();

  const { data: permissions } = await supabase
    .from("folder_permissions")
    .select("id, folder_path, allowed_tiers, updated_at")
    .order("folder_path");

  return (
    <div>
      <div className="mb-6">
        <h2 className="text-xl font-bold text-neutral-900">Permisos por Carpeta</h2>
        <p className="text-sm text-neutral-500 mt-1">
          Define qué tiers pueden acceder a cada carpeta. Las carpetas sin permisos son accesibles para todos.
        </p>
      </div>

      <FolderPermEditor permissions={permissions ?? []} />
    </div>
  );
}

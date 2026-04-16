/**
 * Vault Page — Main file browser.
 *
 * Server Component: fetches folder data and renders.
 * The path is passed via search params: /vault?path=Music/Rock/
 */

import { redirect } from "next/navigation";
import { getSessionUser } from "@/lib/auth";
import { browseFolder } from "@/application/catalog/browse-folder";
import { FolderGrid } from "@/components/vault/folder-grid";
import { Breadcrumbs } from "@/components/vault/breadcrumbs";

interface VaultPageProps {
  searchParams: Promise<{ path?: string }>;
}

export default async function VaultPage({ searchParams }: VaultPageProps) {
  const user = await getSessionUser();
  if (!user) redirect("/login?redirect=/vault");

  const params = await searchParams;
  const folderPath = params.path ?? "";

  const data = await browseFolder(folderPath, user.tier);

  return (
    <div className="p-6 md:p-8 max-w-7xl">
      {/* Breadcrumbs */}
      <Breadcrumbs items={data.breadcrumbs} />

      {/* Header */}
      <div className="mb-6">
        <h2 className="text-2xl font-bold text-neutral-900 tracking-tight">
          {folderPath
            ? folderPath.replace(/\/$/, "").split("/").pop()
            : "Biblioteca"}
        </h2>
        <p className="text-sm text-neutral-500 mt-1">
          {data.folders.length > 0 && `${data.folders.length} carpetas`}
          {data.folders.length > 0 && data.files.length > 0 && " · "}
          {data.files.length > 0 && `${data.files.length} archivos`}
          {data.folders.length === 0 && data.files.length === 0 && "Carpeta vacía"}
        </p>
      </div>

      {/* Content grid */}
      <FolderGrid folders={data.folders} files={data.files} />
    </div>
  );
}

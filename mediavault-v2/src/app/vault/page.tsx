/**
 * Vault Page — File browser with toolbar, breadcrumbs, and content.
 * Server Component for data fetching.
 */

import { redirect } from "next/navigation";
import { getSessionUser } from "@/lib/auth";
import { browseFolder } from "@/application/catalog/browse-folder";
import { Breadcrumbs } from "@/components/vault/breadcrumbs";
import { VaultContent } from "./vault-content";

interface VaultPageProps {
  searchParams: Promise<{ path?: string }>;
}

export default async function VaultPage({ searchParams }: VaultPageProps) {
  const user = await getSessionUser();
  if (!user) redirect("/login?redirect=/vault");

  const params = await searchParams;
  const folderPath = params.path ?? "";

  const data = await browseFolder(folderPath, user.tier);

  const currentName = folderPath
    ? folderPath.replace(/\/$/, "").split("/").pop() ?? "Biblioteca"
    : "Biblioteca";

  return (
    <div>
      {/* Toolbar is rendered inside VaultContent as client component */}
      <VaultContent
        folders={data.folders}
        files={data.files}
        breadcrumbs={data.breadcrumbs}
        currentFolder={folderPath}
        currentName={currentName}
        totalFiles={data.totalFiles}
        folderCount={data.folders.length}
        depth={data.depth}
      />
    </div>
  );
}

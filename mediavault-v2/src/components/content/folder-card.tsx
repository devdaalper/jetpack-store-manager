"use client";

import Link from "next/link";
import { Folder, Download } from "lucide-react";
import { useVaultActions } from "@/hooks/useVaultActions";
import type { BrowseFolder } from "@/application/catalog/browse-folder";

interface FolderCardProps {
  folder: BrowseFolder;
  showDownload?: boolean | undefined;
}

export function FolderCard({ folder, showDownload = false }: FolderCardProps) {
  const { downloadFolder } = useVaultActions();

  return (
    <div className="group bg-white rounded-xl border border-neutral-100 hover:border-neutral-200 hover:shadow-md active:shadow-sm active:scale-[0.98] transition-all duration-200 overflow-hidden">
      {/* Cover — clickable to navigate */}
      <Link href={`/vault?path=${encodeURIComponent(folder.path)}`}>
        <div className="aspect-square bg-gradient-to-br from-neutral-100 to-neutral-200 flex items-center justify-center">
          <Folder className="w-12 h-12 text-neutral-400 group-hover:text-orange-500 transition-colors" strokeWidth={1.5} />
        </div>
      </Link>

      {/* Info + actions */}
      <div className="p-3">
        <Link href={`/vault?path=${encodeURIComponent(folder.path)}`}>
          <h3 className="text-sm font-medium text-neutral-900 truncate leading-tight hover:text-orange-600 transition">
            {folder.name}
          </h3>
        </Link>

        <div className="flex items-center justify-between mt-1">
          <p className="text-xs text-neutral-400">Carpeta</p>

          {/* Download button — only shown at depth >= 2 and if user can download */}
          {showDownload && folder.canDownload && (
            <button
              onClick={(e) => {
                e.preventDefault();
                downloadFolder(folder.path, folder.name);
              }}
              className="p-1.5 rounded-lg text-neutral-400 hover:text-orange-500 hover:bg-orange-50 active:bg-orange-100 transition"
              title={`Descargar ${folder.name}`}
            >
              <Download className="w-4 h-4" />
            </button>
          )}
        </div>
      </div>
    </div>
  );
}

"use client";

import Link from "next/link";
import { Folder } from "lucide-react";
import type { BrowseFolder } from "@/application/catalog/browse-folder";

interface FolderCardProps {
  folder: BrowseFolder;
}

export function FolderCard({ folder }: FolderCardProps) {
  return (
    <Link
      href={`/vault?path=${encodeURIComponent(folder.path)}`}
      className="group block bg-white rounded-xl border border-neutral-100 hover:border-neutral-200 hover:shadow-md transition-all duration-200 hover:-translate-y-0.5 overflow-hidden"
    >
      {/* Cover */}
      <div className="aspect-square bg-gradient-to-br from-neutral-100 to-neutral-200 flex items-center justify-center">
        <Folder className="w-12 h-12 text-neutral-400 group-hover:text-orange-500 transition-colors" strokeWidth={1.5} />
      </div>

      {/* Info */}
      <div className="p-3">
        <h3 className="text-sm font-medium text-neutral-900 truncate leading-tight">
          {folder.name}
        </h3>
        <p className="text-xs text-neutral-400 mt-0.5">Carpeta</p>
      </div>
    </Link>
  );
}

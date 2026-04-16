"use client";

import Link from "next/link";
import type { BrowseFolder, BrowseFile } from "@/application/catalog/browse-folder";

// ─── Folder Card ────────────────────────────────────────────────────

function FolderCard({ folder }: { folder: BrowseFolder }) {
  return (
    <Link
      href={`/vault?path=${encodeURIComponent(folder.path)}`}
      className="group block bg-white rounded-xl shadow-sm hover:shadow-md transition-all duration-200 hover:-translate-y-0.5 border border-transparent hover:border-neutral-200"
    >
      {/* Cover */}
      <div className="aspect-square rounded-t-xl bg-gradient-to-br from-neutral-100 to-neutral-200 flex items-center justify-center">
        <svg
          className="w-12 h-12 text-neutral-400 group-hover:text-orange-500 transition-colors"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
        >
          <path
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeWidth={1.5}
            d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"
          />
        </svg>
      </div>

      {/* Info */}
      <div className="p-3">
        <h3 className="text-sm font-medium text-neutral-900 truncate">
          {folder.name}
        </h3>
        <p className="text-xs text-neutral-500 mt-0.5">Carpeta</p>
      </div>
    </Link>
  );
}

// ─── File Card ──────────────────────────────────────────────────────

function FileCard({ file }: { file: BrowseFile }) {
  const isMedia = file.mediaKind === "audio" || file.mediaKind === "video";
  const icon = file.mediaKind === "audio" ? "🎵" : file.mediaKind === "video" ? "🎬" : "📄";

  return (
    <div className="bg-white rounded-xl shadow-sm border border-transparent hover:shadow-md hover:border-neutral-200 transition-all duration-200">
      {/* Cover */}
      <div
        className={`aspect-square rounded-t-xl flex items-center justify-center ${
          isMedia
            ? "bg-gradient-to-br from-neutral-800 to-neutral-900"
            : "bg-gradient-to-br from-neutral-100 to-neutral-200"
        }`}
      >
        <span className="text-3xl opacity-70">{icon}</span>
      </div>

      {/* Info */}
      <div className="p-3">
        <h3 className="text-sm font-medium text-neutral-900 truncate" title={file.name}>
          {file.name}
        </h3>
        <p className="text-xs text-neutral-500 mt-0.5">
          {file.sizeFmt} · {file.extension.toUpperCase()}
        </p>

        {/* Actions */}
        <div className="flex gap-2 mt-2">
          {isMedia && (
            <button className="flex-1 text-xs py-1.5 px-3 rounded-lg bg-orange-600 text-white font-medium hover:bg-orange-700 transition">
              ▶ Reproducir
            </button>
          )}
          {file.canDownload ? (
            <button className="flex-1 text-xs py-1.5 px-3 rounded-lg border border-neutral-200 text-neutral-700 font-medium hover:bg-neutral-50 transition">
              Descargar
            </button>
          ) : (
            <span className="flex-1 text-xs py-1.5 px-3 rounded-lg bg-neutral-100 text-neutral-400 text-center font-medium">
              Premium ✦
            </span>
          )}
        </div>
      </div>
    </div>
  );
}

// ─── Grid ───────────────────────────────────────────────────────────

interface FolderGridProps {
  folders: BrowseFolder[];
  files: BrowseFile[];
}

export function FolderGrid({ folders, files }: FolderGridProps) {
  if (folders.length === 0 && files.length === 0) {
    return (
      <div className="text-center py-20">
        <p className="text-neutral-400 text-sm">Esta carpeta está vacía</p>
      </div>
    );
  }

  return (
    <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4">
      {folders.map((folder) => (
        <FolderCard key={folder.path} folder={folder} />
      ))}
      {files.map((file) => (
        <FileCard key={file.path} file={file} />
      ))}
    </div>
  );
}

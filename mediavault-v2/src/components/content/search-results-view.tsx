"use client";

import Link from "next/link";
import { Folder, Music2, Film, FileIcon, Play, Search } from "lucide-react";
import type { SearchResult, SearchFolder } from "@/application/catalog/search-catalog";

interface SearchResultsViewProps {
  folders: SearchFolder[];
  files: SearchResult[];
  query: string;
}

export function SearchResultsView({ folders, files, query }: SearchResultsViewProps) {
  if (folders.length === 0 && files.length === 0) {
    return (
      <div className="text-center py-20">
        <div className="w-16 h-16 bg-neutral-100 rounded-full flex items-center justify-center mx-auto mb-4">
          <Search className="w-8 h-8 text-neutral-300" />
        </div>
        <p className="text-sm text-neutral-500 mb-1">
          No encontramos resultados para &quot;{query}&quot;
        </p>
        <p className="text-xs text-neutral-400">
          Verifica la ortografía o usa palabras más generales
        </p>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Folders section */}
      {folders.length > 0 && (
        <div>
          <div className="flex items-center gap-2 mb-3">
            <Folder className="w-4 h-4 text-neutral-400" />
            <h2 className="text-sm font-semibold text-neutral-700">
              Carpetas ({folders.length})
            </h2>
          </div>
          <div className="bg-white rounded-xl border border-neutral-100 overflow-hidden">
            {folders.map((folder) => (
              <Link
                key={folder.path}
                href={`/vault?path=${encodeURIComponent(folder.path)}`}
                className="flex items-center gap-3 px-4 py-3 hover:bg-neutral-50 transition border-b border-neutral-100 last:border-0"
              >
                <div className="w-10 h-10 rounded-lg bg-neutral-100 flex items-center justify-center flex-shrink-0">
                  <Folder className="w-5 h-5 text-neutral-400" />
                </div>
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-medium text-neutral-900 truncate">
                    {folder.name}
                  </p>
                  <p className="text-xs text-neutral-400 truncate">
                    {folder.fullPath}
                  </p>
                </div>
                <span className="text-xs text-neutral-400 flex-shrink-0">
                  Ver contenido →
                </span>
              </Link>
            ))}
          </div>
        </div>
      )}

      {/* Files section */}
      {files.length > 0 && (
        <div>
          <div className="flex items-center gap-2 mb-3">
            <Music2 className="w-4 h-4 text-neutral-400" />
            <h2 className="text-sm font-semibold text-neutral-700">
              Archivos ({files.length})
            </h2>
          </div>
          <div className="bg-white rounded-xl border border-neutral-100 overflow-hidden">
            {files.map((file) => {
              const Icon = file.mediaKind === "audio" ? Music2
                : file.mediaKind === "video" ? Film
                : FileIcon;
              const isMedia = file.mediaKind === "audio" || file.mediaKind === "video";
              const folderName = file.folder.replace(/\/$/, "").split("/").pop() ?? "";

              return (
                <div
                  key={file.path}
                  className="flex items-center gap-3 px-4 py-3 hover:bg-neutral-50 transition border-b border-neutral-100 last:border-0 group"
                >
                  <div className={`w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0 ${
                    isMedia ? "bg-neutral-900" : "bg-neutral-100"
                  }`}>
                    <Icon className={`w-5 h-5 ${isMedia ? "text-neutral-400" : "text-neutral-500"}`} />
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className="text-sm text-neutral-900 truncate">{file.name}</p>
                    <p className="text-xs text-neutral-400 truncate">
                      {folderName} · {file.sizeFmt} · {file.extension.toUpperCase()}
                    </p>
                  </div>
                  {isMedia && (
                    <button className="p-2 rounded-full bg-orange-500 text-white hover:bg-orange-600 transition opacity-0 group-hover:opacity-100">
                      <Play className="w-4 h-4" fill="currentColor" />
                    </button>
                  )}
                </div>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
}

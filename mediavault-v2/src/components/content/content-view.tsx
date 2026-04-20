"use client";

import { useState, useEffect } from "react";
import { FolderCard } from "./folder-card";
import { FileRow } from "./file-row";
import type { BrowseFolder, BrowseFile } from "@/application/catalog/browse-folder";

interface ContentViewProps {
  folders: BrowseFolder[];
  files: BrowseFile[];
  view: "grid" | "list";
  filter: "all" | "audio" | "video";
  depth?: number | undefined;
}

export function ContentView({
  folders,
  files,
  view,
  filter,
  depth = 0,
}: ContentViewProps) {
  // Filter files by type
  const filteredFiles = filter === "all"
    ? files
    : files.filter((f) => f.mediaKind === filter);

  const hasContent = folders.length > 0 || filteredFiles.length > 0;

  if (!hasContent) {
    return (
      <div className="flex flex-col items-center justify-center py-20 text-center">
        <div className="w-16 h-16 bg-neutral-100 rounded-full flex items-center justify-center mb-4">
          <span className="text-2xl">📂</span>
        </div>
        <p className="text-sm text-neutral-500">Esta carpeta está vacía</p>
      </div>
    );
  }

  return (
    <div>
      {/* Folders always in grid */}
      {folders.length > 0 && (
        <div className="mb-6">
          <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3">
            {folders.map((folder) => (
              <FolderCard key={folder.path} folder={folder} showDownload={depth >= 2} />
            ))}
          </div>
        </div>
      )}

      {/* Files: grid or list based on view preference */}
      {filteredFiles.length > 0 && (
        <div>
          {folders.length > 0 && filteredFiles.length > 0 && (
            <div className="border-t border-neutral-100 mb-4" />
          )}

          {view === "list" ? (
            <div className="bg-white rounded-xl border border-neutral-100 overflow-hidden">
              {filteredFiles.map((file) => (
                <FileRow
                  key={file.path}
                  file={file}
                />
              ))}
            </div>
          ) : (
            <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3">
              {filteredFiles.map((file) => (
                <FileCardCompact
                  key={file.path}
                  file={file}
                />
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  );
}

// Compact file card for grid view
import { Music2, Film, FileIcon, Play } from "lucide-react";
import { useVaultActions } from "@/hooks/useVaultActions";

function FileCardCompact({ file }: { file: BrowseFile }) {
  const { playFile } = useVaultActions();
  const isMedia = file.mediaKind === "audio" || file.mediaKind === "video";
  const Icon = file.mediaKind === "audio" ? Music2 : file.mediaKind === "video" ? Film : FileIcon;

  return (
    <div className="bg-white rounded-xl border border-neutral-100 hover:border-neutral-200 hover:shadow-md transition-all duration-200 overflow-hidden group">
      {/* Cover */}
      <div
        className={`aspect-square flex items-center justify-center relative ${
          isMedia ? "bg-gradient-to-br from-neutral-800 to-neutral-900" : "bg-gradient-to-br from-neutral-100 to-neutral-200"
        }`}
      >
        <Icon className={`w-10 h-10 ${isMedia ? "text-neutral-500" : "text-neutral-400"}`} strokeWidth={1.5} />

        {/* Play overlay on hover */}
        {isMedia && (
          <button
            onClick={(e) => { e.stopPropagation(); playFile(file.path, file.name, file.mediaKind as "audio" | "video"); }}
            className="absolute inset-0 flex items-center justify-center bg-black/40 opacity-0 group-hover:opacity-100 transition"
          >
            <div className="w-12 h-12 rounded-full bg-orange-500 flex items-center justify-center">
              <Play className="w-6 h-6 text-white" fill="white" />
            </div>
          </button>
        )}
      </div>

      {/* Info */}
      <div className="p-3">
        <h3 className="text-xs font-medium text-neutral-900 truncate leading-tight" title={file.name}>
          {file.name}
        </h3>
        <p className="text-[11px] text-neutral-400 mt-0.5">
          {file.sizeFmt} · {file.extension.toUpperCase()}
        </p>
      </div>
    </div>
  );
}

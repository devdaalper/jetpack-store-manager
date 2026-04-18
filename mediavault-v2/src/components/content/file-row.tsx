"use client";

import { Music2, Film, FileIcon, Play, Download, Lock } from "lucide-react";
import type { BrowseFile } from "@/application/catalog/browse-folder";

interface FileRowProps {
  file: BrowseFile;
  onPlay?: ((file: BrowseFile) => void) | undefined;
  onDownload?: ((file: BrowseFile) => void) | undefined;
}

export function FileRow({ file, onPlay, onDownload }: FileRowProps) {
  const Icon = file.mediaKind === "audio"
    ? Music2
    : file.mediaKind === "video"
      ? Film
      : FileIcon;

  const isMedia = file.mediaKind === "audio" || file.mediaKind === "video";
  const iconBg = isMedia ? "bg-neutral-900" : "bg-neutral-100";
  const iconColor = isMedia ? "text-neutral-300" : "text-neutral-500";

  return (
    <div className="flex items-center gap-3 px-4 py-3 hover:bg-neutral-50 transition group border-b border-neutral-100 last:border-0">
      {/* Icon */}
      <div className={`w-10 h-10 rounded-lg ${iconBg} flex items-center justify-center flex-shrink-0`}>
        <Icon className={`w-5 h-5 ${iconColor}`} />
      </div>

      {/* Info */}
      <div className="flex-1 min-w-0">
        <p className="text-sm text-neutral-900 truncate">{file.name}</p>
        <p className="text-xs text-neutral-400 mt-0.5">
          {file.extension.toUpperCase()} · {file.sizeFmt}
        </p>
      </div>

      {/* Actions */}
      <div className="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition">
        {isMedia && (
          <button
            onClick={() => onPlay?.(file)}
            className="p-2 rounded-full bg-orange-500 text-white hover:bg-orange-600 transition"
            title="Reproducir"
          >
            <Play className="w-4 h-4" fill="currentColor" />
          </button>
        )}
        <button
          onClick={() => onDownload?.(file)}
          className={`p-2 rounded-full transition ${
            file.canDownload
              ? "text-neutral-600 hover:bg-neutral-200"
              : "text-neutral-300 cursor-default"
          }`}
          title={file.canDownload ? "Descargar" : "Requiere plan"}
        >
          {file.canDownload ? (
            <Download className="w-4 h-4" />
          ) : (
            <Lock className="w-4 h-4" />
          )}
        </button>
      </div>
    </div>
  );
}

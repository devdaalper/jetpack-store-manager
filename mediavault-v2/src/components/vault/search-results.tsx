"use client";

import type { SearchResult } from "@/application/catalog/search-catalog";
import type { TierValue } from "@/domain/schemas";
import Link from "next/link";

interface SearchResultsProps {
  items: SearchResult[];
  userTier: TierValue;
}

export function SearchResults({ items, userTier }: SearchResultsProps) {
  if (items.length === 0) {
    return (
      <div className="text-center py-20">
        <p className="text-neutral-400 text-sm">
          No se encontraron archivos. Intenta con otros términos.
        </p>
      </div>
    );
  }

  return (
    <div className="space-y-2">
      {items.map((item) => {
        const isMedia = item.mediaKind === "audio" || item.mediaKind === "video";
        const icon = item.mediaKind === "audio" ? "🎵" : item.mediaKind === "video" ? "🎬" : "📄";
        const folderName = item.folder.replace(/\/$/, "").split("/").pop() ?? "";

        return (
          <div
            key={item.path}
            className="flex items-center gap-3 p-3 bg-white rounded-lg border border-neutral-100 hover:border-neutral-200 hover:shadow-sm transition"
          >
            {/* Icon */}
            <div
              className={`w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0 ${
                isMedia
                  ? "bg-gradient-to-br from-neutral-800 to-neutral-900"
                  : "bg-neutral-100"
              }`}
            >
              <span className="text-base">{icon}</span>
            </div>

            {/* Info */}
            <div className="flex-1 min-w-0">
              <p className="text-sm font-medium text-neutral-900 truncate">
                {item.name}
              </p>
              <p className="text-xs text-neutral-500 truncate">
                <Link
                  href={`/vault?path=${encodeURIComponent(item.folder)}`}
                  className="hover:text-orange-600 transition"
                >
                  {folderName}
                </Link>
                {" · "}
                {item.sizeFmt}
                {" · "}
                {item.extension.toUpperCase()}
              </p>
            </div>

            {/* Actions */}
            <div className="flex gap-2 flex-shrink-0">
              {isMedia && (
                <button className="text-xs py-1.5 px-3 rounded-lg bg-orange-600 text-white font-medium hover:bg-orange-700 transition">
                  ▶
                </button>
              )}
              {userTier > 0 ? (
                <button className="text-xs py-1.5 px-3 rounded-lg border border-neutral-200 text-neutral-600 font-medium hover:bg-neutral-50 transition">
                  Descargar
                </button>
              ) : (
                <span className="text-xs py-1.5 px-3 rounded-lg bg-neutral-100 text-neutral-400 font-medium">
                  Premium ✦
                </span>
              )}
            </div>
          </div>
        );
      })}
    </div>
  );
}

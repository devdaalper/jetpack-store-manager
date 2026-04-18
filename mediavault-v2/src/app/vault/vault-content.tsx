"use client";

import { useState, useEffect } from "react";
import { Toolbar } from "@/components/layout/toolbar";
import { Breadcrumbs } from "@/components/vault/breadcrumbs";
import { ContentView } from "@/components/content/content-view";
import type { BrowseFolder, BrowseFile } from "@/application/catalog/browse-folder";

interface VaultContentProps {
  folders: BrowseFolder[];
  files: BrowseFile[];
  breadcrumbs: Array<{ name: string; path: string }>;
  currentFolder: string;
  currentName: string;
  totalFiles: number;
  folderCount: number;
}

export function VaultContent({
  folders,
  files,
  breadcrumbs,
  currentFolder,
  currentName,
  totalFiles,
  folderCount,
}: VaultContentProps) {
  const [view, setView] = useState<"grid" | "list">("grid");
  const [filter, setFilter] = useState<"all" | "audio" | "video">("all");

  // Load view preference from localStorage
  useEffect(() => {
    const saved = localStorage.getItem("mv_view_preference");
    if (saved === "list" || saved === "grid") setView(saved);
  }, []);

  function handleViewChange(v: "grid" | "list") {
    setView(v);
    localStorage.setItem("mv_view_preference", v);
  }

  // Auto-select best view: grid if mostly folders, list if mostly files
  useEffect(() => {
    const saved = localStorage.getItem("mv_view_preference");
    if (!saved) {
      // Auto: if only files, default to list
      if (folders.length === 0 && files.length > 0) {
        setView("list");
      } else {
        setView("grid");
      }
    }
  }, [folders.length, files.length]);

  // Summary text
  const parts: string[] = [];
  if (folderCount > 0) parts.push(`${folderCount} carpetas`);
  if (totalFiles > 0) parts.push(`${totalFiles} archivos`);
  const summary = parts.join(" · ") || "Carpeta vacía";

  return (
    <>
      {/* Toolbar */}
      <Toolbar
        currentPath={currentFolder}
        view={view}
        onViewChange={handleViewChange}
        filter={filter}
        onFilterChange={setFilter}
      />

      {/* Content area */}
      <div className="p-4 md:p-6 max-w-7xl">
        {/* Breadcrumbs */}
        {breadcrumbs.length > 1 && <Breadcrumbs items={breadcrumbs} />}

        {/* Header */}
        <div className="mb-5">
          <h1 className="text-2xl font-bold text-neutral-900 tracking-tight">
            {currentName}
          </h1>
          <p className="text-sm text-neutral-400 mt-1">{summary}</p>
        </div>

        {/* Content */}
        <ContentView
          folders={folders}
          files={files}
          view={view}
          filter={filter}
        />
      </div>
    </>
  );
}

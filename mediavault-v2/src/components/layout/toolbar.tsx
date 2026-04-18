"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import {
  ChevronLeft,
  ChevronRight,
  Home,
  Search,
  LayoutGrid,
  List,
  SlidersHorizontal,
} from "lucide-react";

interface ToolbarProps {
  currentPath: string;
  onViewChange?: (view: "grid" | "list") => void;
  view?: "grid" | "list";
  onFilterChange?: (filter: "all" | "audio" | "video") => void;
  filter?: "all" | "audio" | "video";
}

export function Toolbar({
  currentPath,
  onViewChange,
  view = "grid",
  onFilterChange,
  filter = "all",
}: ToolbarProps) {
  const router = useRouter();
  const [searchQuery, setSearchQuery] = useState("");
  const [searchFocused, setSearchFocused] = useState(false);

  function handleSearch(e: React.FormEvent) {
    e.preventDefault();
    if (searchQuery.trim()) {
      router.push(`/vault/search?q=${encodeURIComponent(searchQuery.trim())}`);
    }
  }

  return (
    <div className="flex items-center gap-2 px-4 py-2 border-b border-neutral-200 bg-white sticky top-0 z-20">
      {/* Navigation */}
      <div className="flex items-center gap-1">
        <button
          onClick={() => router.back()}
          className="p-1.5 rounded-lg text-neutral-400 hover:text-neutral-700 hover:bg-neutral-100 transition"
          title="Atr\u00e1s"
        >
          <ChevronLeft className="w-5 h-5" />
        </button>
        <button
          onClick={() => router.forward()}
          className="p-1.5 rounded-lg text-neutral-400 hover:text-neutral-700 hover:bg-neutral-100 transition"
          title="Adelante"
        >
          <ChevronRight className="w-5 h-5" />
        </button>
        <button
          onClick={() => router.push("/vault")}
          className="p-1.5 rounded-lg text-neutral-400 hover:text-neutral-700 hover:bg-neutral-100 transition"
          title="Inicio"
        >
          <Home className="w-5 h-5" />
        </button>
      </div>

      {/* Search */}
      <form onSubmit={handleSearch} className="flex-1 max-w-md mx-auto">
        <div className="relative">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-neutral-400" />
          <input
            type="search"
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            onFocus={() => setSearchFocused(true)}
            onBlur={() => setSearchFocused(false)}
            placeholder="Buscar..."
            className={`w-full pl-10 pr-4 py-2 text-sm rounded-full border transition-all outline-none ${
              searchFocused
                ? "border-orange-400 ring-2 ring-orange-100 bg-white"
                : "border-neutral-200 bg-neutral-50 hover:bg-white"
            }`}
          />
        </div>
      </form>

      {/* Filters */}
      <div className="hidden md:flex items-center gap-1">
        {(["all", "audio", "video"] as const).map((f) => (
          <button
            key={f}
            onClick={() => onFilterChange?.(f)}
            className={`px-3 py-1.5 rounded-full text-xs font-medium transition ${
              filter === f
                ? "bg-neutral-900 text-white"
                : "text-neutral-500 hover:bg-neutral-100"
            }`}
          >
            {f === "all" ? "Todo" : f === "audio" ? "Audio" : "Video"}
          </button>
        ))}
      </div>

      {/* View toggle */}
      <div className="hidden md:flex items-center border border-neutral-200 rounded-lg overflow-hidden">
        <button
          onClick={() => onViewChange?.("grid")}
          className={`p-1.5 transition ${
            view === "grid"
              ? "bg-neutral-900 text-white"
              : "text-neutral-400 hover:text-neutral-700"
          }`}
          title="Vista cuadr\u00edcula"
        >
          <LayoutGrid className="w-4 h-4" />
        </button>
        <button
          onClick={() => onViewChange?.("list")}
          className={`p-1.5 transition ${
            view === "list"
              ? "bg-neutral-900 text-white"
              : "text-neutral-400 hover:text-neutral-700"
          }`}
          title="Vista lista"
        >
          <List className="w-4 h-4" />
        </button>
      </div>
    </div>
  );
}

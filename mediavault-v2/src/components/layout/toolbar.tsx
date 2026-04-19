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
} from "lucide-react";

interface ToolbarProps {
  currentPath: string;
  onViewChange?: ((view: "grid" | "list") => void) | undefined;
  view?: "grid" | "list";
  onFilterChange?: ((filter: "all" | "audio" | "video") => void) | undefined;
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
    <div className="border-b border-neutral-200 bg-white sticky top-0 z-20">
      {/* Top row: nav + search + view toggle */}
      <div className="flex items-center gap-1.5 px-3 md:px-4 py-2">
        {/* Navigation — larger touch targets on mobile */}
        <div className="flex items-center gap-0.5 flex-shrink-0">
          <button
            onClick={() => router.back()}
            className="p-2.5 rounded-lg text-neutral-400 hover:text-neutral-700 hover:bg-neutral-100 active:bg-neutral-200 transition"
            title="Atrás"
          >
            <ChevronLeft className="w-5 h-5" />
          </button>
          <button
            onClick={() => router.forward()}
            className="hidden sm:block p-2.5 rounded-lg text-neutral-400 hover:text-neutral-700 hover:bg-neutral-100 active:bg-neutral-200 transition"
            title="Adelante"
          >
            <ChevronRight className="w-5 h-5" />
          </button>
          <button
            onClick={() => router.push("/vault")}
            className="p-2.5 rounded-lg text-neutral-400 hover:text-neutral-700 hover:bg-neutral-100 active:bg-neutral-200 transition"
            title="Inicio"
          >
            <Home className="w-5 h-5" />
          </button>
        </div>

        {/* Search */}
        <form onSubmit={handleSearch} className="flex-1 min-w-0">
          <div className="relative">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-neutral-400" />
            <input
              type="search"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              onFocus={() => setSearchFocused(true)}
              onBlur={() => setSearchFocused(false)}
              placeholder="Buscar..."
              autoComplete="off"
              className={`w-full pl-10 pr-4 py-2 text-sm rounded-full border transition-all outline-none ${
                searchFocused
                  ? "border-orange-400 ring-2 ring-orange-100 bg-white"
                  : "border-neutral-200 bg-neutral-50 hover:bg-white"
              }`}
            />
          </div>
        </form>

        {/* View toggle — always visible */}
        <div className="flex items-center border border-neutral-200 rounded-lg overflow-hidden flex-shrink-0">
          <button
            onClick={() => onViewChange?.("grid")}
            className={`p-2 transition ${
              view === "grid"
                ? "bg-neutral-900 text-white"
                : "text-neutral-400 hover:text-neutral-700 active:bg-neutral-100"
            }`}
            title="Cuadrícula"
          >
            <LayoutGrid className="w-4 h-4" />
          </button>
          <button
            onClick={() => onViewChange?.("list")}
            className={`p-2 transition ${
              view === "list"
                ? "bg-neutral-900 text-white"
                : "text-neutral-400 hover:text-neutral-700 active:bg-neutral-100"
            }`}
            title="Lista"
          >
            <List className="w-4 h-4" />
          </button>
        </div>
      </div>

      {/* Bottom row: filter pills — scrollable on mobile */}
      <div className="flex items-center gap-1.5 px-3 md:px-4 pb-2 overflow-x-auto scrollbar-none">
        {(["all", "audio", "video"] as const).map((f) => (
          <button
            key={f}
            onClick={() => onFilterChange?.(f)}
            className={`px-3 py-1.5 rounded-full text-xs font-medium transition whitespace-nowrap ${
              filter === f
                ? "bg-neutral-900 text-white"
                : "text-neutral-500 bg-neutral-100 hover:bg-neutral-200 active:bg-neutral-300"
            }`}
          >
            {f === "all" ? "Todo" : f === "audio" ? "Audio" : "Video"}
          </button>
        ))}
      </div>
    </div>
  );
}

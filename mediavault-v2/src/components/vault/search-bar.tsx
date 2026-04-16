"use client";

import { useState, useCallback, useRef } from "react";
import { useRouter } from "next/navigation";

export function SearchBar() {
  const [query, setQuery] = useState("");
  const [isSearching, setIsSearching] = useState(false);
  const router = useRouter();
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  const handleSearch = useCallback(
    (value: string) => {
      setQuery(value);

      if (debounceRef.current) clearTimeout(debounceRef.current);

      if (!value.trim()) {
        setIsSearching(false);
        return;
      }

      setIsSearching(true);
      debounceRef.current = setTimeout(() => {
        router.push(`/vault/search?q=${encodeURIComponent(value.trim())}`);
        setIsSearching(false);
      }, 500);
    },
    [router],
  );

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (debounceRef.current) clearTimeout(debounceRef.current);

    if (query.trim()) {
      router.push(`/vault/search?q=${encodeURIComponent(query.trim())}`);
    }
  };

  return (
    <form onSubmit={handleSubmit} className="relative w-full max-w-sm">
      {/* Search icon */}
      <svg
        className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-neutral-400"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
      >
        <path
          strokeLinecap="round"
          strokeLinejoin="round"
          strokeWidth={2}
          d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
        />
      </svg>

      <input
        type="search"
        value={query}
        onChange={(e) => handleSearch(e.target.value)}
        placeholder="Buscar archivos..."
        className="w-full pl-10 pr-4 py-2 text-sm rounded-lg border border-neutral-200 bg-white text-neutral-900 placeholder:text-neutral-400 focus:border-orange-500 focus:ring-2 focus:ring-orange-500/20 outline-none transition"
      />

      {isSearching && (
        <div className="absolute right-3 top-1/2 -translate-y-1/2">
          <div className="w-4 h-4 border-2 border-neutral-200 border-t-orange-500 rounded-full animate-spin" />
        </div>
      )}
    </form>
  );
}

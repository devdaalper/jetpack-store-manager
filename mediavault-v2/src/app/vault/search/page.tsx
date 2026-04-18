/**
 * Search Results Page.
 */

import { redirect } from "next/navigation";
import { getSessionUser } from "@/lib/auth";
import { searchCatalog } from "@/application/catalog/search-catalog";
import { SearchResultsView } from "@/components/content/search-results-view";
import Link from "next/link";
import { ChevronLeft } from "lucide-react";

interface SearchPageProps {
  searchParams: Promise<{ q?: string; type?: string }>;
}

export default async function SearchPage({ searchParams }: SearchPageProps) {
  const user = await getSessionUser();
  if (!user) redirect("/login?redirect=/vault");

  const params = await searchParams;
  const query = params.q ?? "";
  const type = params.type as "audio" | "video" | undefined;

  if (!query) redirect("/vault");

  const results = await searchCatalog(query, type, 100, 0);

  return (
    <div className="p-4 md:p-6 max-w-7xl">
      {/* Back */}
      <Link
        href="/vault"
        className="inline-flex items-center gap-1 text-sm text-neutral-400 hover:text-orange-600 transition mb-4"
      >
        <ChevronLeft className="w-4 h-4" />
        Volver
      </Link>

      {/* Header */}
      <div className="mb-5">
        <h1 className="text-2xl font-bold text-neutral-900 tracking-tight">
          Resultados para &quot;{query}&quot;
        </h1>
        <p className="text-sm text-neutral-400 mt-1">
          {results.folders.length > 0 && `${results.folders.length} carpetas · `}
          {results.total} {results.total === 1 ? "archivo" : "archivos"}
        </p>
      </div>

      {/* Filters */}
      <div className="flex gap-2 mb-5">
        {(["all", "audio", "video"] as const).map((t) => {
          const isActive = (t === "all" && !type) || t === type;
          const href = t === "all"
            ? `/vault/search?q=${encodeURIComponent(query)}`
            : `/vault/search?q=${encodeURIComponent(query)}&type=${t}`;
          return (
            <Link
              key={t}
              href={href}
              className={`px-3 py-1.5 rounded-full text-xs font-medium transition ${
                isActive
                  ? "bg-neutral-900 text-white"
                  : "text-neutral-500 hover:bg-neutral-100"
              }`}
            >
              {t === "all" ? "Todo" : t === "audio" ? "Audio" : "Video"}
            </Link>
          );
        })}
      </div>

      {/* Results */}
      <SearchResultsView
        folders={results.folders}
        files={results.items}
        query={query}
      />
    </div>
  );
}

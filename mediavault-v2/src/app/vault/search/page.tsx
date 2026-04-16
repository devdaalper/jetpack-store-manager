/**
 * Search Results Page — Server Component.
 *
 * Fetches search results based on query params and renders them.
 */

import { redirect } from "next/navigation";
import { getSessionUser } from "@/lib/auth";
import { searchCatalog } from "@/application/catalog/search-catalog";
import { SearchResults } from "@/components/vault/search-results";
import Link from "next/link";

interface SearchPageProps {
  searchParams: Promise<{ q?: string; type?: string }>;
}

export default async function SearchPage({ searchParams }: SearchPageProps) {
  const user = await getSessionUser();
  if (!user) redirect("/login?redirect=/vault");

  const params = await searchParams;
  const query = params.q ?? "";
  const type = params.type as "audio" | "video" | undefined;

  if (!query) {
    redirect("/vault");
  }

  const results = await searchCatalog(query, type, 100, 0);

  return (
    <div className="p-6 md:p-8 max-w-7xl">
      {/* Back link */}
      <Link
        href="/vault"
        className="inline-flex items-center gap-1.5 text-sm text-neutral-500 hover:text-orange-600 transition mb-4"
      >
        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
        </svg>
        Volver a la biblioteca
      </Link>

      {/* Header */}
      <div className="mb-6">
        <h2 className="text-2xl font-bold text-neutral-900 tracking-tight">
          Resultados para &quot;{query}&quot;
        </h2>
        <p className="text-sm text-neutral-500 mt-1">
          {results.total} {results.total === 1 ? "resultado" : "resultados"}
        </p>
      </div>

      {/* Type filters */}
      <div className="flex gap-2 mb-6">
        {(["all", "audio", "video"] as const).map((t) => {
          const isActive = (t === "all" && !type) || t === type;
          const href =
            t === "all"
              ? `/vault/search?q=${encodeURIComponent(query)}`
              : `/vault/search?q=${encodeURIComponent(query)}&type=${t}`;

          return (
            <Link
              key={t}
              href={href}
              className={`px-4 py-1.5 rounded-full text-sm font-medium transition
                ${isActive
                  ? "bg-neutral-900 text-white"
                  : "bg-white border border-neutral-200 text-neutral-600 hover:border-neutral-300"
                }`}
            >
              {t === "all" ? "Todos" : t === "audio" ? "Audio" : "Video"}
            </Link>
          );
        })}
      </div>

      {/* Results */}
      <SearchResults items={results.items} userTier={user.tier} />
    </div>
  );
}

import Link from "next/link";

interface BreadcrumbsProps {
  items: Array<{ name: string; path: string }>;
}

export function Breadcrumbs({ items }: BreadcrumbsProps) {
  if (items.length <= 1) return null;

  return (
    <nav className="flex items-center gap-1 text-sm mb-3 overflow-x-auto scrollbar-none pb-1">
      {items.map((item, index) => {
        const isLast = index === items.length - 1;

        return (
          <span key={item.path} className="flex items-center gap-1 whitespace-nowrap flex-shrink-0">
            {index > 0 && (
              <svg className="w-3.5 h-3.5 text-neutral-300 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
              </svg>
            )}
            {isLast ? (
              <span className="text-neutral-900 font-semibold max-w-[200px] sm:max-w-none truncate">
                {item.name}
              </span>
            ) : (
              <Link
                href={`/vault?path=${encodeURIComponent(item.path)}`}
                className="text-neutral-500 hover:text-orange-600 active:text-orange-700 transition py-1 max-w-[120px] sm:max-w-[200px] md:max-w-none truncate"
              >
                {item.name}
              </Link>
            )}
          </span>
        );
      })}
    </nav>
  );
}

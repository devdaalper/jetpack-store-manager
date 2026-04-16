/**
 * Reusable loading indicators.
 */

export function Spinner({ size = "md" }: { size?: "sm" | "md" | "lg" }) {
  const sizeClasses = {
    sm: "w-4 h-4 border-[2px]",
    md: "w-8 h-8 border-2",
    lg: "w-12 h-12 border-[3px]",
  };

  return (
    <div
      className={`${sizeClasses[size]} border-neutral-200 border-t-orange-600 rounded-full animate-spin`}
    />
  );
}

export function PageLoader() {
  return (
    <div className="flex items-center justify-center min-h-[400px]">
      <Spinner size="lg" />
    </div>
  );
}

export function InlineLoader({ text = "Cargando..." }: { text?: string }) {
  return (
    <div className="flex items-center gap-2 text-sm text-neutral-500">
      <Spinner size="sm" />
      <span>{text}</span>
    </div>
  );
}

/**
 * Skeleton placeholder for content loading.
 */
export function Skeleton({
  className = "",
  width,
  height,
}: {
  className?: string;
  width?: string;
  height?: string;
}) {
  return (
    <div
      className={`animate-pulse bg-neutral-200 rounded-lg ${className}`}
      style={{ width, height }}
    />
  );
}

export function CardSkeleton() {
  return (
    <div className="bg-white rounded-xl shadow-sm border border-neutral-100 overflow-hidden">
      <Skeleton className="aspect-square rounded-none" />
      <div className="p-3 space-y-2">
        <Skeleton height="14px" width="70%" />
        <Skeleton height="12px" width="40%" />
      </div>
    </div>
  );
}

export function GridSkeleton({ count = 12 }: { count?: number }) {
  return (
    <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4">
      {Array.from({ length: count }, (_, i) => (
        <CardSkeleton key={i} />
      ))}
    </div>
  );
}

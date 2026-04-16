import { GridSkeleton } from "@/components/shared/loading";

export default function VaultLoading() {
  return (
    <div className="p-6 md:p-8 max-w-7xl">
      <div className="animate-pulse mb-6">
        <div className="h-7 bg-neutral-200 rounded w-48 mb-2" />
        <div className="h-4 bg-neutral-100 rounded w-32" />
      </div>
      <GridSkeleton count={12} />
    </div>
  );
}

"use client";

import Link from "next/link";
import { usePathname, useSearchParams } from "next/navigation";

interface SidebarProps {
  folders: Array<{ name: string; path: string }>;
  userEmail: string;
}

export function Sidebar({ folders, userEmail }: SidebarProps) {
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const currentFolder = searchParams.get("path") ?? "";

  return (
    <nav className="flex-1 overflow-y-auto py-2">
      {/* Home */}
      <Link
        href="/vault"
        className={`flex items-center gap-2 mx-2 px-3 py-2 rounded-lg text-sm transition
          ${!currentFolder && pathname === "/vault"
            ? "bg-orange-50 text-orange-700 font-semibold"
            : "text-neutral-600 hover:bg-neutral-100"
          }`}
      >
        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
        </svg>
        Inicio
      </Link>

      {/* Divider */}
      <div className="mx-4 my-2 border-t border-neutral-100" />

      {/* Folders */}
      {folders.map((folder) => {
        const isActive = currentFolder.startsWith(folder.path);
        return (
          <Link
            key={folder.path}
            href={`/vault?path=${encodeURIComponent(folder.path)}`}
            className={`flex items-center gap-2 mx-2 px-3 py-2 rounded-lg text-sm transition
              ${isActive
                ? "bg-orange-50 text-orange-700 font-semibold"
                : "text-neutral-600 hover:bg-neutral-100"
              }`}
          >
            <svg className="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
            </svg>
            <span className="truncate">{folder.name}</span>
          </Link>
        );
      })}

      {folders.length === 0 && (
        <p className="px-5 py-3 text-xs text-neutral-400">
          Sin carpetas indexadas.
          {userEmail && " Ejecuta un sync desde el panel admin."}
        </p>
      )}
    </nav>
  );
}

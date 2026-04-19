"use client";

import Link from "next/link";
import { usePathname, useSearchParams } from "next/navigation";
import { useState, Suspense } from "react";
import {
  Home,
  Folder,
  PanelLeftClose,
  PanelLeft,
  LogOut,
  Music2,
} from "lucide-react";
import { TIER_NAMES } from "@/domain/types";
import type { TierValue } from "@/domain/schemas";

interface SidebarFolder {
  name: string;
  path: string;
}

interface SidebarProps {
  folders: SidebarFolder[];
  userEmail: string;
  userTier: TierValue;
  isCollapsed: boolean;
  onToggleCollapse: () => void;
  isMobileDrawer?: boolean | undefined;
}

export function Sidebar({
  folders,
  userEmail,
  userTier,
  isCollapsed,
  onToggleCollapse,
  isMobileDrawer = false,
}: SidebarProps) {
  return (
    <aside
      className={`${isMobileDrawer ? "flex" : "hidden md:flex"} flex-col border-r border-neutral-200 bg-white transition-all duration-200 ${
        isCollapsed ? "w-14" : "w-60"
      }`}
    >
      {/* Header */}
      <div className="flex items-center justify-between p-3 border-b border-neutral-100">
        {!isCollapsed && (
          <div className="flex items-center gap-2">
            <Music2 className="w-5 h-5 text-orange-500" />
            <span className="text-sm font-bold text-neutral-900 tracking-tight">
              MediaVault
            </span>
          </div>
        )}
        <button
          onClick={onToggleCollapse}
          className="p-1.5 rounded-lg text-neutral-400 hover:text-neutral-700 hover:bg-neutral-100 transition"
          title={isCollapsed ? "Expandir" : "Colapsar"}
        >
          {isCollapsed ? (
            <PanelLeft className="w-4 h-4" />
          ) : (
            <PanelLeftClose className="w-4 h-4" />
          )}
        </button>
      </div>

      {/* Navigation */}
      <nav className="flex-1 overflow-y-auto py-2">
        <Suspense>
          <SidebarNav folders={folders} isCollapsed={isCollapsed} />
        </Suspense>
      </nav>

      {/* User footer */}
      <div className="border-t border-neutral-100 p-3">
        {isCollapsed ? (
          <div className="flex flex-col items-center gap-2">
            <div className="w-8 h-8 rounded-full bg-neutral-100 flex items-center justify-center text-xs font-medium text-neutral-600">
              {userEmail.charAt(0).toUpperCase()}
            </div>
          </div>
        ) : (
          <div>
            <p className="text-xs text-neutral-900 truncate">{userEmail}</p>
            <p className="text-[11px] text-neutral-400 mt-0.5">
              {TIER_NAMES[userTier] ?? `Tier ${userTier}`}
            </p>
            <form action="/auth/logout" method="POST">
              <button
                type="button"
                onClick={() => {
                  // Client-side logout: clear session and redirect
                  document.cookie = "sb-access-token=; max-age=0; path=/";
                  document.cookie = "sb-refresh-token=; max-age=0; path=/";
                  window.location.href = "/login";
                }}
                className="flex items-center gap-1.5 mt-2 text-[11px] text-neutral-400 hover:text-red-500 transition"
              >
                <LogOut className="w-3 h-3" />
                Cerrar sesión
              </button>
            </form>
          </div>
        )}
      </div>
    </aside>
  );
}

// Separate client component for search params access
function SidebarNav({
  folders,
  isCollapsed,
}: {
  folders: SidebarFolder[];
  isCollapsed: boolean;
}) {
  const searchParams = useSearchParams();
  const currentFolder = searchParams.get("path") ?? "";

  return (
    <>
      {/* Home */}
      <Link
        href="/vault"
        className={`flex items-center gap-2 mx-2 px-2 py-3 rounded-lg text-sm transition ${
          !currentFolder
            ? "bg-orange-50 text-orange-700 font-medium"
            : "text-neutral-600 hover:bg-neutral-100"
        } ${isCollapsed ? "justify-center" : ""}`}
        title="Inicio"
      >
        <Home className="w-4 h-4 flex-shrink-0" />
        {!isCollapsed && <span>Inicio</span>}
      </Link>

      {/* Divider */}
      <div className="mx-3 my-2 border-t border-neutral-100" />

      {/* Folders */}
      {folders.map((folder) => {
        const isActive = currentFolder.startsWith(folder.path);
        return (
          <Link
            key={folder.path}
            href={`/vault?path=${encodeURIComponent(folder.path)}`}
            className={`flex items-center gap-2 mx-2 px-2 py-3 rounded-lg text-sm transition ${
              isActive
                ? "bg-orange-50 text-orange-700 font-medium"
                : "text-neutral-600 hover:bg-neutral-100"
            } ${isCollapsed ? "justify-center" : ""}`}
            title={folder.name}
          >
            <Folder className="w-4 h-4 flex-shrink-0" />
            {!isCollapsed && (
              <span className="truncate">{folder.name}</span>
            )}
          </Link>
        );
      })}
    </>
  );
}

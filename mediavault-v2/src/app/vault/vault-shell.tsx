"use client";

import { useState, useCallback, useEffect } from "react";
import { Sidebar } from "@/components/layout/sidebar";
import { PlayerBar, type PlayerTrack } from "@/components/layout/player-bar";
import { UpgradeSheet } from "@/components/access/upgrade-sheet";
import { Menu, X } from "lucide-react";
import type { TierValue } from "@/domain/schemas";

interface VaultShellProps {
  folders: Array<{ name: string; path: string }>;
  userEmail: string;
  userTier: TierValue;
  whatsappNumber?: string | undefined;
  children: React.ReactNode;
}

export function VaultShell({
  folders,
  userEmail,
  userTier,
  whatsappNumber,
  children,
}: VaultShellProps) {
  // Sidebar state
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

  // Player state
  const [currentTrack, setCurrentTrack] = useState<PlayerTrack | null>(null);
  const [remainingPlays, setRemainingPlays] = useState(-1);

  // Upgrade sheet state
  const [upgradeOpen, setUpgradeOpen] = useState(false);
  const [upgradeReason, setUpgradeReason] = useState<"download" | "play_limit" | "preview_limit">("download");

  // Persist sidebar preference
  useEffect(() => {
    const saved = localStorage.getItem("mv_sidebar_collapsed");
    if (saved === "true") setSidebarCollapsed(true);
  }, []);

  // Scroll lock when mobile menu is open
  useEffect(() => {
    if (mobileMenuOpen) {
      document.body.style.overflow = "hidden";
    } else {
      document.body.style.overflow = "";
    }
    return () => { document.body.style.overflow = ""; };
  }, [mobileMenuOpen]);

  const toggleSidebar = useCallback(() => {
    setSidebarCollapsed((prev) => {
      localStorage.setItem("mv_sidebar_collapsed", String(!prev));
      return !prev;
    });
  }, []);

  // Play file handler (passed down via context or props)
  const handlePlayFile = useCallback(async (path: string, name: string, mediaType: "audio" | "video") => {
    try {
      const res = await fetch("/api/media/presigned-url", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ path, intent: "preview" }),
      });
      const data = await res.json();

      if (!data.ok) {
        if (data.error?.code === "PLAY_LIMIT_REACHED") {
          setUpgradeReason("play_limit");
          setUpgradeOpen(true);
          return;
        }
        return;
      }

      // Track the play
      await fetch("/api/media/play", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ path }),
      });

      setCurrentTrack({ url: data.data.url, name, path, mediaType });
      setRemainingPlays(data.data.remainingPlays);
    } catch {
      // Silent fail
    }
  }, []);

  // Download handler
  const handleDownloadFile = useCallback(async (path: string, name: string) => {
    if (userTier === 0) {
      setUpgradeReason("download");
      setUpgradeOpen(true);
      return;
    }

    try {
      const res = await fetch("/api/media/presigned-url", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ path, intent: "download" }),
      });
      const data = await res.json();

      if (!data.ok) {
        if (data.error?.code === "TIER_INSUFFICIENT") {
          setUpgradeReason("download");
          setUpgradeOpen(true);
        }
        return;
      }

      // Trigger download
      const a = document.createElement("a");
      a.href = data.data.url;
      a.download = name;
      a.style.display = "none";
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
    } catch {
      // Silent fail
    }
  }, [userTier]);

  return (
    <div className="flex min-h-screen bg-neutral-50">
      {/* Mobile hamburger */}
      <button
        onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
        className="md:hidden fixed top-2 left-2 z-50 p-2.5 bg-white rounded-xl shadow-md border border-neutral-200"
      >
        {mobileMenuOpen ? <X className="w-5 h-5" /> : <Menu className="w-5 h-5" />}
      </button>

      {/* Mobile sidebar overlay */}
      {mobileMenuOpen && (
        <div className="md:hidden fixed inset-0 z-40">
          <div className="absolute inset-0 bg-black/30" onClick={() => setMobileMenuOpen(false)} />
          <div className="relative w-60 h-full">
            <Sidebar
              folders={folders}
              userEmail={userEmail}
              userTier={userTier}
              isCollapsed={false}
              onToggleCollapse={() => setMobileMenuOpen(false)}
            />
          </div>
        </div>
      )}

      {/* Desktop sidebar */}
      <Sidebar
        folders={folders}
        userEmail={userEmail}
        userTier={userTier}
        isCollapsed={sidebarCollapsed}
        onToggleCollapse={toggleSidebar}
      />

      {/* Main content */}
      <div className="flex-1 min-w-0 flex flex-col pb-16">
        {children}
      </div>

      {/* Player bar */}
      <PlayerBar
        track={currentTrack}
        remainingPlays={remainingPlays}
        onClose={() => setCurrentTrack(null)}
        whatsappNumber={whatsappNumber}
      />

      {/* Upgrade sheet */}
      <UpgradeSheet
        isOpen={upgradeOpen}
        onClose={() => setUpgradeOpen(false)}
        whatsappNumber={whatsappNumber}
        reason={upgradeReason}
      />
    </div>
  );
}

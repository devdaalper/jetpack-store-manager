"use client";

import { createContext, useContext, type ReactNode } from "react";

interface VaultActions {
  playFile: (path: string, name: string, mediaType: "audio" | "video") => void;
  downloadFile: (path: string, name: string) => void;
}

const VaultActionsContext = createContext<VaultActions | null>(null);

export function VaultActionsProvider({
  children,
  playFile,
  downloadFile,
}: {
  children: ReactNode;
  playFile: (path: string, name: string, mediaType: "audio" | "video") => void;
  downloadFile: (path: string, name: string) => void;
}) {
  return (
    <VaultActionsContext.Provider value={{ playFile, downloadFile }}>
      {children}
    </VaultActionsContext.Provider>
  );
}

export function useVaultActions(): VaultActions {
  const ctx = useContext(VaultActionsContext);
  if (!ctx) {
    // Fallback: no-op handlers (prevents crashes if used outside provider)
    return {
      playFile: () => {},
      downloadFile: () => {},
    };
  }
  return ctx;
}

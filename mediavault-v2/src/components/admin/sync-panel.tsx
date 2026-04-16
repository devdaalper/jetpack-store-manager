"use client";

import { useState, useCallback } from "react";

interface BatchResult {
  scanned: number;
  inserted: number;
  status: "in_progress" | "completed";
  nextToken?: string;
}

export function SyncPanel() {
  const [syncing, setSyncing] = useState(false);
  const [progress, setProgress] = useState<{
    totalScanned: number;
    totalInserted: number;
    batches: number;
    status: "idle" | "running" | "completed" | "error";
    error?: string;
  }>({ totalScanned: 0, totalInserted: 0, batches: 0, status: "idle" });

  const runSync = useCallback(async () => {
    setSyncing(true);
    setProgress({ totalScanned: 0, totalInserted: 0, batches: 0, status: "running" });

    let continuationToken: string | undefined;
    let totalScanned = 0;
    let totalInserted = 0;
    let batches = 0;

    try {
      // Loop: call sync API in batches until completed
      do {
        const res = await fetch("/api/admin/sync", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ continuationToken }),
        });

        const data = await res.json();

        if (!data.ok) {
          setProgress((p) => ({
            ...p,
            status: "error",
            error: data.error?.message ?? "Sync failed",
          }));
          break;
        }

        const batch = data.data as BatchResult;
        totalScanned += batch.scanned;
        totalInserted += batch.inserted;
        batches += 1;

        setProgress({
          totalScanned,
          totalInserted,
          batches,
          status: batch.status === "completed" ? "completed" : "running",
        });

        continuationToken = batch.nextToken;
      } while (continuationToken);
    } catch (err) {
      setProgress((p) => ({
        ...p,
        status: "error",
        error: (err as Error).message,
      }));
    } finally {
      setSyncing(false);
    }
  }, []);

  return (
    <div className="bg-white rounded-xl border border-neutral-200 p-6">
      <div className="flex items-center justify-between mb-4">
        <h3 className="text-sm font-semibold text-neutral-900">
          Sincronización B2 → Index
        </h3>
        <button
          onClick={runSync}
          disabled={syncing}
          className="px-4 py-2 text-sm font-medium bg-orange-600 text-white rounded-lg hover:bg-orange-700 disabled:opacity-50 transition"
        >
          {syncing ? "Sincronizando..." : "Iniciar Sync"}
        </button>
      </div>

      {progress.status !== "idle" && (
        <div className="space-y-2">
          {/* Progress bar */}
          {progress.status === "running" && (
            <div className="w-full h-2 bg-neutral-100 rounded-full overflow-hidden">
              <div className="h-full bg-orange-500 rounded-full animate-pulse w-full" />
            </div>
          )}

          {progress.status === "completed" && (
            <div className="w-full h-2 bg-green-500 rounded-full" />
          )}

          {progress.status === "error" && (
            <div className="w-full h-2 bg-red-500 rounded-full" />
          )}

          {/* Stats */}
          <div className="flex gap-6 text-xs text-neutral-600">
            <span>{progress.totalScanned} objetos escaneados</span>
            <span>{progress.totalInserted} archivos indexados</span>
            <span>{progress.batches} batches</span>
          </div>

          {progress.status === "completed" && (
            <p className="text-xs text-green-600 font-medium">
              Sincronización completada. El índice está actualizado.
            </p>
          )}

          {progress.error && (
            <p className="text-xs text-red-600">{progress.error}</p>
          )}
        </div>
      )}
    </div>
  );
}

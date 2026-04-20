"use client";

import { useState, useCallback, useRef, useEffect } from "react";

// ─── Types ──────────────────────────────────────────────────────────

export interface DownloadJob {
  id: string;
  folderName: string;
  totalFiles: number;
  completedFiles: number;
  failedFiles: number;
  totalBytes: number;
  downloadedBytes: number;
  status: "preparing" | "downloading" | "paused" | "completed" | "error";
  error?: string | undefined;
  speed: number; // bytes per second
  eta: number; // estimated seconds remaining
  startedAt: number; // timestamp
}

interface FileEntry {
  path: string;
  name: string;
  relativePath: string;
}

// ─── Hook ───────────────────────────────────────────────────────────

export function useDownloadManager() {
  const [jobs, setJobs] = useState<DownloadJob[]>([]);
  const abortRef = useRef<Map<string, AbortController>>(new Map());
  const pauseRef = useRef<Map<string, boolean>>(new Map());

  // Auto-dismiss completed jobs after 30 seconds
  useEffect(() => {
    const completedJobs = jobs.filter((j) => j.status === "completed");
    if (completedJobs.length === 0) return;

    const timers = completedJobs.map((job) => {
      return setTimeout(() => {
        setJobs((prev) => prev.filter((j) => j.id !== job.id));
      }, 30_000);
    });

    return () => timers.forEach(clearTimeout);
  }, [jobs]);

  /**
   * Download a single file via presigned URL.
   */
  const downloadFile = useCallback(async (path: string, name: string) => {
    try {
      const res = await fetch("/api/media/presigned-url", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ path, intent: "download" }),
      });

      const data = await res.json();
      if (!data.ok) {
        console.error("Download failed:", data.error);
        return;
      }

      // Trigger browser download via hidden anchor
      const a = document.createElement("a");
      a.href = data.data.url;
      a.download = name;
      a.style.display = "none";
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
    } catch (err) {
      console.error("Download error:", err);
    }
  }, []);

  /**
   * Download an entire folder using File System Access API.
   * Supports pause/resume via flag pattern.
   */
  const downloadFolder = useCallback(
    async (folderPath: string, folderName: string) => {
      // Check browser support
      if (!("showDirectoryPicker" in window)) {
        alert(
          "Tu navegador no soporta descarga de carpetas. Usa Chrome o Edge.",
        );
        return;
      }

      const jobId = `job_${Date.now()}`;
      const controller = new AbortController();
      abortRef.current.set(jobId, controller);
      pauseRef.current.set(jobId, false);

      const now = Date.now();

      // Create job
      setJobs((prev) => [
        ...prev,
        {
          id: jobId,
          folderName,
          totalFiles: 0,
          completedFiles: 0,
          failedFiles: 0,
          totalBytes: 0,
          downloadedBytes: 0,
          status: "preparing",
          speed: 0,
          eta: 0,
          startedAt: now,
        },
      ]);

      try {
        // 1. Ask user to pick a local directory
        const dirHandle = await (
          window as unknown as {
            showDirectoryPicker: () => Promise<FileSystemDirectoryHandle>;
          }
        ).showDirectoryPicker();

        // 2. Fetch file list from API
        const listRes = await fetch(
          `/api/catalog/browse?path=${encodeURIComponent(folderPath)}`,
          { signal: controller.signal },
        );
        const listData = await listRes.json();

        if (!listData.ok) {
          throw new Error(listData.error?.message ?? "Failed to list folder");
        }

        const files: FileEntry[] = (
          listData.data.files as Array<{ path: string; name: string }>
        ).map((f) => ({
          path: f.path,
          name: f.name,
          relativePath: f.name,
        }));

        const totalBytes = (
          listData.data.files as Array<{ size: number }>
        ).reduce((sum, f) => sum + f.size, 0);

        setJobs((prev) =>
          prev.map((j) =>
            j.id === jobId
              ? {
                  ...j,
                  totalFiles: files.length,
                  totalBytes,
                  status: "downloading",
                }
              : j,
          ),
        );

        // 3. Download each file with pause/resume support
        let downloadedBytes = 0;
        let completedFiles = 0;
        let failedFiles = 0;
        const startTime = Date.now();

        for (const file of files) {
          if (controller.signal.aborted) break;

          // Pause check — wait until unpaused
          while (pauseRef.current.get(jobId)) {
            await new Promise((resolve) => setTimeout(resolve, 200));
            if (controller.signal.aborted) break;
          }
          if (controller.signal.aborted) break;

          try {
            // Get presigned URL
            const urlRes = await fetch("/api/media/presigned-url", {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({ path: file.path, intent: "download" }),
              signal: controller.signal,
            });

            const urlData = await urlRes.json();
            if (!urlData.ok) {
              failedFiles += 1;
              continue;
            }

            // Download file content
            const fileRes = await fetch(urlData.data.url, {
              signal: controller.signal,
            });

            if (!fileRes.ok) {
              failedFiles += 1;
              continue;
            }

            const blob = await fileRes.blob();

            // Write to local filesystem
            const fileHandle = await dirHandle.getFileHandle(
              file.relativePath,
              { create: true },
            );
            const writable = await fileHandle.createWritable();
            await writable.write(blob);
            await writable.close();

            // Update progress
            downloadedBytes += blob.size;
            completedFiles += 1;
          } catch (fileErr) {
            if ((fileErr as Error).name === "AbortError") throw fileErr;
            failedFiles += 1;
          }

          // Calculate speed and ETA
          const elapsed = (Date.now() - startTime) / 1000;
          const speed = elapsed > 0 ? downloadedBytes / elapsed : 0;
          const remainingBytes = totalBytes - downloadedBytes;
          const eta = speed > 0 ? remainingBytes / speed : 0;

          setJobs((prev) =>
            prev.map((j) =>
              j.id === jobId
                ? { ...j, completedFiles, failedFiles, downloadedBytes, speed, eta }
                : j,
            ),
          );
        }

        // Done
        setJobs((prev) =>
          prev.map((j) =>
            j.id === jobId
              ? {
                  ...j,
                  status: failedFiles > 0 && completedFiles === 0 ? "error" : "completed",
                  speed: 0,
                  eta: 0,
                  error:
                    failedFiles > 0
                      ? `${failedFiles} archivo${failedFiles > 1 ? "s" : ""} fallaron`
                      : undefined,
                }
              : j,
          ),
        );
      } catch (err) {
        if ((err as Error).name === "AbortError") {
          // Cancelled — remove job
          setJobs((prev) => prev.filter((j) => j.id !== jobId));
          return;
        }
        setJobs((prev) =>
          prev.map((j) =>
            j.id === jobId
              ? { ...j, status: "error", error: (err as Error).message, speed: 0, eta: 0 }
              : j,
          ),
        );
      } finally {
        abortRef.current.delete(jobId);
        pauseRef.current.delete(jobId);
      }
    },
    [],
  );

  /**
   * Pause an active download job.
   */
  const pauseJob = useCallback((jobId: string) => {
    pauseRef.current.set(jobId, true);
    setJobs((prev) =>
      prev.map((j) =>
        j.id === jobId ? { ...j, status: "paused", speed: 0 } : j,
      ),
    );
  }, []);

  /**
   * Resume a paused download job.
   */
  const resumeJob = useCallback((jobId: string) => {
    pauseRef.current.set(jobId, false);
    setJobs((prev) =>
      prev.map((j) =>
        j.id === jobId ? { ...j, status: "downloading" } : j,
      ),
    );
  }, []);

  /**
   * Cancel an active download job.
   */
  const cancelJob = useCallback((jobId: string) => {
    const controller = abortRef.current.get(jobId);
    if (controller) {
      controller.abort();
      abortRef.current.delete(jobId);
    }
    pauseRef.current.delete(jobId);
    setJobs((prev) => prev.filter((j) => j.id !== jobId));
  }, []);

  /**
   * Remove a completed/errored job from the list.
   */
  const dismissJob = useCallback((jobId: string) => {
    setJobs((prev) => prev.filter((j) => j.id !== jobId));
  }, []);

  return {
    jobs,
    downloadFile,
    downloadFolder,
    pauseJob,
    resumeJob,
    cancelJob,
    dismissJob,
  };
}

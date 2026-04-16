"use client";

import type { DownloadJob } from "@/hooks/useDownloadManager";

interface DownloadProgressProps {
  jobs: DownloadJob[];
  onCancel: (jobId: string) => void;
  onDismiss: (jobId: string) => void;
}

export function DownloadProgress({ jobs, onCancel, onDismiss }: DownloadProgressProps) {
  if (jobs.length === 0) return null;

  return (
    <div className="fixed bottom-4 right-4 z-40 w-80 space-y-2">
      {jobs.map((job) => {
        const progress =
          job.totalFiles > 0
            ? Math.round((job.completedFiles / job.totalFiles) * 100)
            : 0;

        return (
          <div
            key={job.id}
            className="bg-white rounded-xl shadow-lg border border-neutral-200 p-4"
          >
            {/* Header */}
            <div className="flex items-center justify-between mb-2">
              <p className="text-sm font-medium text-neutral-900 truncate">
                {job.folderName}
              </p>
              {job.status === "downloading" && (
                <button
                  onClick={() => onCancel(job.id)}
                  className="text-xs text-neutral-400 hover:text-red-500 transition"
                >
                  Cancelar
                </button>
              )}
              {(job.status === "completed" || job.status === "error") && (
                <button
                  onClick={() => onDismiss(job.id)}
                  className="text-xs text-neutral-400 hover:text-neutral-600 transition"
                >
                  Cerrar
                </button>
              )}
            </div>

            {/* Progress bar */}
            <div className="w-full h-1.5 bg-neutral-100 rounded-full overflow-hidden mb-2">
              <div
                className={`h-full rounded-full transition-all duration-300 ${
                  job.status === "error"
                    ? "bg-red-500"
                    : job.status === "completed"
                      ? "bg-green-500"
                      : "bg-orange-500"
                }`}
                style={{ width: `${progress}%` }}
              />
            </div>

            {/* Status */}
            <div className="flex items-center justify-between">
              <p className="text-xs text-neutral-500">
                {job.status === "preparing" && "Preparando..."}
                {job.status === "downloading" &&
                  `${job.completedFiles}/${job.totalFiles} archivos`}
                {job.status === "completed" && "Descarga completa"}
                {job.status === "error" && (
                  <span className="text-red-500">{job.error ?? "Error"}</span>
                )}
              </p>
              {job.status === "downloading" && job.speed > 0 && (
                <p className="text-xs text-neutral-400">
                  {formatSpeed(job.speed)}
                </p>
              )}
            </div>
          </div>
        );
      })}
    </div>
  );
}

function formatSpeed(bytesPerSec: number): string {
  if (bytesPerSec < 1024) return `${Math.round(bytesPerSec)} B/s`;
  if (bytesPerSec < 1024 * 1024) return `${(bytesPerSec / 1024).toFixed(1)} KB/s`;
  return `${(bytesPerSec / (1024 * 1024)).toFixed(1)} MB/s`;
}

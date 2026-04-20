"use client";

import { Pause, Play, X, Check, AlertCircle, Loader2 } from "lucide-react";
import type { DownloadJob } from "@/hooks/useDownloadManager";

interface DownloadProgressProps {
  jobs: DownloadJob[];
  onPause: (jobId: string) => void;
  onResume: (jobId: string) => void;
  onCancel: (jobId: string) => void;
  onDismiss: (jobId: string) => void;
}

export function DownloadProgress({
  jobs,
  onPause,
  onResume,
  onCancel,
  onDismiss,
}: DownloadProgressProps) {
  if (jobs.length === 0) return null;

  return (
    <div className="fixed bottom-20 right-4 z-40 w-80 space-y-2">
      {jobs.map((job) => (
        <DownloadJobCard
          key={job.id}
          job={job}
          onPause={onPause}
          onResume={onResume}
          onCancel={onCancel}
          onDismiss={onDismiss}
        />
      ))}
    </div>
  );
}

function DownloadJobCard({
  job,
  onPause,
  onResume,
  onCancel,
  onDismiss,
}: {
  job: DownloadJob;
  onPause: (id: string) => void;
  onResume: (id: string) => void;
  onCancel: (id: string) => void;
  onDismiss: (id: string) => void;
}) {
  const progress =
    job.totalFiles > 0
      ? Math.round((job.completedFiles / job.totalFiles) * 100)
      : 0;

  const isActive = job.status === "downloading" || job.status === "paused";

  return (
    <div className="bg-white rounded-xl shadow-lg border border-neutral-200 p-4 animate-in slide-in-from-right duration-300">
      {/* Header row */}
      <div className="flex items-center justify-between mb-2">
        <div className="flex items-center gap-2 min-w-0">
          <StatusIcon status={job.status} />
          <p className="text-sm font-medium text-neutral-900 truncate">
            {job.folderName}
          </p>
        </div>

        {/* Dismiss button for completed/error */}
        {(job.status === "completed" || job.status === "error") && (
          <button
            onClick={() => onDismiss(job.id)}
            className="p-1 rounded-md text-neutral-400 hover:text-neutral-600 transition flex-shrink-0"
          >
            <X className="w-4 h-4" />
          </button>
        )}
      </div>

      {/* Progress bar */}
      <div className="w-full h-2 bg-neutral-100 rounded-full overflow-hidden mb-2">
        <div
          className={`h-full rounded-full transition-all duration-500 ${progressBarColor(job.status)}`}
          style={{ width: `${progress}%` }}
        />
      </div>

      {/* Stats row */}
      <div className="flex items-center justify-between text-xs">
        <div className="text-neutral-500">
          {job.status === "preparing" && (
            <span>Preparando...</span>
          )}
          {job.status === "downloading" && (
            <span>
              {job.completedFiles}/{job.totalFiles} archivos
              {job.failedFiles > 0 && (
                <span className="text-amber-500 ml-1">
                  ({job.failedFiles} error{job.failedFiles > 1 ? "es" : ""})
                </span>
              )}
            </span>
          )}
          {job.status === "paused" && (
            <span className="text-amber-600">
              Pausado · {job.completedFiles}/{job.totalFiles}
            </span>
          )}
          {job.status === "completed" && (
            <span className="text-green-600">
              Descarga completa
              {job.failedFiles > 0 && (
                <span className="text-amber-500 ml-1">
                  ({job.failedFiles} error{job.failedFiles > 1 ? "es" : ""})
                </span>
              )}
            </span>
          )}
          {job.status === "error" && (
            <span className="text-red-500">{job.error ?? "Error"}</span>
          )}
        </div>

        {/* Speed + ETA */}
        {job.status === "downloading" && job.speed > 0 && (
          <div className="text-neutral-400 flex items-center gap-2">
            <span>{formatSpeed(job.speed)}</span>
            {job.eta > 0 && (
              <span>{formatEta(job.eta)}</span>
            )}
          </div>
        )}
      </div>

      {/* Action buttons */}
      {isActive && (
        <div className="flex items-center gap-2 mt-3 pt-2 border-t border-neutral-100">
          {job.status === "downloading" ? (
            <button
              onClick={() => onPause(job.id)}
              className="flex-1 flex items-center justify-center gap-1.5 py-1.5 text-xs font-medium text-neutral-600 bg-neutral-100 hover:bg-neutral-200 rounded-lg transition"
            >
              <Pause className="w-3.5 h-3.5" />
              Pausar
            </button>
          ) : (
            <button
              onClick={() => onResume(job.id)}
              className="flex-1 flex items-center justify-center gap-1.5 py-1.5 text-xs font-medium text-white bg-orange-500 hover:bg-orange-600 rounded-lg transition"
            >
              <Play className="w-3.5 h-3.5" fill="white" />
              Reanudar
            </button>
          )}
          <button
            onClick={() => onCancel(job.id)}
            className="flex-1 flex items-center justify-center gap-1.5 py-1.5 text-xs font-medium text-red-600 bg-red-50 hover:bg-red-100 rounded-lg transition"
          >
            <X className="w-3.5 h-3.5" />
            Cancelar
          </button>
        </div>
      )}
    </div>
  );
}

function StatusIcon({ status }: { status: DownloadJob["status"] }) {
  switch (status) {
    case "preparing":
      return <Loader2 className="w-4 h-4 text-neutral-400 animate-spin flex-shrink-0" />;
    case "downloading":
      return <Loader2 className="w-4 h-4 text-orange-500 animate-spin flex-shrink-0" />;
    case "paused":
      return <Pause className="w-4 h-4 text-amber-500 flex-shrink-0" />;
    case "completed":
      return <Check className="w-4 h-4 text-green-500 flex-shrink-0" />;
    case "error":
      return <AlertCircle className="w-4 h-4 text-red-500 flex-shrink-0" />;
  }
}

function progressBarColor(status: DownloadJob["status"]): string {
  switch (status) {
    case "preparing":
      return "bg-neutral-300";
    case "downloading":
      return "bg-orange-500";
    case "paused":
      return "bg-amber-400";
    case "completed":
      return "bg-green-500";
    case "error":
      return "bg-red-500";
  }
}

function formatSpeed(bytesPerSec: number): string {
  if (bytesPerSec < 1024) return `${Math.round(bytesPerSec)} B/s`;
  if (bytesPerSec < 1024 * 1024)
    return `${(bytesPerSec / 1024).toFixed(1)} KB/s`;
  return `${(bytesPerSec / (1024 * 1024)).toFixed(1)} MB/s`;
}

function formatEta(seconds: number): string {
  if (seconds < 60) return `${Math.round(seconds)}s`;
  if (seconds < 3600) {
    const m = Math.floor(seconds / 60);
    const s = Math.round(seconds % 60);
    return `${m}m ${s}s`;
  }
  const h = Math.floor(seconds / 3600);
  const m = Math.round((seconds % 3600) / 60);
  return `${h}h ${m}m`;
}

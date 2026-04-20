"use client";

import { AlertTriangle, Download, X, HardDrive } from "lucide-react";

interface SizeWarningProps {
  isOpen: boolean;
  onClose: () => void;
  onConfirm: () => void;
  folderName: string;
  totalSize: string;
  totalFiles: number;
  totalSizeBytes: number;
}

const SIZE_5GB = 5 * 1024 * 1024 * 1024;

export function SizeWarning({
  isOpen,
  onClose,
  onConfirm,
  folderName,
  totalSize,
  totalFiles,
  totalSizeBytes,
}: SizeWarningProps) {
  if (!isOpen) return null;

  const isLarge = totalSizeBytes >= SIZE_5GB;

  return (
    <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
      {/* Backdrop */}
      <div className="absolute inset-0 bg-black/40 backdrop-blur-sm" onClick={onClose} />

      {/* Sheet */}
      <div className="relative bg-white w-full sm:max-w-sm sm:rounded-2xl rounded-t-2xl shadow-2xl overflow-hidden animate-in slide-in-from-bottom duration-200">
        {/* Close */}
        <button
          onClick={onClose}
          className="absolute top-4 right-4 p-1 rounded-full text-neutral-400 hover:text-neutral-600 transition"
        >
          <X className="w-5 h-5" />
        </button>

        <div className="p-6 pt-8 text-center">
          {/* Icon */}
          <div className={`w-14 h-14 rounded-full flex items-center justify-center mx-auto mb-4 ${
            isLarge ? "bg-amber-50" : "bg-blue-50"
          }`}>
            {isLarge ? (
              <AlertTriangle className="w-7 h-7 text-amber-500" />
            ) : (
              <HardDrive className="w-7 h-7 text-blue-500" />
            )}
          </div>

          {/* Content */}
          <h3 className="text-lg font-semibold text-neutral-900 mb-2">
            {isLarge ? "Carpeta grande" : "Confirmar descarga"}
          </h3>

          <p className="text-sm text-neutral-500 leading-relaxed mb-4">
            {isLarge
              ? `Esta carpeta contiene ${totalFiles.toLocaleString()} archivos y pesa ${totalSize}. La descarga puede tomar tiempo considerable.`
              : `Se descargar\u00e1n ${totalFiles.toLocaleString()} archivos (${totalSize}) de "${folderName}".`}
          </p>

          {/* Size badge */}
          <div className={`inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-sm font-medium mb-6 ${
            isLarge
              ? "bg-amber-50 text-amber-700"
              : "bg-neutral-100 text-neutral-600"
          }`}>
            <HardDrive className="w-4 h-4" />
            {totalFiles.toLocaleString()} archivos · {totalSize}
          </div>

          {/* Actions */}
          <div className="flex gap-3">
            <button
              onClick={onClose}
              className="flex-1 py-2.5 text-sm font-medium text-neutral-600 bg-neutral-100 hover:bg-neutral-200 rounded-xl transition"
            >
              Cancelar
            </button>
            <button
              onClick={onConfirm}
              className="flex-1 flex items-center justify-center gap-2 py-2.5 text-sm font-medium text-white bg-orange-500 hover:bg-orange-600 rounded-xl transition"
            >
              <Download className="w-4 h-4" />
              Descargar
            </button>
          </div>

          {isLarge && (
            <p className="text-xs text-neutral-400 mt-3">
              Asegura tener espacio suficiente y una conexi\u00f3n estable
            </p>
          )}
        </div>
      </div>
    </div>
  );
}

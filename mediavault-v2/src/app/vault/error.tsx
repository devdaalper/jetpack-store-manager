"use client";

export default function VaultError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  return (
    <div className="flex items-center justify-center min-h-[400px] p-6">
      <div className="text-center max-w-sm">
        <div className="w-14 h-14 bg-red-50 rounded-full flex items-center justify-center mx-auto mb-4">
          <svg className="w-7 h-7 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
          </svg>
        </div>
        <h2 className="text-lg font-semibold text-neutral-900 mb-2">
          Error al cargar el contenido
        </h2>
        <p className="text-sm text-neutral-500 mb-4">
          {error.message || "Ocurrió un error inesperado. Por favor intenta de nuevo."}
        </p>
        <button
          onClick={reset}
          className="px-4 py-2 text-sm font-medium bg-orange-600 text-white rounded-lg hover:bg-orange-700 transition"
        >
          Reintentar
        </button>
      </div>
    </div>
  );
}

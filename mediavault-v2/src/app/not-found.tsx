import Link from "next/link";

export default function NotFound() {
  return (
    <div className="min-h-screen flex items-center justify-center bg-neutral-50">
      <div className="text-center">
        <p className="text-6xl font-bold text-neutral-200">404</p>
        <h1 className="text-xl font-semibold text-neutral-900 mt-4">
          Página no encontrada
        </h1>
        <p className="text-sm text-neutral-500 mt-2">
          La página que buscas no existe o fue movida.
        </p>
        <Link
          href="/vault"
          className="inline-block mt-6 px-4 py-2 text-sm font-medium bg-orange-600 text-white rounded-lg hover:bg-orange-700 transition"
        >
          Ir a la biblioteca
        </Link>
      </div>
    </div>
  );
}

"use client";

import { MessageCircle, X, Download } from "lucide-react";

interface UpgradeSheetProps {
  isOpen: boolean;
  onClose: () => void;
  whatsappNumber?: string | undefined;
  reason: "download" | "play_limit" | "preview_limit";
}

const MESSAGES = {
  download: {
    title: "Descargas con plan activo",
    description:
      "Navega y escucha libremente. Cuando quieras descargar, activa tu acceso para obtener todos los archivos.",
    icon: Download,
  },
  play_limit: {
    title: "Límite de previews alcanzado",
    description:
      "Has explorado bastante contenido este mes. Para acceso ilimitado, activa tu plan.",
    icon: MessageCircle,
  },
  preview_limit: {
    title: "Preview completo",
    description:
      "Escuchaste la vista previa completa. Con un plan activo, escucha sin límites de tiempo.",
    icon: MessageCircle,
  },
} as const;

export function UpgradeSheet({ isOpen, onClose, whatsappNumber, reason }: UpgradeSheetProps) {
  if (!isOpen) return null;

  const msg = MESSAGES[reason];
  const Icon = msg.icon;

  const whatsappText = encodeURIComponent(
    "Hola, me interesa obtener acceso completo al catálogo de JetPack Store."
  );
  const whatsappUrl = whatsappNumber
    ? `https://wa.me/${whatsappNumber}?text=${whatsappText}`
    : undefined;

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
          <div className="w-14 h-14 bg-orange-50 rounded-full flex items-center justify-center mx-auto mb-4">
            <Icon className="w-7 h-7 text-orange-500" />
          </div>

          {/* Content */}
          <h3 className="text-lg font-semibold text-neutral-900 mb-2">
            {msg.title}
          </h3>
          <p className="text-sm text-neutral-500 leading-relaxed mb-6">
            {msg.description}
          </p>

          {/* CTA */}
          {whatsappUrl ? (
            <a
              href={whatsappUrl}
              target="_blank"
              rel="noopener noreferrer"
              className="flex items-center justify-center gap-2 w-full py-3 bg-green-600 hover:bg-green-700 text-white font-medium rounded-xl transition"
            >
              <MessageCircle className="w-5 h-5" />
              Contactar por WhatsApp
            </a>
          ) : (
            <p className="text-sm text-neutral-400">
              Contacta al administrador para más información.
            </p>
          )}

          <p className="text-xs text-neutral-400 mt-3">
            Se abrirá WhatsApp con tu solicitud preparada
          </p>
        </div>
      </div>
    </div>
  );
}

"use client";

import { useState } from "react";

interface SettingsEditorProps {
  initialConfig: Record<string, unknown>;
}

// Group settings into sections for the UI
const SECTIONS = [
  {
    title: "Precios MXN",
    fields: [
      { key: "price_mxn_basic", label: "Básico", type: "number" },
      { key: "price_mxn_vip_basic", label: "VIP + Básico", type: "number" },
      { key: "price_mxn_vip_videos", label: "VIP + Videos", type: "number" },
      { key: "price_mxn_vip_pelis", label: "VIP + Películas", type: "number" },
      { key: "price_mxn_full", label: "Full", type: "number" },
    ],
  },
  {
    title: "Precios USD",
    fields: [
      { key: "price_usd_vip_videos", label: "VIP + Videos", type: "number" },
      { key: "price_usd_vip_pelis", label: "VIP + Películas", type: "number" },
      { key: "price_usd_full", label: "Full", type: "number" },
    ],
  },
  {
    title: "Contacto",
    fields: [
      { key: "whatsapp_number", label: "WhatsApp (con código país)", type: "text", placeholder: "5215512345678" },
      { key: "reply_to_email", label: "Reply-To email", type: "email" },
      { key: "notify_emails", label: "Emails de notificación (separados por coma)", type: "text" },
    ],
  },
  {
    title: "Infraestructura",
    fields: [
      { key: "cloudflare_domain", label: "Dominio Cloudflare (opcional)", type: "text", placeholder: "https://cdn.tudominio.com" },
    ],
  },
] as const;

const EMAIL_TEMPLATES = [
  { key: "email_template_basic", label: "Básico" },
  { key: "email_template_vip_basic", label: "VIP + Básico" },
  { key: "email_template_vip_videos", label: "VIP + Videos" },
  { key: "email_template_vip_pelis", label: "VIP + Películas" },
  { key: "email_template_full", label: "Full" },
] as const;

export function SettingsEditor({ initialConfig }: SettingsEditorProps) {
  const [config, setConfig] = useState<Record<string, unknown>>(initialConfig);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);
  const [activeTemplate, setActiveTemplate] = useState<string | null>(null);

  function updateField(key: string, value: unknown) {
    setConfig((prev) => ({ ...prev, [key]: value }));
    setSaved(false);
  }

  async function saveAll() {
    setSaving(true);
    try {
      const res = await fetch("/api/admin/settings", {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(config),
      });
      const data = await res.json();
      if (data.ok) setSaved(true);
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="space-y-8">
      {/* Grouped settings */}
      {SECTIONS.map((section) => (
        <div key={section.title} className="bg-white rounded-xl border border-neutral-200 p-5">
          <h3 className="text-sm font-semibold text-neutral-900 mb-4">{section.title}</h3>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
            {section.fields.map((field) => (
              <div key={field.key}>
                <label className="block text-xs font-medium text-neutral-600 mb-1">
                  {field.label}
                </label>
                <input
                  type={field.type}
                  value={String(config[field.key] ?? "")}
                  onChange={(e) =>
                    updateField(
                      field.key,
                      field.type === "number" ? Number(e.target.value) : e.target.value,
                    )
                  }
                  placeholder={"placeholder" in field ? field.placeholder : undefined}
                  className="w-full px-3 py-2 text-sm border border-neutral-300 rounded-lg focus:border-orange-500 focus:ring-2 focus:ring-orange-500/20 outline-none"
                />
              </div>
            ))}
          </div>
        </div>
      ))}

      {/* Email Templates */}
      <div className="bg-white rounded-xl border border-neutral-200 p-5">
        <h3 className="text-sm font-semibold text-neutral-900 mb-4">
          Plantillas de Correo (HTML)
        </h3>
        <div className="flex gap-2 mb-4">
          {EMAIL_TEMPLATES.map((tpl) => (
            <button
              key={tpl.key}
              onClick={() => setActiveTemplate(activeTemplate === tpl.key ? null : tpl.key)}
              className={`px-3 py-1.5 text-xs font-medium rounded-full transition ${
                activeTemplate === tpl.key
                  ? "bg-neutral-900 text-white"
                  : "bg-neutral-100 text-neutral-600 hover:bg-neutral-200"
              }`}
            >
              {tpl.label}
            </button>
          ))}
        </div>

        {activeTemplate && (
          <div>
            <textarea
              value={String(config[activeTemplate] ?? "")}
              onChange={(e) => updateField(activeTemplate, e.target.value)}
              rows={12}
              className="w-full px-3 py-2 text-sm font-mono border border-neutral-300 rounded-lg focus:border-orange-500 focus:ring-2 focus:ring-orange-500/20 outline-none resize-y"
              placeholder="<html><body>Tu plantilla HTML aquí...</body></html>"
            />
            <p className="text-xs text-neutral-400 mt-1">
              Variables disponibles: {"{{email}}"}, {"{{package}}"}, {"{{amount}}"}, {"{{currency}}"}
            </p>
          </div>
        )}
      </div>

      {/* Save button */}
      <div className="flex items-center gap-3">
        <button
          onClick={saveAll}
          disabled={saving}
          className="px-6 py-2.5 text-sm font-medium bg-orange-600 text-white rounded-lg hover:bg-orange-700 disabled:opacity-50 transition"
        >
          {saving ? "Guardando..." : "Guardar cambios"}
        </button>
        {saved && (
          <span className="text-sm text-green-600 font-medium">
            ✓ Guardado
          </span>
        )}
      </div>
    </div>
  );
}

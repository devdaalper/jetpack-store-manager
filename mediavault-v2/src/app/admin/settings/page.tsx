/**
 * Admin: Settings Page
 *
 * Replaces the WordPress admin settings panel.
 * Manages pricing, email templates, WhatsApp, Cloudflare, etc.
 */

import { getAllConfig } from "@/application/config/manage-config";
import { SettingsEditor } from "@/components/admin/settings-editor";

export default async function AdminSettingsPage() {
  const config = await getAllConfig();

  return (
    <div>
      <div className="mb-6">
        <h2 className="text-xl font-bold text-neutral-900">Configuración</h2>
        <p className="text-sm text-neutral-500 mt-1">
          Precios, plantillas de correo, WhatsApp y más.
        </p>
      </div>

      <SettingsEditor initialConfig={config} />
    </div>
  );
}

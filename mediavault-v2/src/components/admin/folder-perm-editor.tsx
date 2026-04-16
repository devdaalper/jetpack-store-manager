"use client";

import { useState } from "react";
import { TIER_NAMES } from "@/domain/types";

interface Permission {
  id: string;
  folder_path: string;
  allowed_tiers: number[];
  updated_at: string;
}

interface FolderPermEditorProps {
  permissions: Permission[];
}

export function FolderPermEditor({ permissions: initial }: FolderPermEditorProps) {
  const [permissions, setPermissions] = useState(initial);
  const [newPath, setNewPath] = useState("");
  const [saving, setSaving] = useState<string | null>(null);

  async function savePerm(folderPath: string, allowedTiers: number[]) {
    setSaving(folderPath);
    try {
      const res = await fetch("/api/admin/folders", {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ folderPath, allowedTiers }),
      });

      const data = await res.json();
      if (data.ok) {
        setPermissions((prev) =>
          prev.map((p) =>
            p.folder_path === folderPath ? { ...p, allowed_tiers: allowedTiers } : p,
          ),
        );
      }
    } finally {
      setSaving(null);
    }
  }

  async function addFolder() {
    if (!newPath.trim()) return;
    const path = newPath.trim().endsWith("/") ? newPath.trim() : `${newPath.trim()}/`;

    setSaving("new");
    try {
      const res = await fetch("/api/admin/folders", {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ folderPath: path, allowedTiers: [] }),
      });

      const data = await res.json();
      if (data.ok) {
        setPermissions((prev) => [
          ...prev,
          { id: crypto.randomUUID(), folder_path: path, allowed_tiers: [], updated_at: new Date().toISOString() },
        ]);
        setNewPath("");
      }
    } finally {
      setSaving(null);
    }
  }

  function toggleTier(folderPath: string, tier: number, currentTiers: number[]) {
    const newTiers = currentTiers.includes(tier)
      ? currentTiers.filter((t) => t !== tier)
      : [...currentTiers, tier].sort();
    savePerm(folderPath, newTiers);
  }

  return (
    <div className="space-y-4">
      {/* Add new folder */}
      <div className="flex gap-2">
        <input
          type="text"
          value={newPath}
          onChange={(e) => setNewPath(e.target.value)}
          placeholder="Music/Premium/"
          className="flex-1 px-3 py-2 text-sm border border-neutral-300 rounded-lg focus:border-orange-500 focus:ring-2 focus:ring-orange-500/20 outline-none"
        />
        <button
          onClick={addFolder}
          disabled={saving === "new"}
          className="px-4 py-2 text-sm font-medium bg-neutral-900 text-white rounded-lg hover:bg-neutral-800 disabled:opacity-50 transition"
        >
          Agregar
        </button>
      </div>

      {/* Permissions table */}
      <div className="bg-white rounded-xl border border-neutral-200 overflow-hidden">
        <table className="w-full text-sm">
          <thead>
            <tr className="bg-neutral-50 border-b border-neutral-200">
              <th className="text-left px-4 py-3 font-medium text-neutral-600">Carpeta</th>
              {Object.entries(TIER_NAMES).map(([value, name]) => (
                <th key={value} className="text-center px-2 py-3 font-medium text-neutral-600 text-xs">
                  {name}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {permissions.map((perm) => (
              <tr key={perm.id} className="border-b border-neutral-100 last:border-0">
                <td className="px-4 py-3 text-neutral-900 font-mono text-xs">
                  {perm.folder_path}
                </td>
                {Object.keys(TIER_NAMES).map((tierStr) => {
                  const tier = Number(tierStr);
                  const isAllowed = perm.allowed_tiers.includes(tier) || tier === 5;
                  const isFullTier = tier === 5;

                  return (
                    <td key={tier} className="text-center px-2 py-3">
                      <button
                        onClick={() => {
                          if (!isFullTier) toggleTier(perm.folder_path, tier, perm.allowed_tiers);
                        }}
                        disabled={isFullTier || saving === perm.folder_path}
                        className={`w-6 h-6 rounded-md border text-xs transition ${
                          isAllowed
                            ? "bg-green-100 border-green-300 text-green-700"
                            : "bg-white border-neutral-200 text-neutral-300 hover:border-neutral-400"
                        } ${isFullTier ? "cursor-not-allowed opacity-50" : "cursor-pointer"}`}
                        title={isFullTier ? "Full siempre tiene acceso" : undefined}
                      >
                        {isAllowed ? "✓" : ""}
                      </button>
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>

        {permissions.length === 0 && (
          <p className="text-center py-8 text-sm text-neutral-400">
            Sin permisos configurados. Todas las carpetas son accesibles para todos los tiers.
          </p>
        )}
      </div>
    </div>
  );
}

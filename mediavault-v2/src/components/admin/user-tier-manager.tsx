"use client";

import { useState } from "react";
import { TIER_NAMES } from "@/domain/types";

interface User {
  id: string;
  email: string;
  tier: number;
  tierName: string;
  is_admin: boolean;
  updated_at: string;
}

interface UserTierManagerProps {
  users: User[];
}

export function UserTierManager({ users: initialUsers }: UserTierManagerProps) {
  const [users, setUsers] = useState(initialUsers);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function updateTier(email: string, newTier: number) {
    setSaving(true);
    try {
      const res = await fetch("/api/admin/users", {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email, tier: newTier }),
      });

      const data = await res.json();
      if (data.ok) {
        setUsers((prev) =>
          prev.map((u) =>
            u.email === email
              ? { ...u, tier: newTier, tierName: TIER_NAMES[newTier] ?? `Tier ${newTier}` }
              : u,
          ),
        );
        setEditingId(null);
      }
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="bg-white rounded-xl border border-neutral-200 overflow-hidden">
      <table className="w-full text-sm">
        <thead>
          <tr className="bg-neutral-50 border-b border-neutral-200">
            <th className="text-left px-4 py-3 font-medium text-neutral-600">Email</th>
            <th className="text-left px-4 py-3 font-medium text-neutral-600">Tier</th>
            <th className="text-left px-4 py-3 font-medium text-neutral-600">Admin</th>
            <th className="text-right px-4 py-3 font-medium text-neutral-600">Acciones</th>
          </tr>
        </thead>
        <tbody>
          {users.map((user) => (
            <tr key={user.id} className="border-b border-neutral-100 last:border-0">
              <td className="px-4 py-3 text-neutral-900">{user.email}</td>
              <td className="px-4 py-3">
                {editingId === user.id ? (
                  <select
                    defaultValue={user.tier}
                    onChange={(e) => updateTier(user.email, Number(e.target.value))}
                    disabled={saving}
                    className="text-sm border border-neutral-300 rounded-lg px-2 py-1"
                  >
                    {Object.entries(TIER_NAMES).map(([value, label]) => (
                      <option key={value} value={value}>
                        {label}
                      </option>
                    ))}
                  </select>
                ) : (
                  <span
                    className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${
                      user.tier === 0
                        ? "bg-neutral-100 text-neutral-600"
                        : user.tier === 5
                          ? "bg-orange-100 text-orange-700"
                          : "bg-blue-50 text-blue-700"
                    }`}
                  >
                    {user.tierName}
                  </span>
                )}
              </td>
              <td className="px-4 py-3">
                {user.is_admin && (
                  <span className="text-xs text-green-600 font-medium">Admin</span>
                )}
              </td>
              <td className="px-4 py-3 text-right">
                {editingId === user.id ? (
                  <button
                    onClick={() => setEditingId(null)}
                    className="text-xs text-neutral-500 hover:text-neutral-700"
                  >
                    Cancelar
                  </button>
                ) : (
                  <button
                    onClick={() => setEditingId(user.id)}
                    className="text-xs text-orange-600 hover:text-orange-700 font-medium"
                  >
                    Editar tier
                  </button>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      {users.length === 0 && (
        <p className="text-center py-8 text-sm text-neutral-400">
          No hay usuarios registrados.
        </p>
      )}
    </div>
  );
}

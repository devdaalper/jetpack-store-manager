"use client";

import { useState } from "react";
import type { SaleRow } from "@/application/sales/create-sale";

interface SalesManagerProps {
  initialSales: SaleRow[];
}

export function SalesManager({ initialSales }: SalesManagerProps) {
  const [sales, setSales] = useState(initialSales);
  const [showForm, setShowForm] = useState(false);
  const [saving, setSaving] = useState(false);

  async function handleCreate(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setSaving(true);

    const form = new FormData(e.currentTarget);

    try {
      const res = await fetch("/api/admin/sales", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          email: form.get("email"),
          package: form.get("package"),
          region: form.get("region"),
          amount: Number(form.get("amount")),
          currency: form.get("currency"),
        }),
      });

      const data = await res.json();
      if (data.ok) {
        // Refresh sales list
        const listRes = await fetch("/api/admin/sales");
        const listData = await listRes.json();
        if (listData.ok) setSales(listData.data);
        setShowForm(false);
      }
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="space-y-4">
      {/* Add sale button */}
      <button
        onClick={() => setShowForm(!showForm)}
        className="px-4 py-2 text-sm font-medium bg-orange-600 text-white rounded-lg hover:bg-orange-700 transition"
      >
        {showForm ? "Cancelar" : "+ Nueva Venta"}
      </button>

      {/* New sale form */}
      {showForm && (
        <form onSubmit={handleCreate} className="bg-white rounded-xl border border-neutral-200 p-4 grid grid-cols-2 gap-3">
          <input name="email" type="email" required placeholder="Email" className="col-span-2 px-3 py-2 text-sm border border-neutral-300 rounded-lg" />
          <select name="package" required className="px-3 py-2 text-sm border border-neutral-300 rounded-lg">
            <option value="basic">Básico</option>
            <option value="vip_basic">VIP + Básico</option>
            <option value="vip_videos">VIP + Videos</option>
            <option value="vip_pelis">VIP + Películas</option>
            <option value="full">Full</option>
          </select>
          <select name="region" required className="px-3 py-2 text-sm border border-neutral-300 rounded-lg">
            <option value="national">Nacional</option>
            <option value="international">Internacional</option>
          </select>
          <input name="amount" type="number" step="0.01" required placeholder="Monto" className="px-3 py-2 text-sm border border-neutral-300 rounded-lg" />
          <select name="currency" required className="px-3 py-2 text-sm border border-neutral-300 rounded-lg">
            <option value="MXN">MXN</option>
            <option value="USD">USD</option>
          </select>
          <button type="submit" disabled={saving} className="col-span-2 py-2 bg-neutral-900 text-white text-sm font-medium rounded-lg hover:bg-neutral-800 disabled:opacity-50">
            {saving ? "Guardando..." : "Registrar Venta"}
          </button>
        </form>
      )}

      {/* Sales table */}
      <div className="bg-white rounded-xl border border-neutral-200 overflow-hidden">
        <table className="w-full text-sm">
          <thead>
            <tr className="bg-neutral-50 border-b border-neutral-200">
              <th className="text-left px-4 py-3 font-medium text-neutral-600">Fecha</th>
              <th className="text-left px-4 py-3 font-medium text-neutral-600">Email</th>
              <th className="text-left px-4 py-3 font-medium text-neutral-600">Paquete</th>
              <th className="text-right px-4 py-3 font-medium text-neutral-600">Monto</th>
              <th className="text-left px-4 py-3 font-medium text-neutral-600">Estado</th>
            </tr>
          </thead>
          <tbody>
            {sales.map((sale) => (
              <tr key={sale.sale_uid} className="border-b border-neutral-100 last:border-0">
                <td className="px-4 py-3 text-neutral-600 text-xs">
                  {new Date(sale.sale_time).toLocaleDateString("es-MX")}
                </td>
                <td className="px-4 py-3 text-neutral-900">{sale.email}</td>
                <td className="px-4 py-3">
                  <span className="px-2 py-0.5 rounded-full text-xs font-medium bg-blue-50 text-blue-700">
                    {sale.package}
                  </span>
                </td>
                <td className="px-4 py-3 text-right text-neutral-900 font-mono">
                  ${sale.amount} {sale.currency}
                </td>
                <td className="px-4 py-3">
                  <span className={`text-xs font-medium ${sale.status === "Completado" ? "text-green-600" : "text-red-500"}`}>
                    {sale.status}
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {sales.length === 0 && (
          <p className="text-center py-8 text-sm text-neutral-400">Sin ventas registradas.</p>
        )}
      </div>
    </div>
  );
}

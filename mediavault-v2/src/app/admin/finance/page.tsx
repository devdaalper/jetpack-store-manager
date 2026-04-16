/**
 * Admin: Finance Overview
 */

import { getFinanceSummary, listSettlements, listExpenses } from "@/application/finance/manage-finance";

export default async function AdminFinancePage() {
  const [summary, { settlements }, { expenses }] = await Promise.all([
    getFinanceSummary(),
    listSettlements(20),
    listExpenses(20),
  ]);

  const fmtMxn = (n: number) =>
    new Intl.NumberFormat("es-MX", { style: "currency", currency: "MXN" }).format(n);

  return (
    <div>
      <h2 className="text-xl font-bold text-neutral-900 mb-6">Finanzas</h2>

      {/* Summary cards */}
      <div className="grid grid-cols-3 gap-4 mb-8">
        <div className="bg-white rounded-xl border border-neutral-200 p-4">
          <p className="text-xs text-neutral-500 mb-1">Ingresos netos</p>
          <p className="text-2xl font-bold text-green-700">{fmtMxn(summary.totalRevenueMxn)}</p>
          <p className="text-xs text-neutral-400 mt-1">{summary.settlementCount} liquidaciones</p>
        </div>
        <div className="bg-white rounded-xl border border-neutral-200 p-4">
          <p className="text-xs text-neutral-500 mb-1">Gastos</p>
          <p className="text-2xl font-bold text-red-600">{fmtMxn(summary.totalExpensesMxn)}</p>
          <p className="text-xs text-neutral-400 mt-1">{summary.expenseCount} registros</p>
        </div>
        <div className="bg-white rounded-xl border border-neutral-200 p-4">
          <p className="text-xs text-neutral-500 mb-1">Utilidad neta</p>
          <p className={`text-2xl font-bold ${summary.netIncomeMxn >= 0 ? "text-green-700" : "text-red-600"}`}>
            {fmtMxn(summary.netIncomeMxn)}
          </p>
        </div>
      </div>

      {/* Settlements */}
      <div className="mb-8">
        <h3 className="text-sm font-semibold text-neutral-900 mb-3">Liquidaciones recientes</h3>
        <div className="bg-white rounded-xl border border-neutral-200 overflow-hidden">
          <table className="w-full text-sm">
            <thead>
              <tr className="bg-neutral-50 border-b border-neutral-200">
                <th className="text-left px-4 py-2 font-medium text-neutral-600 text-xs">Fecha</th>
                <th className="text-left px-4 py-2 font-medium text-neutral-600 text-xs">Canal</th>
                <th className="text-right px-4 py-2 font-medium text-neutral-600 text-xs">Neto MXN</th>
                <th className="text-right px-4 py-2 font-medium text-neutral-600 text-xs">Ventas</th>
              </tr>
            </thead>
            <tbody>
              {settlements.map((s: Record<string, unknown>) => (
                <tr key={s["settlement_uid"] as string} className="border-b border-neutral-100 last:border-0">
                  <td className="px-4 py-2 text-xs text-neutral-600">{String(s["settlement_date"])}</td>
                  <td className="px-4 py-2 text-xs">{String(s["channel"])}</td>
                  <td className="px-4 py-2 text-xs text-right font-mono">{fmtMxn(Number(s["net_amount_mxn"] ?? 0))}</td>
                  <td className="px-4 py-2 text-xs text-right">{String(s["sales_count"])}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {settlements.length === 0 && <p className="text-center py-6 text-xs text-neutral-400">Sin liquidaciones.</p>}
        </div>
      </div>

      {/* Expenses */}
      <div>
        <h3 className="text-sm font-semibold text-neutral-900 mb-3">Gastos recientes</h3>
        <div className="bg-white rounded-xl border border-neutral-200 overflow-hidden">
          <table className="w-full text-sm">
            <thead>
              <tr className="bg-neutral-50 border-b border-neutral-200">
                <th className="text-left px-4 py-2 font-medium text-neutral-600 text-xs">Fecha</th>
                <th className="text-left px-4 py-2 font-medium text-neutral-600 text-xs">Categoría</th>
                <th className="text-left px-4 py-2 font-medium text-neutral-600 text-xs">Proveedor</th>
                <th className="text-right px-4 py-2 font-medium text-neutral-600 text-xs">Monto MXN</th>
              </tr>
            </thead>
            <tbody>
              {expenses.map((e: Record<string, unknown>) => (
                <tr key={e["expense_uid"] as string} className="border-b border-neutral-100 last:border-0">
                  <td className="px-4 py-2 text-xs text-neutral-600">{String(e["expense_date"])}</td>
                  <td className="px-4 py-2 text-xs">{String(e["category"])}</td>
                  <td className="px-4 py-2 text-xs">{String(e["vendor"])}</td>
                  <td className="px-4 py-2 text-xs text-right font-mono">{fmtMxn(Number(e["amount_mxn"] ?? 0))}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {expenses.length === 0 && <p className="text-center py-6 text-xs text-neutral-400">Sin gastos.</p>}
        </div>
      </div>
    </div>
  );
}

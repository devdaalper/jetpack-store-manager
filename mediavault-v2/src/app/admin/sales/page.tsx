/**
 * Admin: Sales Management
 */

import { listSales } from "@/application/sales/create-sale";
import { SalesManager } from "@/components/admin/sales-manager";

export default async function AdminSalesPage() {
  const { sales, total } = await listSales(1, 50);

  return (
    <div>
      <div className="flex items-center justify-between mb-6">
        <div>
          <h2 className="text-xl font-bold text-neutral-900">Ventas</h2>
          <p className="text-sm text-neutral-500 mt-1">{total} ventas registradas</p>
        </div>
      </div>

      <SalesManager initialSales={sales} />
    </div>
  );
}

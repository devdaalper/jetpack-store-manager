/**
 * Admin Layout — Tab navigation + admin guard.
 */

import { redirect } from "next/navigation";
import { getSessionUser } from "@/lib/auth";
import Link from "next/link";

const ADMIN_TABS = [
  { name: "Usuarios", href: "/admin/users" },
  { name: "Carpetas", href: "/admin/folders" },
  { name: "Ventas", href: "/admin/sales" },
  { name: "Finanzas", href: "/admin/finance" },
  { name: "Analytics", href: "/admin/analytics" },
  { name: "Sync", href: "/admin/sync" },
  { name: "Config", href: "/admin/settings" },
] as const;

export default async function AdminLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  const user = await getSessionUser();

  if (!user) redirect("/login?redirect=/admin/users");
  if (!user.isAdmin) redirect("/vault");

  return (
    <div className="min-h-screen bg-neutral-50">
      {/* Header */}
      <header className="bg-white border-b border-neutral-200">
        <div className="max-w-6xl mx-auto px-6">
          <div className="flex items-center justify-between h-14">
            <div className="flex items-center gap-4">
              <Link href="/vault" className="text-sm text-neutral-500 hover:text-orange-600 transition">
                ← Vault
              </Link>
              <h1 className="text-lg font-bold text-neutral-900">Admin</h1>
            </div>
            <span className="text-xs text-neutral-400">{user.email}</span>
          </div>

          {/* Tabs */}
          <nav className="flex gap-1 -mb-px">
            {ADMIN_TABS.map((tab) => (
              <Link
                key={tab.href}
                href={tab.href}
                className="px-4 py-2.5 text-sm font-medium text-neutral-600 hover:text-neutral-900 border-b-2 border-transparent hover:border-neutral-300 transition"
              >
                {tab.name}
              </Link>
            ))}
          </nav>
        </div>
      </header>

      {/* Content */}
      <main className="max-w-6xl mx-auto px-6 py-8">{children}</main>
    </div>
  );
}

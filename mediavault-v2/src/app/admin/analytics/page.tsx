/**
 * Admin: Analytics Dashboard
 */

import { getBehaviorReport, getTopDownloadedFolders } from "@/application/analytics/track-behavior";

export default async function AdminAnalyticsPage() {
  const today = new Date().toISOString().slice(0, 10);
  const monthStart = `${today.slice(0, 7)}-01`;

  const [behavior, topFolders] = await Promise.all([
    getBehaviorReport(monthStart, today),
    getTopDownloadedFolders(15),
  ]);

  // Aggregate events by name
  const eventCounts = new Map<string, number>();
  for (const row of behavior) {
    const existing = eventCounts.get(row.eventName) ?? 0;
    eventCounts.set(row.eventName, existing + row.count);
  }

  const sortedEvents = Array.from(eventCounts.entries())
    .sort((a, b) => b[1] - a[1]);

  return (
    <div>
      <h2 className="text-xl font-bold text-neutral-900 mb-2">Analytics</h2>
      <p className="text-sm text-neutral-500 mb-6">
        Periodo: {monthStart} — {today}
      </p>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Event summary */}
        <div>
          <h3 className="text-sm font-semibold text-neutral-900 mb-3">Eventos del mes</h3>
          <div className="bg-white rounded-xl border border-neutral-200 overflow-hidden">
            <table className="w-full text-sm">
              <thead>
                <tr className="bg-neutral-50 border-b border-neutral-200">
                  <th className="text-left px-4 py-2 font-medium text-neutral-600 text-xs">Evento</th>
                  <th className="text-right px-4 py-2 font-medium text-neutral-600 text-xs">Total</th>
                </tr>
              </thead>
              <tbody>
                {sortedEvents.map(([name, count]) => (
                  <tr key={name} className="border-b border-neutral-100 last:border-0">
                    <td className="px-4 py-2 text-xs font-mono text-neutral-700">{name}</td>
                    <td className="px-4 py-2 text-xs text-right font-bold text-neutral-900">{count.toLocaleString()}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            {sortedEvents.length === 0 && (
              <p className="text-center py-6 text-xs text-neutral-400">Sin eventos este mes.</p>
            )}
          </div>
        </div>

        {/* Top folders */}
        <div>
          <h3 className="text-sm font-semibold text-neutral-900 mb-3">Top carpetas descargadas</h3>
          <div className="bg-white rounded-xl border border-neutral-200 overflow-hidden">
            <table className="w-full text-sm">
              <thead>
                <tr className="bg-neutral-50 border-b border-neutral-200">
                  <th className="text-left px-4 py-2 font-medium text-neutral-600 text-xs">#</th>
                  <th className="text-left px-4 py-2 font-medium text-neutral-600 text-xs">Carpeta</th>
                  <th className="text-right px-4 py-2 font-medium text-neutral-600 text-xs">Descargas</th>
                </tr>
              </thead>
              <tbody>
                {topFolders.map((folder, i) => (
                  <tr key={folder.folderPath} className="border-b border-neutral-100 last:border-0">
                    <td className="px-4 py-2 text-xs text-neutral-400 font-medium">{i + 1}</td>
                    <td className="px-4 py-2 text-xs text-neutral-900">{folder.folderName || folder.folderPath}</td>
                    <td className="px-4 py-2 text-xs text-right font-bold">{folder.downloadCount}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            {topFolders.length === 0 && (
              <p className="text-center py-6 text-xs text-neutral-400">Sin descargas registradas.</p>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

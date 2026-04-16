/**
 * Admin: User Tier Management
 */

import { createServiceClient } from "@/infrastructure/supabase/server";
import { UserTierManager } from "@/components/admin/user-tier-manager";
import { TIER_NAMES } from "@/domain/types";

export default async function AdminUsersPage() {
  const supabase = createServiceClient();

  const { data: users } = await supabase
    .from("profiles")
    .select("id, email, tier, is_admin, created_at, updated_at")
    .order("updated_at", { ascending: false })
    .limit(100);

  const usersWithLabels = (users ?? []).map((u) => ({
    ...u,
    tierName: TIER_NAMES[u.tier] ?? `Tier ${u.tier}`,
  }));

  return (
    <div>
      <div className="flex items-center justify-between mb-6">
        <div>
          <h2 className="text-xl font-bold text-neutral-900">Usuarios</h2>
          <p className="text-sm text-neutral-500 mt-1">
            {usersWithLabels.length} usuarios registrados
          </p>
        </div>
      </div>

      <UserTierManager users={usersWithLabels} />
    </div>
  );
}

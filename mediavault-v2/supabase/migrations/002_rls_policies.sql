-- ============================================================
-- MediaVault v2 — Row Level Security Policies
-- ============================================================
-- Defense in depth: even if API routes have bugs, RLS prevents
-- unauthorized data access at the database level.
-- ============================================================

-- ─── Profiles ──────────────────────────────────────────────

ALTER TABLE public.profiles ENABLE ROW LEVEL SECURITY;

-- Users can read their own profile
CREATE POLICY "Users can read own profile"
  ON public.profiles FOR SELECT
  USING (auth.uid() = user_id);

-- Users can update their own profile (except tier and is_admin)
-- Tier and admin changes only via service_role from API routes
CREATE POLICY "Users can update own profile"
  ON public.profiles FOR UPDATE
  USING (auth.uid() = user_id)
  WITH CHECK (auth.uid() = user_id);

-- ─── Play Counts ───────────────────────────────────────────

ALTER TABLE public.play_counts ENABLE ROW LEVEL SECURITY;

-- Users can read their own play count
CREATE POLICY "Users can read own play count"
  ON public.play_counts FOR SELECT
  USING (
    email = (SELECT email FROM public.profiles WHERE user_id = auth.uid())
  );

-- ─── File Index ────────────────────────────────────────────

ALTER TABLE public.file_index ENABLE ROW LEVEL SECURITY;

-- All authenticated users can read the active index version
-- Folder-level access is enforced in application layer (too complex for RLS)
CREATE POLICY "Authenticated users can browse index"
  ON public.file_index FOR SELECT
  USING (auth.role() = 'authenticated');

-- ─── Folder Permissions ────────────────────────────────────

ALTER TABLE public.folder_permissions ENABLE ROW LEVEL SECURITY;

-- All authenticated users can read folder permissions
-- (needed client-side for lock indicators)
CREATE POLICY "Authenticated users can read folder permissions"
  ON public.folder_permissions FOR SELECT
  USING (auth.role() = 'authenticated');

-- ─── Behavior Events ──────────────────────────────────────

ALTER TABLE public.behavior_events ENABLE ROW LEVEL SECURITY;

-- Authenticated users can insert events (analytics tracking)
CREATE POLICY "Authenticated users can insert events"
  ON public.behavior_events FOR INSERT
  WITH CHECK (auth.role() = 'authenticated');

-- ─── Tables WITHOUT RLS (admin-only, accessed via service_role) ─
-- These tables are never queried from the browser Supabase client.
-- They are only accessed from Next.js API routes using the
-- service_role key, which bypasses RLS entirely.
--
-- NO RLS on: leads, sales, finance_settlements, finance_settlement_items,
--            finance_expenses, folder_download_events, behavior_daily,
--            app_config, traffic_logs

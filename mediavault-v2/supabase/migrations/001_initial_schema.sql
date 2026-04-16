-- ============================================================
-- MediaVault v2 — Initial Database Schema
-- ============================================================
-- This migration creates all tables for the MediaVault system.
-- Run via: supabase db push
-- ============================================================

-- ─── User Profiles ──────────────────────────────────────────

CREATE TABLE public.profiles (
  id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id     UUID UNIQUE REFERENCES auth.users(id) ON DELETE CASCADE,
  email       TEXT NOT NULL UNIQUE,
  tier        SMALLINT NOT NULL DEFAULT 0 CHECK (tier BETWEEN 0 AND 5),
  is_admin    BOOLEAN NOT NULL DEFAULT false,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_profiles_user_id ON public.profiles(user_id);
CREATE INDEX idx_profiles_tier ON public.profiles(tier);

-- ─── Leads (Demo User Registration) ────────────────────────

CREATE TABLE public.leads (
  id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  email         TEXT NOT NULL UNIQUE,
  registered_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  source        TEXT NOT NULL DEFAULT ''
);

CREATE INDEX idx_leads_registered ON public.leads(registered_at);

-- ─── Play Counts (Demo Playback Limits) ────────────────────

CREATE TABLE public.play_counts (
  id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  email       TEXT NOT NULL UNIQUE,
  play_count  INT NOT NULL DEFAULT 0,
  month       TEXT NOT NULL DEFAULT to_char(now(), 'YYYY-MM'),
  updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ─── File Index (Catalog from B2) ──────────────────────────

CREATE TABLE public.file_index (
  id            BIGSERIAL PRIMARY KEY,
  version       INT NOT NULL DEFAULT 1,
  path          TEXT NOT NULL,
  path_hash     TEXT NOT NULL,
  path_norm     TEXT NOT NULL DEFAULT '',
  name          TEXT NOT NULL DEFAULT '',
  name_norm     TEXT NOT NULL DEFAULT '',
  folder        TEXT NOT NULL DEFAULT '',
  folder_norm   TEXT NOT NULL DEFAULT '',
  size          BIGINT NOT NULL DEFAULT 0,
  extension     TEXT NOT NULL DEFAULT '',
  media_kind    TEXT NOT NULL DEFAULT 'other',
  last_modified TIMESTAMPTZ,
  etag          TEXT NOT NULL DEFAULT '',
  depth         INT NOT NULL DEFAULT 0,
  synced_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE(version, path_hash)
);

CREATE INDEX idx_file_index_version_folder ON public.file_index(version, folder);
CREATE INDEX idx_file_index_version_name ON public.file_index(version, name_norm);
CREATE INDEX idx_file_index_version_media ON public.file_index(version, media_kind);
CREATE INDEX idx_file_index_version_ext ON public.file_index(version, extension);

-- ─── Folder Permissions ────────────────────────────────────

CREATE TABLE public.folder_permissions (
  id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  folder_path   TEXT NOT NULL UNIQUE,
  allowed_tiers SMALLINT[] NOT NULL DEFAULT '{}',
  updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ─── Sales ─────────────────────────────────────────────────

CREATE TABLE public.sales (
  id          BIGSERIAL PRIMARY KEY,
  sale_uid    TEXT NOT NULL UNIQUE,
  sale_time   TIMESTAMPTZ NOT NULL,
  email       TEXT NOT NULL,
  package     TEXT NOT NULL,
  region      TEXT NOT NULL DEFAULT '',
  amount      DECIMAL(12,2) NOT NULL DEFAULT 0,
  currency    TEXT NOT NULL DEFAULT 'MXN',
  status      TEXT NOT NULL DEFAULT 'Completado',
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_sales_email_time ON public.sales(email, sale_time);
CREATE INDEX idx_sales_time ON public.sales(sale_time);

-- ─── Finance: Settlements ──────────────────────────────────

CREATE TABLE public.finance_settlements (
  id              BIGSERIAL PRIMARY KEY,
  settlement_uid  TEXT NOT NULL UNIQUE,
  settlement_date DATE NOT NULL,
  market          TEXT NOT NULL DEFAULT '',
  channel         TEXT NOT NULL DEFAULT '',
  currency        TEXT NOT NULL DEFAULT 'MXN',
  gross_amount    DECIMAL(14,2) NOT NULL DEFAULT 0,
  fee_amount      DECIMAL(14,2) NOT NULL DEFAULT 0,
  net_amount      DECIMAL(14,2) NOT NULL DEFAULT 0,
  fx_rate         DECIMAL(14,6),
  net_amount_mxn  DECIMAL(14,2),
  sales_count     INT NOT NULL DEFAULT 0,
  bank_account    TEXT DEFAULT '',
  external_ref    TEXT DEFAULT '',
  notes           TEXT DEFAULT '',
  status          TEXT NOT NULL DEFAULT 'confirmed',
  created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ─── Finance: Settlement Items ─────────────────────────────

CREATE TABLE public.finance_settlement_items (
  id              BIGSERIAL PRIMARY KEY,
  item_uid        TEXT NOT NULL UNIQUE,
  settlement_uid  TEXT NOT NULL REFERENCES public.finance_settlements(settlement_uid) ON DELETE CASCADE,
  sale_uid        TEXT,
  sale_time       TIMESTAMPTZ,
  email           TEXT,
  package         TEXT,
  region          TEXT,
  gross_amount    DECIMAL(14,2) NOT NULL DEFAULT 0,
  fee_amount      DECIMAL(14,2) NOT NULL DEFAULT 0,
  net_amount      DECIMAL(14,2) NOT NULL DEFAULT 0,
  currency        TEXT NOT NULL DEFAULT 'MXN',
  created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ─── Finance: Expenses ─────────────────────────────────────

CREATE TABLE public.finance_expenses (
  id            BIGSERIAL PRIMARY KEY,
  expense_uid   TEXT NOT NULL UNIQUE,
  expense_date  DATE NOT NULL,
  category      TEXT NOT NULL DEFAULT '',
  vendor        TEXT DEFAULT '',
  description   TEXT DEFAULT '',
  amount        DECIMAL(14,2) NOT NULL DEFAULT 0,
  currency      TEXT NOT NULL DEFAULT 'MXN',
  fx_rate       DECIMAL(14,6),
  amount_mxn    DECIMAL(14,2),
  account_label TEXT DEFAULT '',
  notes         TEXT DEFAULT '',
  status        TEXT NOT NULL DEFAULT 'confirmed',
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ─── Analytics: Folder Download Events ─────────────────────

CREATE TABLE public.folder_download_events (
  id            BIGSERIAL PRIMARY KEY,
  folder_path   TEXT NOT NULL,
  folder_name   TEXT NOT NULL DEFAULT '',
  downloaded_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_fde_path_time ON public.folder_download_events(folder_path, downloaded_at);

-- ─── Analytics: Behavior Events ────────────────────────────

CREATE TABLE public.behavior_events (
  id                BIGSERIAL PRIMARY KEY,
  event_uuid        TEXT NOT NULL UNIQUE,
  event_time        TIMESTAMPTZ NOT NULL DEFAULT now(),
  event_name        TEXT NOT NULL,
  session_id_hash   TEXT,
  user_id_hash      TEXT,
  tier              SMALLINT,
  region            TEXT,
  device_class      TEXT,
  query_norm        TEXT,
  object_path_norm  TEXT,
  status            TEXT,
  files_count       INT,
  bytes_authorized  BIGINT,
  bytes_observed    BIGINT,
  result_count      INT,
  object_type       TEXT,
  meta_json         JSONB,
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_be_event_time ON public.behavior_events(event_name, event_time);
CREATE INDEX idx_be_event_date ON public.behavior_events(event_time);

-- ─── Analytics: Behavior Daily (Aggregates) ────────────────

CREATE TABLE public.behavior_daily (
  id                      BIGSERIAL PRIMARY KEY,
  day_date                DATE NOT NULL,
  metric_key              TEXT NOT NULL,
  dimension_hash          TEXT NOT NULL,
  query_norm              TEXT,
  object_path_norm        TEXT,
  tier                    SMALLINT,
  region                  TEXT,
  device_class            TEXT,
  metric_count            BIGINT NOT NULL DEFAULT 0,
  metric_bytes_authorized BIGINT DEFAULT 0,
  metric_bytes_observed   BIGINT DEFAULT 0,
  updated_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE(day_date, metric_key, dimension_hash)
);

CREATE INDEX idx_bd_day ON public.behavior_daily(day_date);

-- ─── App Config (Key-Value) ────────────────────────────────

CREATE TABLE public.app_config (
  key        TEXT PRIMARY KEY,
  value      JSONB NOT NULL DEFAULT '{}'::jsonb,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ─── Traffic Logs ──────────────────────────────────────────

CREATE TABLE public.traffic_logs (
  id            BIGSERIAL PRIMARY KEY,
  user_email    TEXT,
  file_name     TEXT NOT NULL,
  file_size     BIGINT DEFAULT 0,
  download_date TIMESTAMPTZ NOT NULL DEFAULT now(),
  ip_address    TEXT
);

CREATE INDEX idx_traffic_user_date ON public.traffic_logs(user_email, download_date);

-- ─── Seed: Initial Config ──────────────────────────────────

INSERT INTO public.app_config (key, value) VALUES
  ('active_index_version', '1'::jsonb),
  ('cloudflare_domain', '""'::jsonb),
  ('whatsapp_number', '""'::jsonb),
  ('sidebar_order', '[]'::jsonb);

-- ─── Auto-create profile on auth signup ────────────────────

CREATE OR REPLACE FUNCTION public.handle_new_user()
RETURNS TRIGGER AS $$
BEGIN
  INSERT INTO public.profiles (user_id, email, tier, is_admin)
  VALUES (NEW.id, LOWER(NEW.email), 0, false)
  ON CONFLICT (email) DO UPDATE SET user_id = NEW.id;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

CREATE TRIGGER on_auth_user_created
  AFTER INSERT ON auth.users
  FOR EACH ROW
  EXECUTE FUNCTION public.handle_new_user();

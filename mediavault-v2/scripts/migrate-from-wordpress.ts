/**
 * Data Migration: WordPress → Supabase
 *
 * One-time script to migrate data from the WordPress MySQL database
 * to Supabase PostgreSQL. Run this AFTER creating the Supabase project
 * and running the SQL migrations.
 *
 * Prerequisites:
 *   1. Export WordPress tables to CSV using WP-CLI or phpMyAdmin:
 *      - wp_jpsm_sales → data/sales.csv
 *      - wp_jpsm_user_tiers → data/user_tiers.csv
 *      - wp_jpsm_leads → data/leads.csv
 *      - wp_jpsm_play_counts → data/play_counts.csv
 *      - wp_jpsm_finance_settlements → data/settlements.csv
 *      - wp_jpsm_finance_expenses → data/expenses.csv
 *      - wp_jpsm_behavior_daily → data/behavior_daily.csv
 *
 *   2. Export wp_options for folder permissions:
 *      SELECT option_value FROM wp_options WHERE option_name = 'jpsm_folder_permissions'
 *      Save the JSON to data/folder_permissions.json
 *
 *   3. Set environment variables:
 *      SUPABASE_URL=https://your-project.supabase.co
 *      SUPABASE_SERVICE_ROLE_KEY=your-service-role-key
 *
 * Usage:
 *   npx tsx scripts/migrate-from-wordpress.ts
 *
 * The file_index does NOT need migration — run the sync from the admin
 * panel to rebuild it from B2 directly.
 */

import { createClient } from "@supabase/supabase-js";
import { readFileSync, existsSync } from "fs";
import { resolve } from "path";

const SUPABASE_URL = process.env["SUPABASE_URL"] ?? "";
const SUPABASE_KEY = process.env["SUPABASE_SERVICE_ROLE_KEY"] ?? "";

if (!SUPABASE_URL || !SUPABASE_KEY) {
  console.error("ERROR: Set SUPABASE_URL and SUPABASE_SERVICE_ROLE_KEY");
  process.exit(1);
}

const supabase = createClient(SUPABASE_URL, SUPABASE_KEY, {
  auth: { persistSession: false },
});

const DATA_DIR = resolve(__dirname, "../data");

// ─── Helpers ────────────────────────────────────────────────────────

function readCsv(filename: string): Record<string, string>[] {
  const path = resolve(DATA_DIR, filename);
  if (!existsSync(path)) {
    console.warn(`  SKIP: ${filename} not found`);
    return [];
  }

  const content = readFileSync(path, "utf-8");
  const lines = content.trim().split("\n");
  const headers = lines[0]?.split(",").map((h) => h.trim().replace(/"/g, "")) ?? [];

  return lines.slice(1).map((line) => {
    const values = parseCsvLine(line);
    const row: Record<string, string> = {};
    headers.forEach((h, i) => {
      row[h] = values[i] ?? "";
    });
    return row;
  });
}

function parseCsvLine(line: string): string[] {
  const values: string[] = [];
  let current = "";
  let inQuotes = false;

  for (const char of line) {
    if (char === '"') {
      inQuotes = !inQuotes;
    } else if (char === "," && !inQuotes) {
      values.push(current.trim());
      current = "";
    } else {
      current += char;
    }
  }
  values.push(current.trim());
  return values;
}

function readJson(filename: string): unknown {
  const path = resolve(DATA_DIR, filename);
  if (!existsSync(path)) {
    console.warn(`  SKIP: ${filename} not found`);
    return null;
  }
  return JSON.parse(readFileSync(path, "utf-8"));
}

// ─── Migrations ─────────────────────────────────────────────────────

async function migrateSales() {
  console.log("\n📦 Migrating sales...");
  const rows = readCsv("sales.csv");
  if (rows.length === 0) return;

  const data = rows.map((r) => ({
    sale_uid: r["sale_uid"] ?? `legacy_${r["id"]}`,
    sale_time: r["sale_time"] ?? r["time"] ?? new Date().toISOString(),
    email: (r["email"] ?? "").toLowerCase(),
    package: r["package"] ?? "",
    region: r["region"] ?? "",
    amount: Number(r["amount"] ?? 0),
    currency: r["currency"] ?? "MXN",
    status: r["status"] ?? "Completado",
  }));

  const { error } = await supabase.from("sales").upsert(data, { onConflict: "sale_uid" });
  if (error) console.error("  ERROR:", error.message);
  else console.log(`  ✅ ${data.length} sales migrated`);
}

async function migrateUserTiers() {
  console.log("\n👤 Migrating user tiers...");
  const rows = readCsv("user_tiers.csv");
  if (rows.length === 0) return;

  const data = rows.map((r) => ({
    email: (r["email"] ?? "").toLowerCase(),
    tier: Number(r["tier"] ?? 0),
    is_admin: false,
  }));

  // Upsert profiles (without user_id — linked on first login)
  const { error } = await supabase.from("profiles").upsert(data, { onConflict: "email" });
  if (error) console.error("  ERROR:", error.message);
  else console.log(`  ✅ ${data.length} user tiers migrated`);
}

async function migrateLeads() {
  console.log("\n📋 Migrating leads...");
  const rows = readCsv("leads.csv");
  if (rows.length === 0) return;

  const data = rows.map((r) => ({
    email: (r["email"] ?? "").toLowerCase(),
    registered_at: r["registered_at"] ?? new Date().toISOString(),
    source: r["source"] ?? "wordpress_migration",
  }));

  const { error } = await supabase.from("leads").upsert(data, { onConflict: "email" });
  if (error) console.error("  ERROR:", error.message);
  else console.log(`  ✅ ${data.length} leads migrated`);
}

async function migrateFolderPermissions() {
  console.log("\n📁 Migrating folder permissions...");
  const perms = readJson("folder_permissions.json") as Record<string, number[]> | null;
  if (!perms) return;

  const data = Object.entries(perms).map(([path, tiers]) => ({
    folder_path: path,
    allowed_tiers: tiers,
  }));

  const { error } = await supabase
    .from("folder_permissions")
    .upsert(data, { onConflict: "folder_path" });

  if (error) console.error("  ERROR:", error.message);
  else console.log(`  ✅ ${data.length} folder permissions migrated`);
}

async function migratePlayCounts() {
  console.log("\n🎵 Migrating play counts...");
  const rows = readCsv("play_counts.csv");
  if (rows.length === 0) return;

  const currentMonth = new Date().toISOString().slice(0, 7);
  const data = rows.map((r) => ({
    email: (r["email"] ?? "").toLowerCase(),
    play_count: Number(r["play_count"] ?? 0),
    month: currentMonth,
  }));

  const { error } = await supabase.from("play_counts").upsert(data, { onConflict: "email" });
  if (error) console.error("  ERROR:", error.message);
  else console.log(`  ✅ ${data.length} play counts migrated`);
}

// ─── Main ───────────────────────────────────────────────────────────

async function main() {
  console.log("═══════════════════════════════════════");
  console.log("  MediaVault: WordPress → Supabase");
  console.log("═══════════════════════════════════════");
  console.log(`  Target: ${SUPABASE_URL}`);
  console.log(`  Data dir: ${DATA_DIR}`);

  if (!existsSync(DATA_DIR)) {
    console.log(`\n  ⚠️  Create ${DATA_DIR}/ and add CSV exports first.`);
    console.log("  See script header for instructions.\n");
    process.exit(1);
  }

  await migrateSales();
  await migrateUserTiers();
  await migrateLeads();
  await migrateFolderPermissions();
  await migratePlayCounts();

  console.log("\n═══════════════════════════════════════");
  console.log("  Migration complete!");
  console.log("  Next: Run index sync from Admin > Sync");
  console.log("═══════════════════════════════════════\n");
}

main().catch((err) => {
  console.error("Migration failed:", err);
  process.exit(1);
});

/**
 * Environment variable access with validation.
 * Fails fast at startup if required vars are missing.
 */

function requireEnv(name: string): string {
  const value = process.env[name];
  if (!value) {
    throw new Error(`Missing required environment variable: ${name}`);
  }
  return value;
}

function optionalEnv(name: string, fallback = ""): string {
  return process.env[name] ?? fallback;
}

/** Server-only config — never import from client components */
export const serverConfig = {
  supabase: {
    url: requireEnv("NEXT_PUBLIC_SUPABASE_URL"),
    serviceRoleKey: requireEnv("SUPABASE_SERVICE_ROLE_KEY"),
  },
  b2: {
    keyId: requireEnv("B2_KEY_ID"),
    appKey: requireEnv("B2_APP_KEY"),
    region: requireEnv("B2_REGION"),
    bucket: requireEnv("B2_BUCKET"),
  },
  cloudflare: {
    domain: optionalEnv("CLOUDFLARE_DOMAIN"),
  },
  resend: {
    apiKey: optionalEnv("RESEND_API_KEY"),
  },
} as const;

/** Public config — safe to use in client components */
export const publicConfig = {
  supabaseUrl: process.env["NEXT_PUBLIC_SUPABASE_URL"] ?? "",
  supabaseAnonKey: process.env["NEXT_PUBLIC_SUPABASE_ANON_KEY"] ?? "",
} as const;

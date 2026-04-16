/**
 * Presigned URL generation for Backblaze B2.
 *
 * Uses AWS SDK v3 request presigner — handles Sig v4 automatically.
 * Server-only — never import from client components.
 */

import { GetObjectCommand } from "@aws-sdk/client-s3";
import { getSignedUrl } from "@aws-sdk/s3-request-presigner";
import { s3Client, B2_BUCKET_NAME } from "./s3-client";
import { PRESIGNED_URL_TTL } from "@/domain/types";

const CLOUDFLARE_DOMAIN = process.env["CLOUDFLARE_DOMAIN"] ?? "";

/**
 * Generate a presigned URL for downloading a file.
 *
 * @param filePath - S3 object key
 * @param intent - "download" (attachment) or "preview" (inline)
 * @returns Presigned URL (optionally rewritten for Cloudflare CDN)
 */
export async function generatePresignedUrl(
  filePath: string,
  intent: "download" | "preview" = "download",
): Promise<string> {
  const ttl =
    intent === "preview"
      ? PRESIGNED_URL_TTL.preview
      : PRESIGNED_URL_TTL.download;

  const disposition =
    intent === "download"
      ? `attachment; filename="${encodeURIComponent(getFilename(filePath))}"`
      : "inline";

  const command = new GetObjectCommand({
    Bucket: B2_BUCKET_NAME,
    Key: filePath,
    ResponseContentDisposition: disposition,
  });

  const url = await getSignedUrl(s3Client, command, { expiresIn: ttl });

  return rewriteForCloudflare(url);
}

/**
 * Extract filename from a full S3 path.
 */
function getFilename(path: string): string {
  const parts = path.split("/");
  return parts[parts.length - 1] ?? path;
}

/**
 * If a Cloudflare domain is configured, rewrite the B2 URL
 * to go through Cloudflare CDN for better global performance.
 */
function rewriteForCloudflare(url: string): string {
  if (!CLOUDFLARE_DOMAIN) return url;

  try {
    const parsed = new URL(url);
    const cfUrl = new URL(url);
    cfUrl.hostname = new URL(CLOUDFLARE_DOMAIN).hostname;
    cfUrl.protocol = "https:";
    cfUrl.port = "";
    // Preserve path and query (includes the signature)
    cfUrl.pathname = parsed.pathname;
    cfUrl.search = parsed.search;
    return cfUrl.toString();
  } catch {
    // If rewrite fails, return original URL
    return url;
  }
}

/**
 * Backblaze B2 S3-compatible client.
 *
 * Uses the official AWS SDK v3 (works with any S3-compatible service).
 * Server-only — never import from client components.
 */

import {
  S3Client,
  ListObjectsV2Command,
  type _Object,
} from "@aws-sdk/client-s3";

const B2_KEY_ID = process.env["B2_KEY_ID"] ?? "";
const B2_APP_KEY = process.env["B2_APP_KEY"] ?? "";
const B2_REGION = process.env["B2_REGION"] ?? "us-west-004";
const B2_BUCKET = process.env["B2_BUCKET"] ?? "";

/** Singleton S3 client for the B2 bucket */
export const s3Client = new S3Client({
  endpoint: `https://s3.${B2_REGION}.backblazeb2.com`,
  region: B2_REGION,
  credentials: {
    accessKeyId: B2_KEY_ID,
    secretAccessKey: B2_APP_KEY,
  },
});

export const B2_BUCKET_NAME = B2_BUCKET;

// ─── Types ──────────────────────────────────────────────────────────

export interface S3Object {
  key: string;
  size: number;
  lastModified: Date | undefined;
  etag: string;
}

export interface S3ListResult {
  objects: S3Object[];
  nextContinuationToken: string | undefined;
  isTruncated: boolean;
}

// ─── List Objects ───────────────────────────────────────────────────

/**
 * List objects in the B2 bucket under a given prefix.
 * Supports pagination via continuation token.
 *
 * @param prefix - Folder prefix (e.g., "Music/Rock/")
 * @param continuationToken - Token from previous page
 * @param maxKeys - Max objects per page (default 1000)
 */
export async function listObjects(
  prefix: string,
  continuationToken?: string,
  maxKeys = 1000,
): Promise<S3ListResult> {
  const command = new ListObjectsV2Command({
    Bucket: B2_BUCKET_NAME,
    Prefix: prefix,
    MaxKeys: maxKeys,
    ContinuationToken: continuationToken,
  });

  const response = await s3Client.send(command);

  const objects: S3Object[] = (response.Contents ?? [])
    .filter((obj: _Object): obj is _Object & { Key: string } => obj.Key != null)
    .map((obj) => ({
      key: obj.Key,
      size: obj.Size ?? 0,
      lastModified: obj.LastModified,
      etag: (obj.ETag ?? "").replace(/"/g, ""),
    }));

  return {
    objects,
    nextContinuationToken: response.NextContinuationToken,
    isTruncated: response.IsTruncated ?? false,
  };
}

/**
 * List ALL objects under a prefix (auto-paginating).
 * Use with caution — can be slow for large prefixes.
 */
export async function listAllObjects(prefix: string): Promise<S3Object[]> {
  const allObjects: S3Object[] = [];
  let continuationToken: string | undefined;

  do {
    const result = await listObjects(prefix, continuationToken);
    allObjects.push(...result.objects);
    continuationToken = result.nextContinuationToken;
  } while (continuationToken);

  return allObjects;
}

/**
 * List immediate children (folders + files) of a prefix.
 * Uses Delimiter="/" to get CommonPrefixes (folders) and direct files.
 */
export async function listFolderContents(prefix: string): Promise<{
  folders: string[];
  files: S3Object[];
}> {
  const command = new ListObjectsV2Command({
    Bucket: B2_BUCKET_NAME,
    Prefix: prefix,
    Delimiter: "/",
    MaxKeys: 1000,
  });

  const response = await s3Client.send(command);

  const folders = (response.CommonPrefixes ?? [])
    .map((cp) => cp.Prefix)
    .filter((p): p is string => p != null);

  const files: S3Object[] = (response.Contents ?? [])
    .filter((obj): obj is _Object & { Key: string } => {
      // Exclude the prefix itself (folder marker)
      return obj.Key != null && obj.Key !== prefix;
    })
    .map((obj) => ({
      key: obj.Key,
      size: obj.Size ?? 0,
      lastModified: obj.LastModified,
      etag: (obj.ETag ?? "").replace(/"/g, ""),
    }));

  return { folders, files };
}

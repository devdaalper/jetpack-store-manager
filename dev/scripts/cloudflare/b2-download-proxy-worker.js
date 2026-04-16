/**
 * Cloudflare Worker - Backblaze B2 signed-download proxy
 *
 * Required env vars:
 * - B2_REGION (e.g. us-west-004)
 * - B2_BUCKET (exact bucket name)
 *
 * Optional env vars:
 * - CORS_ALLOW_ORIGIN (default "*")
 */

const ALLOWED_METHODS = new Set(['GET', 'HEAD', 'OPTIONS']);

export default {
  async fetch(request, env) {
    const method = (request.method || '').toUpperCase();
    if (!ALLOWED_METHODS.has(method)) {
      return withCors(new Response('Method Not Allowed', { status: 405 }), env);
    }

    if (method === 'OPTIONS') {
      return withCors(new Response(null, { status: 204 }), env);
    }

    const region = String(env.B2_REGION || '').trim();
    const bucket = String(env.B2_BUCKET || '').trim();
    if (!region || !bucket) {
      return withCors(new Response('Worker misconfigured', { status: 500 }), env);
    }

    const incoming = new URL(request.url);

    // Only allow presigned requests.
    if (!incoming.searchParams.has('X-Amz-Signature')) {
      return withCors(new Response('Missing signature', { status: 403 }), env);
    }

    // Path must stay under /<bucket>/ for private bucket safety.
    const rawPrefix = `/${bucket}/`;
    const encodedPrefix = `/${encodeURIComponent(bucket)}/`;
    if (!incoming.pathname.startsWith(rawPrefix) && !incoming.pathname.startsWith(encodedPrefix)) {
      return withCors(new Response('Not Found', { status: 404 }), env);
    }

    const upstream = new URL(`https://s3.${region}.backblazeb2.com${incoming.pathname}${incoming.search}`);

    const forwardedHeaders = new Headers();
    copyIfPresent(request.headers, forwardedHeaders, 'range');
    copyIfPresent(request.headers, forwardedHeaders, 'if-none-match');
    copyIfPresent(request.headers, forwardedHeaders, 'if-modified-since');

    const upstreamResp = await fetch(upstream.toString(), {
      method,
      headers: forwardedHeaders,
      redirect: 'manual',
    });

    const headers = new Headers();
    preserveIfPresent(upstreamResp.headers, headers, 'content-type');
    preserveIfPresent(upstreamResp.headers, headers, 'content-length');
    preserveIfPresent(upstreamResp.headers, headers, 'content-disposition');
    preserveIfPresent(upstreamResp.headers, headers, 'content-range');
    preserveIfPresent(upstreamResp.headers, headers, 'accept-ranges');
    preserveIfPresent(upstreamResp.headers, headers, 'etag');
    preserveIfPresent(upstreamResp.headers, headers, 'last-modified');
    preserveIfPresent(upstreamResp.headers, headers, 'cache-control');

    const response = new Response(upstreamResp.body, {
      status: upstreamResp.status,
      statusText: upstreamResp.statusText,
      headers,
    });

    return withCors(response, env);
  },
};

function copyIfPresent(source, target, name) {
  const value = source.get(name);
  if (value !== null && value !== '') {
    target.set(name, value);
  }
}

function preserveIfPresent(source, target, name) {
  const value = source.get(name);
  if (value !== null && value !== '') {
    target.set(name, value);
  }
}

function withCors(response, env) {
  const corsOrigin = String(env.CORS_ALLOW_ORIGIN || '*').trim() || '*';
  const headers = new Headers(response.headers);
  headers.set('Access-Control-Allow-Origin', corsOrigin);
  headers.set('Access-Control-Allow-Methods', 'GET, HEAD, OPTIONS');
  headers.set('Access-Control-Allow-Headers', 'Range, Content-Type');
  headers.set('Access-Control-Expose-Headers', 'Content-Length, Content-Range, Content-Disposition, Content-Type, Accept-Ranges, ETag');

  return new Response(response.body, {
    status: response.status,
    statusText: response.statusText,
    headers,
  });
}

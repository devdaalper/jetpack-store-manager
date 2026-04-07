# Guia Asistida: Backblaze + Cloudflare para Descargas (MediaVault)

Fecha: 2026-02-17  
Sitio objetivo: `https://jetpackstore.net/descargas/`

## 1. Resumen simple

Si, te entendiste bien: la idea es pasar las descargas por Cloudflare para aprovechar el beneficio de transferencia con Backblaze B2 y bajar costos de egreso.

La forma segura de hacerlo es en 3 fases:

1. Probar primero con `workers.dev` (sin tocar DNS global del dominio).
2. Migrar DNS del dominio a Cloudflare con checklist.
3. Pasar de `workers.dev` al subdominio final (`downloads.jetpackstore.net`).

## 2. Regla de seguridad (muy importante)

1. No cambies nameservers al inicio.
2. Primero valida que el flujo funcione en `workers.dev`.
3. Si algo falla: borra el valor de `Cloudflare Domain` en WordPress y vuelves a B2 directo.

---

## 3. Fase 1 (sin tocar DNS): prueba real

### Paso A: Crear Worker en Cloudflare

1. Entra a Cloudflare.
2. Ve a `Workers & Pages`.
3. Crea un Worker nuevo, por ejemplo: `jpsm-b2-proxy`.
4. Borra el contenido y pega este codigo:

```javascript
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
```

### Paso B: Variables del Worker (copiar/pegar)

En `Settings -> Variables` agrega:

```text
B2_REGION = us-west-004
B2_BUCKET = TU_BUCKET_REAL
CORS_ALLOW_ORIGIN = https://jetpackstore.net
```

Notas:

1. `B2_REGION` y `B2_BUCKET` deben coincidir con tu configuracion actual de WordPress.
2. Si tu region no es `us-west-004`, usa la que tengas configurada.

### Paso C: Publicar

1. Pulsa `Deploy`.
2. Copia la URL del worker, ejemplo:  
   `https://jpsm-b2-proxy.tu-subdominio.workers.dev`

### Paso D: Conectar en WordPress

1. En WordPress ve a `MediaVault Manager -> Configuracion`.
2. En `Cloudflare Domain` pega solo el origen, por ejemplo:  
   `https://jpsm-b2-proxy.tu-subdominio.workers.dev`
3. Guarda cambios.

Importante:

1. No pongas ruta (`/algo`), ni query (`?x=1`), ni fragmento (`#x`).
2. Solo origen HTTPS.

### Paso E: Pruebas funcionales

1. Abre `https://jetpackstore.net/descargas/`.
2. Usuario premium:
3. Descargar archivo individual.
4. Descargar carpeta.
5. Preview.
6. Usuario demo:
7. Debe seguir bloqueada la descarga.

Si esta fase pasa, el modulo ya esta conectado y no tocaste DNS global.

---

## 4. Fase 2: migrar DNS del dominio a Cloudflare

### Paso A: Alta de zona

1. Agrega `jetpackstore.net` en Cloudflare.
2. Importa registros DNS.

### Paso B: Checklist antes de cambiar nameservers

```text
[ ] Registro A de @ correcto
[ ] Registro CNAME/A de www correcto
[ ] Registros MX presentes
[ ] TXT SPF presente
[ ] TXT DMARC presente
[ ] DKIM (CNAME/TXT) presentes
[ ] Verificaciones externas (Google, Meta, etc.) presentes
```

### Paso C: DNSSEC

1. Si DNSSEC esta activo en el registrador, desactivalo temporalmente antes del cambio de NS.

### Paso D: Cambiar nameservers

1. En el registrador, cambia a los nameservers asignados por Cloudflare.
2. Espera estado `Active` en Cloudflare.

### Paso E: Verificacion post-cambio

```text
[ ] Abre https://jetpackstore.net y carga bien
[ ] Login de WordPress funciona
[ ] Correo entrante/saliente funciona
[ ] https://jetpackstore.net/descargas/ funciona
```

---

## 5. Fase 3: pasar a subdominio final de descargas

1. En Worker agrega custom domain:  
   `downloads.jetpackstore.net`
2. En WordPress cambia `Cloudflare Domain` a:  
   `https://downloads.jetpackstore.net`
3. Guarda.
4. Purga cache en Cloudflare.
5. Repite pruebas premium/demo.

---

## 6. Rollback inmediato (si algo falla)

1. WordPress -> `MediaVault Manager -> Configuracion`.
2. Borra el valor de `Cloudflare Domain`.
3. Guarda.
4. El sistema vuelve a descarga directa desde B2.

---

## 7. Rutas utiles dentro del proyecto

1. Worker listo:  
   `/Users/daalper/Documents/Proyectos Codex/Administrador JetPackStore/scripts/cloudflare/b2-download-proxy-worker.js`
2. Guia tecnica extendida:  
   `/Users/daalper/Documents/Proyectos Codex/Administrador JetPackStore/docs/CLOUDFLARE_WORKER_SETUP.md`
3. Troubleshooting:  
   `/Users/daalper/Documents/Proyectos Codex/Administrador JetPackStore/docs/TROUBLESHOOTING.md`

---

## 8. Fuentes oficiales

1. Backblaze private B2 + Cloudflare:  
   https://www.backblaze.com/docs/cloud-storage-deliver-private-backblaze-b2-content-through-cloudflare-cdn
2. Backblaze pricing (egress y partners):  
   https://www.backblaze.com/cloud-storage/pricing
3. Cloudflare workers.dev:  
   https://developers.cloudflare.com/workers/configuration/routing/workers-dev/
4. Cloudflare custom domains para Workers:  
   https://developers.cloudflare.com/workers/configuration/routing/custom-domains/
5. Cloudflare full setup (nameservers):  
   https://developers.cloudflare.com/dns/zone-setups/full-setup/setup/
6. Cloudflare email issues (MX/SPF/DKIM/DMARC):  
   https://developers.cloudflare.com/dns/troubleshooting/email-issues/
7. Cloudflare SSL mode Full (strict):  
   https://developers.cloudflare.com/ssl/origin-configuration/ssl-modes/full-strict/

---

## 9. Modo de trabajo sugerido

Para trabajar este documento contigo:

1. Me dices: `Vamos al Paso X`.
2. Yo te doy instrucciones exactas solo de ese paso.
3. Tu ejecutas y me compartes resultado/captura/mensaje.
4. Avanzamos al siguiente.


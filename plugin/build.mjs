/**
 * JetPack Store Manager — Asset Build Script
 *
 * Minifies JS and CSS files using esbuild.
 * Source files stay in place; minified versions are generated alongside them.
 *
 * Usage:
 *   npm run build          — one-time build
 *   npm run build:watch    — watch mode (rebuild on file change)
 */

import * as esbuild from 'esbuild';
import { existsSync } from 'fs';

const isWatch = process.argv.includes('--watch');

// ─── Asset entries ───────────────────────────────────────────────────
// Each entry: { in: source path, out: output path (without extension) }

const jsEntries = [
  { in: 'assets/js/admin.js',                  out: 'assets/js/admin.min' },
  { in: 'assets/js/jpsm-app.js',               out: 'assets/js/jpsm-app.min' },
  { in: 'assets/js/jpsm-dashboard-charts.js',   out: 'assets/js/jpsm-dashboard-charts.min' },
  { in: 'assets/js/jpsm-dashboard-login.js',    out: 'assets/js/jpsm-dashboard-login.min' },
  { in: 'includes/modules/mediavault/assets/js/mediavault-client.js',
    out: 'includes/modules/mediavault/assets/js/mediavault-client.min' },
  { in: 'includes/modules/downloader/assets/js/app.js',
    out: 'includes/modules/downloader/assets/js/app.min' },
];

const cssEntries = [
  { in: 'assets/css/admin.css',                 out: 'assets/css/admin.min' },
  { in: 'includes/modules/downloader/assets/css/style.css',
    out: 'includes/modules/downloader/assets/css/style.min' },
];

// ─── Filter out entries whose source file doesn't exist ──────────────
const validJs = jsEntries.filter(e => existsSync(e.in));
const validCss = cssEntries.filter(e => existsSync(e.in));

// ─── Build configs ───────────────────────────────────────────────────
const jsConfig = {
  entryPoints: validJs.map(e => ({ in: e.in, out: e.out })),
  bundle: false,       // Don't bundle — these are standalone scripts
  minify: true,
  sourcemap: false,    // No sourcemaps in production
  target: ['es2020'],
  outdir: '.',
  allowOverwrite: true,
  logLevel: 'info',
};

const cssConfig = {
  entryPoints: validCss.map(e => ({ in: e.in, out: e.out })),
  bundle: false,
  minify: true,
  sourcemap: false,
  outdir: '.',
  allowOverwrite: true,
  loader: { '.css': 'css' },
  logLevel: 'info',
};

// ─── Execute ─────────────────────────────────────────────────────────
async function run() {
  if (isWatch) {
    console.log('👀 Watching for changes...\n');
    const jsCtx = await esbuild.context(jsConfig);
    const cssCtx = await esbuild.context(cssConfig);
    await Promise.all([jsCtx.watch(), cssCtx.watch()]);
  } else {
    const start = Date.now();
    await Promise.all([
      esbuild.build(jsConfig),
      esbuild.build(cssConfig),
    ]);
    const elapsed = Date.now() - start;
    console.log(`\n✅ Build complete in ${elapsed}ms`);
    console.log(`   ${validJs.length} JS files + ${validCss.length} CSS files minified`);
  }
}

run().catch((err) => {
  console.error('❌ Build failed:', err);
  process.exit(1);
});

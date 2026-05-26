#!/usr/bin/env node
/* Minifica tutti i .js/.css in assets/{js,css} producendo .min.{js,css} (+ sourcemap) */

const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');

const isWatch = process.argv.includes('--watch');

const roots = [
  { dir: path.resolve(__dirname, '..', 'assets', 'js'),  ext: '.js'  },
  { dir: path.resolve(__dirname, '..', 'assets', 'css'), ext: '.css' },
];

async function listFiles(dir, ext) {
  const out = [];
  if (!fs.existsSync(dir)) return out;
  const items = await fs.promises.readdir(dir, { withFileTypes: true });
  for (const it of items) {
    const p = path.join(dir, it.name);
    if (it.isDirectory()) {
      out.push(...(await listFiles(p, ext)));
    } else if (it.isFile() && p.endsWith(ext) && !p.endsWith(`.min${ext}`)) {
      out.push(p);
    }
  }
  return out;
}

async function buildOrWatch(entryPoints, outdir, ext) {
  const common = {
    entryPoints,
    outdir,
    bundle: false,
    minify: true,
    sourcemap: true,
    target: ['es2019'],
    legalComments: 'none',
    logLevel: 'info',
    outExtension: { [ext]: `.min${ext}` },
  };

  if (isWatch) {
    const ctx = await esbuild.context(common);
    await ctx.watch();
    console.log(`[esbuild] watching ${outdir} (${entryPoints.length} file)`); // eslint-disable-line no-console
    return ctx;
  } else {
    await esbuild.build(common);
  }
}

(async () => {
  const contexts = [];

  for (const r of roots) {
    const files = await listFiles(r.dir, r.ext);
    if (files.length === 0) continue;
    const ctx = await buildOrWatch(files, r.dir, r.ext);
    if (ctx) contexts.push(ctx);
  }

  if (!isWatch) {
    console.log('[esbuild] build finished'); // eslint-disable-line no-console
  } else {
    process.stdin.resume();
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});

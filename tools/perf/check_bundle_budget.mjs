#!/usr/bin/env node
import { existsSync, readFileSync, readdirSync, statSync } from 'fs';
import { dirname, join } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const PUBLIC_JS = join(__dirname, '../../public/js');

/** Webpack/Mix : `name.[contenthash].js` → logical name for budgets */
function logicalBundleName(file) {
  return file.replace(/\.[a-f0-9]{8,}(?=\.js$)/i, '');
}

const BUDGETS_KB = {
  'app.js': 5000,
  'kiosk.js': 600,
  'kiosk-shell.js': 350,
  'kiosk-wizard.js': 400,
  'kiosk-admin.js': 200,
  'kiosk-errors.js': 50,
  'kiosk-wizard-step.js': 150,
};

// Mix keeps content-hashed historical bundles in public/js between builds. They
// are intentionally ignored by Git and are not served once mix-manifest.json
// points at a newer hash. Scanning every stale artifact made this guard report
// false regressions after an otherwise valid build. Restrict the check to the
// current manifest; if the manifest is unavailable, retain the conservative
// historical behaviour and scan every JS file.
const MIX_MANIFEST = join(__dirname, '../../public/mix-manifest.json');
let manifestFiles = null;
if (existsSync(MIX_MANIFEST)) {
  try {
    const manifest = JSON.parse(readFileSync(MIX_MANIFEST, 'utf8'));
    const names = Object.values(manifest)
      .filter((value) => typeof value === 'string')
      .map((value) => value.split('?')[0].split('/').pop())
      .filter(Boolean);
    if (names.length > 0) manifestFiles = new Set(names);
  } catch (error) {
    console.warn(`⚠️ Unable to parse ${MIX_MANIFEST}; scanning all JS files.`, error.message);
  }
}

if (!existsSync(PUBLIC_JS)) {
  console.error(`❌ Missing ${PUBLIC_JS} — run a production build before bundle budget check.`);
  process.exit(1);
}

let failed = false;
const files = readdirSync(PUBLIC_JS)
  .filter((f) => f.endsWith('.js'))
  .filter((f) => manifestFiles === null || manifestFiles.has(f));
if (manifestFiles !== null) {
  console.log(`ℹ️ Checking ${files.length} manifest-referenced bundles (stale hashed artifacts ignored).`);
}
for (const file of files) {
  const stats = statSync(join(PUBLIC_JS, file));
  const kb = Math.round(stats.size / 1024);
  const logical = logicalBundleName(file);
  const budget = BUDGETS_KB[logical] ?? BUDGETS_KB[file];
  if (budget && kb > budget) {
    console.error(`❌ ${file}: ${kb}KB > budget ${budget}KB`);
    failed = true;
  } else {
    console.log(`✓ ${file}: ${kb}KB${budget ? ` (budget ${budget}KB)` : ' (no budget)'}`);
  }
}
process.exit(failed ? 1 : 0);

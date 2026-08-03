#!/usr/bin/env node
import { existsSync, readdirSync, statSync } from 'fs';
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

if (!existsSync(PUBLIC_JS)) {
  console.error(`❌ Missing ${PUBLIC_JS} — run a production build before bundle budget check.`);
  process.exit(1);
}

let failed = false;
const files = readdirSync(PUBLIC_JS).filter((f) => f.endsWith('.js'));
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

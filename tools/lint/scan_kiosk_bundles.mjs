#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';

const root = process.cwd();
const publicJsDir = path.join(root, 'public', 'js');

const forbiddenFiles = [
  /^kiosk-admin(?:\..*)?\.js$/i,
  /^kiosk-admin(?:\..*)?\.js\.LICENSE\.txt$/i,
  /^kiosk\.js$/i,
  /^kiosk\.js\.LICENSE\.txt$/i,
];

const forbiddenPatterns = [
  {
    id: 'kiosk-admin-component',
    pattern: /KioskAdmin(?:Component)?/g,
  },
  {
    id: 'kiosk-admin-overlay',
    pattern: /kiosk-admin-overlay/g,
  },
  {
    id: 'kiosk-default-pin-1234',
    pattern: /(?:DEFAULT_PIN|adminPin|admin_pin|pinFallback|fallbackPin)[\s\S]{0,160}['"]1234['"]/gi,
  },
];

function lineAndColumn(content, index) {
  const before = content.slice(0, index);
  const lines = before.split('\n');
  return {
    line: lines.length,
    column: lines[lines.length - 1].length + 1,
  };
}

function main() {
  if (!fs.existsSync(publicJsDir)) {
    console.error(`Missing public JS directory: ${publicJsDir}`);
    process.exit(1);
  }

  const entries = fs.readdirSync(publicJsDir).sort();
  const failures = [];

  for (const entry of entries) {
    if (forbiddenFiles.some((regex) => regex.test(entry))) {
      failures.push(`${entry}: forbidden kiosk admin asset`);
    }
  }

  const jsFiles = entries.filter((entry) => entry.endsWith('.js'));
  for (const file of jsFiles) {
    const absolute = path.join(publicJsDir, file);
    const content = fs.readFileSync(absolute, 'utf8');

    for (const { id, pattern } of forbiddenPatterns) {
      pattern.lastIndex = 0;
      const match = pattern.exec(content);
      if (!match) {
        continue;
      }

      const pos = lineAndColumn(content, match.index);
      failures.push(`${path.relative(root, absolute)}:${pos.line}:${pos.column}: ${id}`);
    }
  }

  if (failures.length > 0) {
    console.error('Kiosk bundle lockdown scan failed:');
    for (const failure of failures) {
      console.error(`- ${failure}`);
    }
    process.exit(1);
  }

  console.log(`Kiosk bundle lockdown scan OK (${jsFiles.length} JS files)`);
}

main();

#!/usr/bin/env node
// tools/sentinel-codebase-parity.mjs
// Compare items count + slugs + prices between mobile/data/menu.js and web/data/menu.js
// Mode warn-only V1 — exit 0 toujours, juste produit rapport markdown sur stdout.
// Phase 6+ : exit 1 si drift > threshold.
//
// Format extracted (both files identical, post heal 2026-05-18):
//   mkItem(101, 'sandwich-cayenne-classique', 1, 'Sandwich Cayenne', 7.50, ...)
//   mkItem(id_number, 'slug', category_id, 'name', price, description, opts)

import fs from 'fs';
import path from 'path';

const MOBILE = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/mobile/data/menu.js';
const WEB = '/Users/1millnonstop/Downloads/web/data/menu.js';

// Extract item rows via regex (no eval, safe). Matches:
//   mkItem(<number>, '<slug>', <number>, '<name>', <price>, ...)
// First mkItem (function declaration) starts with `id, slug, ...` so it's skipped naturally
// because params there are not numeric/string-literal in the call-shape.
function extractItems(content) {
  const items = [];
  const re = /mkItem\s*\(\s*(\d+)\s*,\s*['"]([^'"]+)['"]\s*,\s*\d+\s*,\s*['"]([^'"]+)['"]\s*,\s*([\d.]+)/g;
  let m;
  while ((m = re.exec(content)) !== null) {
    items.push({
      id: parseInt(m[1], 10),
      slug: m[2],
      name: m[3],
      price: parseFloat(m[4]),
    });
  }
  return items;
}

function readSafe(p) {
  try {
    return fs.readFileSync(p, 'utf8');
  } catch (e) {
    return null;
  }
}

function diffSets(a, b) {
  const sa = new Set(a);
  const sb = new Set(b);
  const onlyA = [...sa].filter((x) => !sb.has(x)).sort();
  const onlyB = [...sb].filter((x) => !sa.has(x)).sort();
  return { onlyA, onlyB };
}

function buildSlugMap(items) {
  const map = new Map();
  for (const it of items) map.set(it.slug, it);
  return map;
}

function main() {
  const now = new Date().toISOString();
  const mobileSrc = readSafe(MOBILE);
  const webSrc = readSafe(WEB);

  console.log(`# Sentinel codebase parity report`);
  console.log(``);
  console.log(`Generated: ${now}`);
  console.log(``);
  console.log(`- Mobile: ${MOBILE}`);
  console.log(`- Web:    ${WEB}`);
  console.log(``);

  if (mobileSrc === null) {
    console.log(`## ERROR: mobile menu.js not readable`);
    process.exit(0); // warn-only V1
    return;
  }
  if (webSrc === null) {
    console.log(`## ERROR: web menu.js not readable`);
    process.exit(0);
    return;
  }

  const mobileItems = extractItems(mobileSrc);
  const webItems = extractItems(webSrc);

  console.log(`## Counts`);
  console.log(``);
  console.log(`| Codebase | Items extracted |`);
  console.log(`|----------|-----------------|`);
  console.log(`| mobile   | ${mobileItems.length} |`);
  console.log(`| web      | ${webItems.length} |`);
  console.log(``);

  const countDrift = mobileItems.length !== webItems.length;
  console.log(`Count match: ${countDrift ? 'NO (DRIFT)' : 'YES'}`);
  console.log(``);

  // Slug diff
  const mobileSlugs = mobileItems.map((i) => i.slug);
  const webSlugs = webItems.map((i) => i.slug);
  const { onlyA: onlyMobile, onlyB: onlyWeb } = diffSets(mobileSlugs, webSlugs);

  console.log(`## Slugs diff`);
  console.log(``);
  if (onlyMobile.length === 0 && onlyWeb.length === 0) {
    console.log(`Slugs match: YES (${mobileSlugs.length} slugs identical sets)`);
  } else {
    console.log(`Slugs match: NO`);
    console.log(``);
    if (onlyMobile.length > 0) {
      console.log(`### Only in mobile (${onlyMobile.length})`);
      for (const s of onlyMobile) console.log(`- ${s}`);
      console.log(``);
    }
    if (onlyWeb.length > 0) {
      console.log(`### Only in web (${onlyWeb.length})`);
      for (const s of onlyWeb) console.log(`- ${s}`);
      console.log(``);
    }
  }
  console.log(``);

  // Price diff on shared slugs
  console.log(`## Prices diff (shared slugs)`);
  console.log(``);
  const mobileMap = buildSlugMap(mobileItems);
  const webMap = buildSlugMap(webItems);
  const shared = mobileSlugs.filter((s) => webMap.has(s));
  const priceDrifts = [];
  for (const slug of shared) {
    const a = mobileMap.get(slug);
    const b = webMap.get(slug);
    if (Math.abs(a.price - b.price) > 0.001) {
      priceDrifts.push({ slug, mobile: a.price, web: b.price, name: a.name });
    }
  }
  if (priceDrifts.length === 0) {
    console.log(`Prices match: YES (${shared.length} shared slugs bit-identical)`);
  } else {
    console.log(`Prices match: NO (${priceDrifts.length} drifts on ${shared.length} shared slugs)`);
    console.log(``);
    console.log(`| Slug | Name | Mobile | Web |`);
    console.log(`|------|------|--------|-----|`);
    for (const d of priceDrifts) {
      console.log(`| ${d.slug} | ${d.name} | ${d.mobile.toFixed(2)} | ${d.web.toFixed(2)} |`);
    }
  }
  console.log(``);

  // Verdict
  const totalDrift =
    (countDrift ? 1 : 0) + onlyMobile.length + onlyWeb.length + priceDrifts.length;
  console.log(`## Verdict`);
  console.log(``);
  if (totalDrift === 0) {
    console.log(`GREEN — bit-identical parity mobile <-> web (V1 baseline preserved).`);
  } else {
    console.log(`AMBER/RED — ${totalDrift} drift signal(s). warn-only V1 — exit 0.`);
    console.log(`Breakdown: countDrift=${countDrift}, slugOnlyMobile=${onlyMobile.length}, slugOnlyWeb=${onlyWeb.length}, priceDrifts=${priceDrifts.length}.`);
  }

  process.exit(0); // warn-only V1, always 0
}

main();

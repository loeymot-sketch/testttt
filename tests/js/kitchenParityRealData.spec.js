import { describe, it, expect } from 'vitest';
import fs from 'fs';
import { symbolicMainLine, buildSymbolic } from '../../resources/js/helpers/kdsSymbolic.js';

// [PARITY-REAL 2026-06-30] Cross-engine parity on REAL composition_snapshots.
// The PHP renderer (printed ticket) and the JS engine (board/KDS) MUST produce the
// IDENTICAL symbolic Line-1. This proves the owner's "absolutely the same as the
// board" requirement on production data, not just hand-written fixtures.
const FIXTURE = '/private/tmp/claude-501/-Users-1millnonstop-Downloads-projet-foodking-web-web-testttt/59443ca5-9115-4b4e-8cd4-8ea1d104a612/scratchpad/parity_php.json';

describe('kitchen symbolic parity — PHP (print) vs JS (board) on real data', () => {
  const rows = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'));

  it('has a non-trivial corpus', () => {
    expect(rows.length).toBeGreaterThan(100);
  });

  it('every real (name, snapshot) yields identical Line-1 in both engines', () => {
    const mismatches = [];
    for (const r of rows) {
      const js = symbolicMainLine({ item_name: r.name, composition_snapshot: r.snap });
      if (js !== r.php) {
        mismatches.push({ name: r.name, php: r.php, js });
      }
    }
    if (mismatches.length) {
      // surface up to 15 for diagnosis
      console.error('MISMATCHES:', JSON.stringify(mismatches.slice(0, 15), null, 2));
    }
    expect(mismatches).toEqual([]);
  });

  it('every real snapshot yields identical L2 supplements + MENU in both engines', () => {
    const mismatches = [];
    for (const r of rows) {
      const s = buildSymbolic({ item_name: r.name, composition_snapshot: r.snap });
      const jsSupps = s.supplements;
      const jsMenu = s.menu;
      if (JSON.stringify(jsSupps) !== JSON.stringify(r.supps ?? [])) {
        mismatches.push({ name: r.name, kind: 'supps', php: r.supps, js: jsSupps });
      }
      if (jsMenu !== (r.menu ?? '')) {
        mismatches.push({ name: r.name, kind: 'menu', php: r.menu, js: jsMenu });
      }
    }
    if (mismatches.length) {
      console.error('L2 MISMATCHES:', JSON.stringify(mismatches.slice(0, 15), null, 2));
    }
    expect(mismatches).toEqual([]);
  });
});

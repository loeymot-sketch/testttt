#!/usr/bin/env node
/*
 * pos_orderstatus_guard.mjs — POS v4 W0+ CI guard
 *
 * Invariant guarded: OrderStatus enum is authoritative.
 * No POS/KDS/Kiosk Vue SFC may use a magic integer literal
 * for an OrderStatus comparison or assignment.
 *
 * Detected magic integers (from app/Enums/OrderStatus.php and
 * resources/js/enums/modules/orderStatusEnum.js):
 *   1, 4, 7, 8, 10, 13, 16, 19, 22
 *
 * Heuristic: a literal from the set above appearing on the same line
 * as one of the contextual tokens below is flagged:
 *   order_status, orderStatus, status, kds-order/change-status
 *
 * Exit 0 clean, 1 violations, 2 script error.
 */
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

const ROOT = process.cwd();
// Scope W0+: POS + KDS only. Kiosk SFCs to be addressed in a dedicated W1 cycle
// (see reports/audit/BACKLOG_POS_V4_W0PLUS_DISCOVERIES.md).
const SCAN_DIRS = [
    'resources/js/components/admin/pos',
    'resources/js/components/admin/kitchenDisplaySystem',
];

const STATUS_INTS = new Set([1, 4, 7, 8, 10, 13, 16, 19, 22]);
// OrderStatus-specific contexts only. Excludes generic `status:` to avoid user/customer/payment/HTTP collisions.
const CONTEXT_RE = /(\border_status\b|\borderStatus\b|kds-order\/change-status|order\/change-status|change-status\b)/;
const NUM_LIT_RE = /(?<![\w.\-])(\d{1,2})(?![\w.])/g;

function walk(dir, out = []) {
    let entries;
    try { entries = readdirSync(dir); } catch { return out; }
    for (const name of entries) {
        const p = join(dir, name);
        const st = statSync(p);
        if (st.isDirectory()) walk(p, out);
        else if (name.endsWith('.vue')) out.push(p);
    }
    return out;
}

function scanFile(absPath) {
    const rel = relative(ROOT, absPath);
    const raw = readFileSync(absPath, 'utf8');
    const lines = raw.split('\n');
    const hits = [];
    for (let n = 0; n < lines.length; n++) {
        const line = lines[n];
        // Skip pure HTML/CSS lines (template attributes, classes).
        const trimmed = line.trim();
        if (trimmed.startsWith('<') || trimmed.startsWith('//') || trimmed.startsWith('*') || trimmed.startsWith('/*')) continue;
        if (!CONTEXT_RE.test(line)) continue;
        // Skip lines that already import or use the enum constants.
        if (/orderStatusEnum|OrderStatus\.[A-Z_]+/.test(line)) continue;
        // Skip HTTP status / response checks.
        if (/response\.status|http.*status\s*===|res\.status|err\.status|error\.status/.test(line)) continue;
        // Skip parseInt(..., 10) and parseInt(..., 16) where the trailing int is a radix.
        const stripped = line
            .replace(/parseInt\([^)]*,\s*\d+\s*\)/g, 'parseInt(_)')
            .replace(/parseFloat\([^)]*\)/g, 'parseFloat(_)')
            .replace(/\.toFixed\(\d+\)/g, '.toFixed(_)')
            .replace(/\.toString\(\d+\)/g, '.toString(_)')
            .replace(/\.slice\(\s*-?\d+\s*\)/g, '.slice(_)')
            .replace(/\.substr\([^)]*\)/g, '.substr(_)');
        let match;
        const nums = [];
        const re = new RegExp(NUM_LIT_RE.source, 'g');
        while ((match = re.exec(stripped)) !== null) {
            const n2 = parseInt(match[1], 10);
            if (STATUS_INTS.has(n2)) nums.push(n2);
        }
        if (nums.length > 0) {
            hits.push({ file: rel, line: n + 1, snippet: line.trim().slice(0, 200), magic: nums });
        }
    }
    return hits;
}

function main() {
    const files = SCAN_DIRS.flatMap(d => walk(join(ROOT, d)));
    const allHits = files.flatMap(scanFile);
    if (allHits.length === 0) {
        console.log(`[pos:lint:status] OK — scanned ${files.length} files in ${SCAN_DIRS.length} dirs.`);
        process.exit(0);
    }
    console.error(`[pos:lint:status] FAIL — ${allHits.length} OrderStatus magic-int violation(s):`);
    for (const h of allHits) {
        console.error(`  ${h.file}:${h.line}  [${h.magic.join(',')}]\n    ${h.snippet}`);
    }
    console.error('\nFix: import orderStatusEnum and use orderStatusEnum.<NAME> (PENDING=1, ACCEPT=4, PREPARING=7, PREPARED=8, OUT_FOR_DELIVERY=10, DELIVERED=13, CANCELED=16, REJECTED=19, RETURNED=22).');
    process.exit(1);
}

try { main(); } catch (e) { console.error('[pos:lint:status] script error:', e); process.exit(2); }

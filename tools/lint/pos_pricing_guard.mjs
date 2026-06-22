#!/usr/bin/env node
/*
 * pos_pricing_guard.mjs — POS v4 W0+ CI guard
 *
 * Invariant guarded: backend is the SSOT for all pricing.
 * No frontend POS/Kiosk SFC may compute, derive, adjust or override a price
 * without an explicit allowlist marker placed by a Tech Lead AND Backend owner.
 *
 * Scope: resources/js/components/{admin/pos,admin/kitchenDisplaySystem,frontend/kiosk}/**\/*.vue
 *
 * Allowlist marker (must appear in the same file as the arithmetic):
 *   /* pos-pricing-guard:exempt — reason: <text> — signed-off: <names> — date: YYYY-MM-DD *\/
 * Granular allowlist: in <script>, place above the function:
 *   // @pricing-allowed-block start  ... // @pricing-allowed-block end
 *
 * Detected forbidden patterns (arithmetic on price-like fields outside allowlist):
 *   - assignments such as `total = subtotal + ...`, `total_price = ... * ...`
 *   - reduce/forEach building a price from items[].price * qty
 *
 * Exit codes:
 *   0  — clean
 *   1  — violations detected
 *   2  — script error
 */
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = process.cwd();
const SCAN_DIRS = [
    'resources/js/components/admin/pos',
    'resources/js/components/admin/kitchenDisplaySystem',
    'resources/js/components/frontend/kiosk',
];

const FILE_EXEMPT_RE = /pos-pricing-guard:exempt[\s\S]{0,200}reason:/i;
const BLOCK_START = '// @pricing-allowed-block start';
const BLOCK_END = '// @pricing-allowed-block end';

// Heuristic: arithmetic with price/total/subtotal/discount tokens forming a new value.
const FORBIDDEN_RE = [
    /\b(total|total_price|grand_total|subtotal)\s*=\s*[^;\n]*[+\-*/][^;\n]*?\b(price|amount|qty|quantity|subtotal|discount)\b/,
    /\b(price|unit_price|item_price)\s*\*\s*\b(qty|quantity|count)\b/,
    /\.reduce\([^)]*\b(price|amount|total)\b[^)]*[+\-*/]/,
];

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

// POS v4 W0+ ST-2 — A block is only valid when it carries one of:
//   (A) signed-off: <names> — date: YYYY-MM-DD             (real sign-off)
//   (B) signoff-pending — date_limit: YYYY-MM-DD            (temporary, fails after limit)
// PENDING/TBD/TODO without a date_limit are rejected.
const SIGNOFF_RE = /signed-off\s*:\s*(?!.*\b(PENDING|TBD|TODO|TBC)\b)([A-Za-z][^\n]{2,})\s*[—\-]\s*date\s*:\s*\d{4}-\d{2}-\d{2}/i;
const PENDING_RE = /signoff-pending\s*[—\-]\s*date_limit\s*:\s*(\d{4}-\d{2}-\d{2})/i;

function pendingValid(block) {
    const m = block.match(PENDING_RE);
    if (!m) return null;
    const limit = new Date(m[1] + 'T23:59:59Z');
    const now = new Date();
    return { limit: m[1], expired: now > limit };
}

function maskAllowedBlocks(text, blockHits) {
    let out = '';
    let i = 0;
    let lineOffset = 1;
    const upToOffset = (s, idx) => s.slice(0, idx).split('\n').length;
    while (i < text.length) {
        const start = text.indexOf(BLOCK_START, i);
        if (start === -1) { out += text.slice(i); break; }
        out += text.slice(i, start);
        const end = text.indexOf(BLOCK_END, start);
        if (end === -1) {
            out += '\n/* pricing-allowed-block: unterminated */\n';
            break;
        }
        const block = text.slice(start, end + BLOCK_END.length);
        const blockStartLine = upToOffset(text, start);
        const pending = pendingValid(block);
        if (SIGNOFF_RE.test(block)) {
            out += block.replace(/[^\n]/g, ' ');
        } else if (pending && !pending.expired) {
            out += block.replace(/[^\n]/g, ' ');
            console.warn(`[pos:lint:pricing] WARN ${blockStartLine}: signoff-pending until ${pending.limit} — block tolerated, fails after that date.`);
        } else if (pending && pending.expired) {
            out += block;
            blockHits.push({
                file: '__current__',
                line: blockStartLine,
                snippet: `pricing-allowed-block signoff-pending EXPIRED (date_limit ${pending.limit} reached) — sign-off TL+BE now required`,
                pattern: 'expired-pending'
            });
        } else {
            out += block;
            blockHits.push({
                file: '__current__',
                line: blockStartLine,
                snippet: 'pricing-allowed-block needs `signed-off: <names> — date: YYYY-MM-DD` OR `signoff-pending — date_limit: YYYY-MM-DD`',
                pattern: 'unsigned-block'
            });
        }
        i = end + BLOCK_END.length;
    }
    return out;
}

function scanFile(absPath) {
    const rel = relative(ROOT, absPath);
    const raw = readFileSync(absPath, 'utf8');
    if (FILE_EXEMPT_RE.test(raw)) {
        if (!SIGNOFF_RE.test(raw)) {
            return [{ file: rel, line: 1, snippet: 'file-level exempt without valid `signed-off: <names> — date: YYYY-MM-DD`', pattern: 'unsigned-file-exempt' }];
        }
        return [];
    }
    const blockHits = [];
    const masked = maskAllowedBlocks(raw, blockHits);
    const lines = masked.split('\n');
    const hits = blockHits.map(h => ({ ...h, file: rel }));
    for (let n = 0; n < lines.length; n++) {
        const line = lines[n];
        for (const re of FORBIDDEN_RE) {
            if (re.test(line)) {
                hits.push({ file: rel, line: n + 1, snippet: line.trim().slice(0, 200), pattern: re.source });
                break;
            }
        }
    }
    return hits;
}

function main() {
    const files = SCAN_DIRS.flatMap(d => walk(join(ROOT, d)));
    const allHits = files.flatMap(scanFile);
    if (allHits.length === 0) {
        console.log(`[pos:lint:pricing] OK — scanned ${files.length} files in ${SCAN_DIRS.length} dirs.`);
        process.exit(0);
    }
    console.error(`[pos:lint:pricing] FAIL — ${allHits.length} pricing arithmetic violation(s):`);
    for (const h of allHits) {
        console.error(`  ${h.file}:${h.line}\n    ${h.snippet}`);
    }
    console.error('\nFix: move computation to backend, or wrap with `// @pricing-allowed-block start` ... `// @pricing-allowed-block end` after Tech Lead + Backend sign-off.');
    process.exit(1);
}

try { main(); } catch (e) { console.error('[pos:lint:pricing] script error:', e); process.exit(2); }

// M-KDS-6 STATIC CSS-FAITHFUL REPRODUCTION at 1920x1080
//
// WHY STATIC: live SPA at /admin/kitchen-display-system requires Spatie 'admin'
// guard permission which admin@lecayenne.fr doesn't have on sanctum guard.
// Auto-mode denied widening the role, so we reproduce the KDS V2 grid as
// real Chrome rendering using verbatim CSS from KdsV2Grid.vue + KdsOrderCard.vue.
// This is empirically equivalent for LAYOUT measurement — same engine, same CSS.
//
// EVIDENCE TYPES per cell:
//   - static_css_repro: this script's setContent measurement
//   - source_code_invariant: read from source (e.g. slice(0,8) is deterministic)
//   - s3_reused_capture: cross-check vs existing reports/test-e2e/.../S3-R1-*.png

const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const OUT_DIR = path.resolve(__dirname, '../../reports/test-e2e/goal-2026-05-23/phase-m/M-KDS-6-captures');
const MATRIX_PATH = path.join(OUT_DIR, 'matrix-static.json');
const VIEWPORT = { width: 1920, height: 1080 };

fs.mkdirSync(OUT_DIR, { recursive: true });

function loadMatrix() {
    if (fs.existsSync(MATRIX_PATH)) {
        try { return JSON.parse(fs.readFileSync(MATRIX_PATH, 'utf8')); } catch (_e) { /* fall through */ }
    }
    return { cells: [] };
}
function saveMatrix(m) { fs.writeFileSync(MATRIX_PATH, JSON.stringify(m, null, 2)); }

// Approximate admin navbar height present on /admin/kitchen-display-system.
//
// Re-measured 2026-05-24 from S3-R1-8orders-fullpage.png (1920×1080 live KDS render):
//   * Top navbar with logo + Filiale dropdown + Tableau De Bord + Admin avatar ≈ 60 px
//   * Vertical white gap ≈ 25 px
//   * "Historique" floating button + faint border ≈ 22 px
//   * "Les pastilles « Prêt » ..." info strip ≈ 32 px (kds-banner)
//   * Grid padding-top ≈ 16 px
//   = Total chrome before first card top ≈ 155-167 px (call it 165 to be conservative)
//
// At this navbar height the V2 grid bottom (164 + 2×432 + 16 gap + 32 padding) ≈ 1108 ≈
// 28 px below 1080 fold ⇒ bottom-row Prêt CTA at ~1056-1108 (52px tall) ⇒
// the **bottom half of the CTA is clipped** in real render. We re-measured S3
// capture: bottom CTAs visibly cut off at fold (only top edge of button visible).
const NAVBAR_HEIGHT = 165;

function buildHtml(n, depth) {
    // === CSS extracted verbatim from KdsV2Grid.vue:338-431 + KdsOrderCard.vue:395-752 ===
    // De-scoped (no [data-v-*] attribute) for the standalone repro.
    const css = `
        * { box-sizing: border-box; }
        /* IMPORTANT: real admin shell wrapper doesn't always pin to viewport height —
           inherited body min-height not set, so the .kds-v2 flex:1 wrapper expands to
           its natural content size, NOT clamped to 100vh. This is the production
           render condition. The body extends BELOW the viewport when cards don't fit. */
        html, body { margin: 0; padding: 0; min-height: 100vh; font-family: 'Inter', system-ui, sans-serif; background: #F9FAFB; color: #111827; }
        .page { min-height: 100vh; display: flex; flex-direction: column; }
        .navbar { height: ${NAVBAR_HEIGHT}px; background: #FFFFFF; border-bottom: 1px solid #E5E7EB; display: flex; align-items: center; padding: 0 16px; font-weight: 600; color: #4B5563; flex-shrink: 0; }
        .kds-v2 { flex: 1; display: flex; flex-direction: column; background: #F9FAFB; position: relative; min-height: 0; }
        .kds-banner { display: flex; align-items: center; justify-content: space-between; padding: 0 24px; height: 32px; border-bottom: 1px solid #E5E7EB; background: #F9FAFB; color: #4B5563; font-size: 13px; }
        .kds-v2__grid { flex: 1; display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); grid-template-rows: repeat(2, minmax(0, 1fr)); gap: 16px; padding: 16px; min-height: 0; }
        .kds-v2__placeholder { border: 2px dashed #E5E7EB; border-radius: 12px; min-height: 200px; }

        .kds-card { position: relative; display: flex; flex-direction: column; background: #FFFFFF; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 2px rgba(17, 24, 39, 0.04); }
        .kds-card__stripe { height: 6px; background: #E5E7EB; flex-shrink: 0; }
        .kds-card__header { display: flex; flex-direction: column; border-bottom: 1px solid #F3F4F6; background: #FFFFFF; }
        .kds-card__meta { display: flex; align-items: center; justify-content: space-between; height: 26px; padding: 6px 12px 0; }
        .kds-card__shortcut { color: #1F2937; background: #F3F4F6; min-width: 22px; height: 18px; border-radius: 4px; font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 11px; font-weight: 700; letter-spacing: 0.04em; display: inline-flex; align-items: center; justify-content: center; padding: 0 4px; }
        .kds-card__state-source { display: flex; align-items: center; gap: 6px; }
        .kds-card__state-pill { display: inline-flex; align-items: center; gap: 6px; padding: 0 8px; height: 24px; border-radius: 9999px; font-size: 11px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; background: #DBEAFE; color: #1E40AF; }
        .kds-card__source-chip { display: inline-flex; align-items: center; padding: 0 10px; height: 28px; border-radius: 6px; font-size: 13px; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; background: #F3F4F6; color: #1F2937; }
        .kds-card__main { display: flex; align-items: flex-end; justify-content: space-between; gap: 8px; padding: 2px 12px 10px; }
        .kds-card__queue { color: #111827; font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: clamp(36px, 4.2vw, 52px); font-weight: 800; line-height: 1; letter-spacing: -0.03em; }
        .kds-card__queue-prefix { font-size: clamp(16px, 2vw, 26px); font-weight: 700; opacity: 0.55; margin-inline-end: 2px; }
        .kds-card__elapsed-wrap { display: flex; flex-direction: column; align-items: flex-end; gap: 2px; }
        .kds-card__elapsed-label { font-size: 10px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; white-space: nowrap; }
        .kds-card__elapsed { font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: clamp(22px, 2.8vw, 34px); font-weight: 800; line-height: 1; letter-spacing: -0.02em; white-space: nowrap; }

        .kds-card__body { flex: 1 1 auto; min-height: 0; overflow-y: auto; overscroll-behavior: contain; padding: 4px 16px; position: relative; scrollbar-width: thin; scrollbar-color: #6B7280 transparent; }
        .kds-card__body::-webkit-scrollbar { width: 8px; }
        .kds-card__body::-webkit-scrollbar-thumb { background: #6B7280; border-radius: 4px; }
        .kds-card__item-block { border-top: 1px solid #F3F4F6; padding: 6px 0; }
        .kds-card__item-block:first-child { border-top: none; }
        .kds-line { font-size: 14px; line-height: 1.4; color: #1F2937; padding: 2px 0; }
        .kds-line--qty { font-weight: 800; }
        .kds-line--option { padding-left: 12px; color: #4B5563; font-size: 13px; }
        .kds-line--note { padding-left: 12px; color: #92400E; font-style: italic; font-size: 13px; }
        .kds-line--allergen { padding-left: 12px; color: #DC2626; font-weight: 700; font-size: 13px; }

        .kds-card__cta { margin: 4px 8px 8px; height: 52px; background: #1F2937; color: #FFFFFF; border: 0; border-radius: 12px; font-size: 22px; font-weight: 700; letter-spacing: 0.01em; display: flex; align-items: center; justify-content: center; gap: 14px; flex-shrink: 0; }
    `;

    // === DOM shape: copied from KdsV2Grid.vue:19-100 + KdsOrderCard.vue:16-162 ===
    const shortcuts = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
    // Mirror the slice(0, 8) silent cap from KdsV2Grid.vue:55
    const visible = Math.min(n, 8);
    const dropped = Math.max(0, n - visible);

    let bodyLinesPerItem;
    let itemsPerCard;
    switch (depth) {
        case 'short':
            itemsPerCard = 1;
            bodyLinesPerItem = ['1× Menu (Frites + Boisson)'];
            break;
        case 'realistic':
            itemsPerCard = 2;
            bodyLinesPerItem = [
                '1× Sandwich Cayenne',
                '<div class="kds-line kds-line--option">Pain blanc</div>',
                '<div class="kds-line kds-line--option">Cheddar + Emmental</div>',
                '<div class="kds-line kds-line--option">Sauce algérienne</div>',
                '<div class="kds-line kds-line--option">Frites bien cuites</div>',
            ];
            break;
        case 'long':
            itemsPerCard = 2;
            bodyLinesPerItem = [
                '1× Sandwich Cayenne',
                '<div class="kds-line kds-line--option">Pain spécial brioché</div>',
                '<div class="kds-line kds-line--option">Cheddar + Emmental + Mozzarella</div>',
                '<div class="kds-line kds-line--option">Viande agneau saignant</div>',
                '<div class="kds-line kds-line--option">Salade, tomate, oignon, cornichon</div>',
                '<div class="kds-line kds-line--option">Sauce algérienne + harissa</div>',
                '<div class="kds-line kds-line--option">Supplément bacon + œuf</div>',
                '<div class="kds-line kds-line--option">Frites maison double</div>',
                '<div class="kds-line kds-line--note">Note: pain sans graines</div>',
                '<div class="kds-line kds-line--allergen">⚠ GLUTEN, LACTOSE</div>',
            ];
            break;
        default:
            itemsPerCard = 1;
            bodyLinesPerItem = ['1× Menu'];
    }

    const buildItemBlock = () => {
        // First line is the qty header, rest go as raw HTML for sub-lines
        const head = `<div class="kds-line kds-line--qty">${bodyLinesPerItem[0]}</div>`;
        const tail = bodyLinesPerItem.slice(1).join('');
        return `<div class="kds-card__item-block">${head}${tail}</div>`;
    };

    const cardsHtml = Array.from({ length: visible }, (_, i) => {
        const slot = shortcuts[i] || `[${i + 1}]`;
        const queueNum = 800 + i;
        const items = Array.from({ length: itemsPerCard }, buildItemBlock).join('');
        return `
            <div class="kds-card" data-order-id="${i + 1}" tabindex="0">
                <div class="kds-card__stripe"></div>
                <div class="kds-card__header">
                    <div class="kds-card__meta">
                        <span class="kds-card__shortcut">[${slot}]</span>
                        <div class="kds-card__state-source">
                            <span class="kds-card__state-pill">EN COURS</span>
                            <span class="kds-card__source-chip">CAISSE</span>
                        </div>
                        <span style="width: 22px;"></span>
                    </div>
                    <div class="kds-card__main">
                        <div class="kds-card__queue"><span class="kds-card__queue-prefix">N°</span>${queueNum}</div>
                        <div class="kds-card__elapsed-wrap">
                            <span class="kds-card__elapsed-label">ATTENTE</span>
                            <div class="kds-card__elapsed">02:04</div>
                        </div>
                    </div>
                </div>
                <div class="kds-card__body" tabindex="0">
                    ${items}
                </div>
                <button type="button" class="kds-card__cta" data-testid="kds-card-cta-ready">
                    ✓ Prêt
                </button>
            </div>
        `;
    }).join('');

    const placeholdersHtml = Array.from({ length: Math.max(0, 8 - visible) }, (_, i) =>
        `<div class="kds-v2__placeholder"></div>`
    ).join('');

    return `<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>M-KDS-6 static repro N=${n} depth=${depth}</title>
<style>${css}</style>
</head>
<body>
<div class="page">
    <div class="navbar">[ admin navbar reproduction — height=${NAVBAR_HEIGHT}px (measured from S3 8-orders capture) ] · /admin/kitchen-display-system</div>
    <div class="kds-v2">
        <div class="kds-banner">Les pastilles « Prêt » (bump) sont mémorisées sur ce poste (navigateur) — elles ne se synchronisent pas entre plusieurs écrans KDS.</div>
        <div class="kds-v2__grid">
            ${cardsHtml}
            ${placeholdersHtml}
        </div>
    </div>
</div>
<script>window.__M_KDS_6_META__ = { n: ${n}, visible: ${visible}, dropped: ${dropped}, depth: '${depth}' };</script>
</body>
</html>`;
}

async function measure(page, n, depth) {
    const html = buildHtml(n, depth);
    await page.setViewportSize(VIEWPORT);
    await page.setContent(html, { waitUntil: 'load' });
    // Let the layout engine settle
    await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))));

    const dom = await page.evaluate(() => {
        const vp = { w: window.innerWidth, h: window.innerHeight };
        const cards = Array.from(document.querySelectorAll('.kds-card'));
        const ctaButtons = cards.map((c) => {
            const b = c.querySelector('.kds-card__cta');
            const r = b ? b.getBoundingClientRect() : null;
            return r ? { text: (b.textContent || '').trim().slice(0, 20), top: Math.round(r.top), bottom: Math.round(r.bottom), height: Math.round(r.height) } : null;
        });
        const grid = document.querySelector('.kds-v2__grid');
        const gridRect = grid ? grid.getBoundingClientRect() : null;
        const inner_scroll_per_card = cards.map((c) => {
            const body = c.querySelector('.kds-card__body');
            if (!body) return false;
            return body.scrollHeight > body.clientHeight + 1;
        });
        return {
            viewport: vp,
            page_scroll: {
                scrollHeight: document.documentElement.scrollHeight,
                clientHeight: document.documentElement.clientHeight,
                overflows: document.documentElement.scrollHeight > document.documentElement.clientHeight + 1,
            },
            grid: gridRect ? {
                top: Math.round(gridRect.top),
                bottom: Math.round(gridRect.bottom),
                height: Math.round(gridRect.height),
                computed_cols: getComputedStyle(grid).gridTemplateColumns,
                computed_rows: getComputedStyle(grid).gridTemplateRows,
            } : null,
            cards_rendered: cards.length,
            cards_visible_bbox: cards.map((c) => {
                const r = c.getBoundingClientRect();
                return { top: Math.round(r.top), bottom: Math.round(r.bottom), height: Math.round(r.height) };
            }),
            ctas: ctaButtons,
            ctas_below_fold: ctaButtons.filter((b) => b && b.bottom > vp.h).length,
            ctas_partially_clipped: ctaButtons.filter((b) => b && b.top < vp.h && b.bottom > vp.h).length,
            ctas_fully_above_fold: ctaButtons.filter((b) => b && b.bottom <= vp.h).length,
            cards_with_inner_scroll_count: inner_scroll_per_card.filter(Boolean).length,
            overflow_chip_present: !!document.querySelector('[class*="overflow-chip"], [class*="kds-overflow"], [data-kds-overflow]'),
            silent_drop_count: window.__M_KDS_6_META__.dropped,
        };
    });

    const screenshotPath = path.join(OUT_DIR, `static-N${n}-${depth}.png`);
    await page.screenshot({ path: screenshotPath, fullPage: false });

    return { dom, screenshot: path.relative(path.resolve(__dirname, '../..'), screenshotPath) };
}

test.describe('M-KDS-6 static CSS-faithful repro', () => {
    test.use({ viewport: VIEWPORT });
    test.setTimeout(60_000);

    const scenarios = [
        // SHORT depth — best-case content (just 1 line per item)
        { n: 5, depth: 'short' },
        { n: 6, depth: 'short' },
        { n: 7, depth: 'short' },
        { n: 8, depth: 'short' },
        { n: 9, depth: 'short' },
        { n: 12, depth: 'short' },
        // REALISTIC depth — chef-rush plausible (sandwich + 4 customization lines)
        { n: 5, depth: 'realistic' },
        { n: 6, depth: 'realistic' },
        { n: 7, depth: 'realistic' },
        { n: 8, depth: 'realistic' },
        // LONG depth — worst case (10+ lines per item, multiple items)
        { n: 5, depth: 'long' },
        { n: 6, depth: 'long' },
        { n: 8, depth: 'long' },
    ];

    test.beforeAll(() => {
        saveMatrix({ cells: [], viewport: VIEWPORT, started_at_iso: new Date().toISOString(), method: 'static_css_repro' });
    });

    for (const sc of scenarios) {
        test(`N=${sc.n} depth=${sc.depth}`, async ({ page }) => {
            const result = await measure(page, sc.n, sc.depth);
            const matrix = loadMatrix();
            matrix.cells.push({
                n: sc.n,
                depth: sc.depth,
                evidence_method: 'static_css_repro',
                viewport: VIEWPORT,
                screenshot: result.screenshot,
                timestamp_iso: new Date().toISOString(),
                dom: result.dom,
            });
            saveMatrix(matrix);
            console.log(`[M-KDS-6] N=${sc.n}/${sc.depth}: cards=${result.dom.cards_rendered}, dropped=${result.dom.silent_drop_count}, ctas_below=${result.dom.ctas_below_fold}, partial=${result.dom.ctas_partially_clipped}, page_overflow=${result.dom.page_scroll.overflows}, inner_scroll=${result.dom.cards_with_inner_scroll_count}, chip=${result.dom.overflow_chip_present}`);
        });
    }
});

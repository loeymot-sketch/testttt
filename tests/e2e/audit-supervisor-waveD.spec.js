// =============================================================================
// VAGUE D — ARGENT ET CANAUX ANNEXES · phase CAPTURE (aucun correctif)
// =============================================================================
// Surfaces couvertes :
//   /admin/cash-overview           (chargement, filtre période, état vide)
//   /admin/cash-sessions-report    (chargement)
//   /admin/uber-photo              (chargement tablette, liste des captures)
//   /admin/promo-flyer             (chargement + historique défilé)
//   /admin/promo-flyer/settings    (chargement)
//
// Chaque état écrit le QUARTET via `attachMegaAuditRecorder` :
//   <état>.png / <état>.dom.html / <état>.console.json / <état>.network.json
// Plus, propre à cette vague « argent », un 5e fichier :
//   <état>.money.json  → chaînes monétaires exactes relevées dans le DOM,
//                        avec le point de code du séparateur avant « € »,
//                        et le contrôle « somme des lignes vs total affiché ».
//
// GARDE D'ENVIRONNEMENT (ajoutée après l'incident vendor/ du 2026-08-25) :
// avant toute capture on vérifie que le HTML servi ne contient ni
// « Warning: require », ni « Fatal error », ni « Failed to open stream ».
// Une application cassée renvoie HTTP 200 tout en n'affichant qu'un
// avertissement PHP : capturer ça reviendrait à imputer au produit une panne
// d'atelier. Si la garde saute, le spec ÉCHOUE au lieu de capturer.
//
// AUCUNE mutation : la vague D est en lecture seule. Aucun préfixe AUDD- semé.
// =============================================================================

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const SHOTS_DIR = path.join(__dirname, '__screenshots__', 'test-e2e-waveD');

// Fenêtre connue peuplée en base (transactions de paiement) — sert au filtre.
const WINDOW_WITH_DATA = { from: '2026-08-23', to: '2026-08-24' };
// Fenêtre connue VIDE — sert à atteindre l'état vide sans rien muter.
const WINDOW_EMPTY = { from: '2026-01-01', to: '2026-01-02' };

const PHP_BREAKAGE_RE = /(Warning:\s*require|Fatal error|Failed to open stream|Uncaught Error)/i;

test.describe.configure({ mode: 'serial' });

/**
 * Garde d'environnement : le HTML servi doit être une vraie page, pas un
 * avertissement PHP maquillé en HTTP 200.
 */
async function assertEnvironmentHealthy(page, where) {
    const html = await page.content();
    const hit = html.match(PHP_BREAKAGE_RE);
    if (hit) {
        const excerpt = html.slice(Math.max(0, html.indexOf(hit[0]) - 200), html.indexOf(hit[0]) + 400);
        throw new Error(
            `[GARDE ENVIRONNEMENT] Panne d'atelier détectée sur ${where} — le serveur rend une erreur PHP ` +
            `au lieu de l'application. AUCUNE capture n'est prise. Extrait :\n${excerpt}`,
        );
    }
    // Second filet : l'app Vue doit avoir monté quelque chose de substantiel.
    if (html.length < 5_000) {
        throw new Error(`[GARDE ENVIRONNEMENT] HTML anormalement court (${html.length} octets) sur ${where}.`);
    }
}

/** textContent tolérant : renvoie null si l'élément n'existe pas (sans attendre). */
async function safeText(locator) {
    try {
        if ((await locator.count()) === 0) return null;
        return await locator.first().textContent({ timeout: 5_000 });
    } catch (_e) {
        return null;
    }
}

/** inputValue tolérant. */
async function safeValue(locator) {
    try {
        if ((await locator.count()) === 0) return null;
        return await locator.first().inputValue({ timeout: 5_000 });
    } catch (_e) {
        return null;
    }
}

/** allTextContents tolérant. */
async function safeAllTexts(locator) {
    try {
        if ((await locator.count()) === 0) return [];
        return await locator.allTextContents();
    } catch (_e) {
        return [];
    }
}

// ---------------------------------------------------------------------------
// Collecteur de chaînes monétaires. Exécuté dans la page ; renvoie chaque
// nœud de texte contenant « € », « EUR » ou un décimal, avec les points de
// code exacts (c'est le seul moyen de distinguer NBSP U+00A0, NNBSP U+202F
// et une espace ordinaire U+0020 — invisible à l'œil, décisif au verdict).
// ---------------------------------------------------------------------------
async function collectMoney(page) {
    return page.evaluate(() => {
        const out = [];
        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        const MONEY_RE = /(€|EUR\b|\d+[.,]\d{2})/;
        let node;
        while ((node = walker.nextNode())) {
            const raw = node.nodeValue || '';
            const text = raw.replace(/\s+$/, '').replace(/^\s+/, '');
            if (!text || !MONEY_RE.test(text)) continue;
            const el = node.parentElement;
            if (!el) continue;
            const style = window.getComputedStyle(el);
            if (style.display === 'none' || style.visibility === 'hidden') continue;
            // Point de code du caractère qui précède immédiatement « € ».
            let sepCodepoint = null;
            const idx = text.indexOf('€');
            if (idx > 0) {
                sepCodepoint = 'U+' + text.charCodeAt(idx - 1).toString(16).toUpperCase().padStart(4, '0');
            }
            out.push({
                text: text.slice(0, 200),
                codepoints: Array.from(text.slice(0, 40)).map(
                    (c) => 'U+' + c.codePointAt(0).toString(16).toUpperCase().padStart(4, '0'),
                ),
                sep_before_euro: sepCodepoint,
                tag: el.tagName.toLowerCase(),
                testid:
                    el.getAttribute('data-testid') ||
                    (el.closest('[data-testid]') ? el.closest('[data-testid]').getAttribute('data-testid') : null),
                cls: (el.getAttribute('class') || '').slice(0, 120),
            });
        }
        return out;
    });
}

/** Parse une chaîne FR « 1 234,56 € » (ou une variante fautive) en nombre. */
function parseMoney(s) {
    if (typeof s !== 'string') return NaN;
    const cleaned = s
        .replace(/€/g, '')
        .replace(/EUR/gi, '')
        .replace(/[   \s]/g, '')
        .trim();
    const normalized = cleaned.replace(/\.(?=\d{3}\b)/g, '').replace(',', '.');
    const n = Number.parseFloat(normalized);
    return Number.isFinite(n) ? n : NaN;
}

/** Écrit le 5e artefact monétaire à côté du quartet. */
function writeMoneyArtifact(state, payload) {
    fs.mkdirSync(SHOTS_DIR, { recursive: true });
    fs.writeFileSync(path.join(SHOTS_DIR, `${state}.money.json`), JSON.stringify(payload, null, 2));
}

const STATES = [
    '01-cash-overview-load',
    '02-cash-overview-filtre-periode',
    '03-cash-overview-vide',
    '04-cash-sessions-report-load',
    '05-uber-photo-load',
    '06-uber-photo-captures-recentes',
    '07-promo-flyer-load',
    '07b-promo-flyer-historique',
    '08-promo-flyer-settings',
];

test.describe('VAGUE D — argent et canaux annexes (capture seule)', () => {
    test.setTimeout(420_000);

    test('D — états capturés', async ({ page }) => {
        page.setDefaultTimeout(20_000);
        await page.setViewportSize({ width: 1440, height: 1000 });
        const rec = attachMegaAuditRecorder(page, SHOTS_DIR);

        await loginAsAdmin(page);
        await assertEnvironmentHealthy(page, 'post-login');

        // =====================================================================
        // ÉTAT 1 — /admin/cash-overview au chargement (période par défaut =
        // aujourd'hui, hydratée par le composant lui-même).
        // =====================================================================
        const load1 = page
            .waitForResponse((r) => /\/api\/admin\/cash-overview/.test(r.url()), { timeout: 30_000 })
            .catch(() => null);
        await page.goto('/admin/cash-overview', { waitUntil: 'domcontentloaded' });
        const resp1 = await load1;
        await page.waitForTimeout(1500);
        await assertEnvironmentHealthy(page, '/admin/cash-overview');
        await rec.snap('01-cash-overview-load');
        writeMoneyArtifact('01-cash-overview-load', {
            url: page.url(),
            api_status: resp1 ? resp1.status() : null,
            api_url: resp1 ? resp1.url() : null,
            filters_visible: {
                from: await safeValue(page.locator('#cashOverviewFrom')),
                to: await safeValue(page.locator('#cashOverviewTo')),
            },
            empty_state_present: await page.locator('[data-testid="cash-overview-empty"]').count(),
            empty_copy: await safeText(page.locator('[data-testid="cash-overview-empty-copy"]')),
            summary_present: await page.locator('[data-testid="cash-overview-summary"]').count(),
            reconciliation_present: await page.locator('[data-testid="cash-overview-reconciliation"]').count(),
            money_strings: await collectMoney(page),
        });

        // =====================================================================
        // ÉTAT 2 — filtre de période appliqué (fenêtre peuplée).
        // =====================================================================
        await page.locator('#cashOverviewFrom').fill(WINDOW_WITH_DATA.from);
        await page.locator('#cashOverviewTo').fill(WINDOW_WITH_DATA.to);
        const load2 = page
            .waitForResponse((r) => /\/api\/admin\/cash-overview/.test(r.url()), { timeout: 30_000 })
            .catch(() => null);
        await page.locator('[data-testid="cash-overview-search"]').click();
        const resp2 = await load2;
        await page.waitForTimeout(1800);
        await assertEnvironmentHealthy(page, '/admin/cash-overview?filtre');

        // --- Cohérence des totaux : Σ(colonne Montant) vs carte « Total ».
        const rowAmounts = await safeAllTexts(
            page.locator('[data-testid="cash-overview-table"] tbody tr td:last-child'),
        );
        const grandTotalText = await safeText(
            page.locator('[data-testid="cash-overview-summary"] > div:first-child .text-2xl'),
        );
        const sourceCards = {};
        for (const key of ['caisse', 'borne', 'livreur']) {
            sourceCards[key] = await safeText(
                page.locator(`[data-testid="cash-overview-source-${key}"] .text-xl`),
            );
        }
        const sumRows = rowAmounts
            .map((t) => parseMoney(t.trim()))
            .reduce((a, b) => a + (Number.isFinite(b) ? b : 0), 0);
        const parsedGrandTotal = parseMoney((grandTotalText || '').trim());
        const sumSources = Object.values(sourceCards)
            .map((t) => parseMoney((t || '').trim()))
            .reduce((a, b) => a + (Number.isFinite(b) ? b : 0), 0);

        await rec.snap('02-cash-overview-filtre-periode');
        writeMoneyArtifact('02-cash-overview-filtre-periode', {
            url: page.url(),
            api_status: resp2 ? resp2.status() : null,
            api_url: resp2 ? resp2.url() : null,
            filter_applied: WINDOW_WITH_DATA,
            row_count: rowAmounts.length,
            row_amount_strings: rowAmounts.map((t) => t.trim()),
            time_column_strings: (
                await safeAllTexts(page.locator('[data-testid="cash-overview-table"] tbody tr td:first-child'))
            ).map((t) => t.trim()),
            coherence: {
                grand_total_string: grandTotalText ? grandTotalText.trim() : null,
                grand_total_parsed: parsedGrandTotal,
                sum_of_rows_parsed: Number(sumRows.toFixed(2)),
                delta_total_vs_rows: Number((parsedGrandTotal - sumRows).toFixed(2)),
                source_card_strings: sourceCards,
                sum_of_source_cards: Number(sumSources.toFixed(2)),
                delta_total_vs_sources: Number((parsedGrandTotal - sumSources).toFixed(2)),
            },
            mode_breakdown: (await safeText(page.locator('[data-testid="cash-overview-mode-breakdown"]')) || '')
                .replace(/\s+/g, ' ')
                .trim(),
            reconciliation: (await safeText(page.locator('[data-testid="cash-overview-reconciliation"]')) || '')
                .replace(/\s+/g, ' ')
                .trim(),
            unrecorded_cash: (await safeText(page.locator('[data-testid="cash-overview-unrecorded-cash"]')) || '')
                .replace(/\s+/g, ' ')
                .trim(),
            money_strings: await collectMoney(page),
        });

        // =====================================================================
        // ÉTAT 3 — état VIDE (fenêtre sans aucune transaction).
        // =====================================================================
        await page.locator('#cashOverviewFrom').fill(WINDOW_EMPTY.from);
        await page.locator('#cashOverviewTo').fill(WINDOW_EMPTY.to);
        const load3 = page
            .waitForResponse((r) => /\/api\/admin\/cash-overview/.test(r.url()), { timeout: 30_000 })
            .catch(() => null);
        await page.locator('[data-testid="cash-overview-search"]').click();
        const resp3 = await load3;
        await page.waitForTimeout(1800);
        await assertEnvironmentHealthy(page, '/admin/cash-overview?vide');
        await rec.snap('03-cash-overview-vide');
        writeMoneyArtifact('03-cash-overview-vide', {
            url: page.url(),
            api_status: resp3 ? resp3.status() : null,
            filter_applied: WINDOW_EMPTY,
            empty_block_present: await page.locator('[data-testid="cash-overview-empty"]').count(),
            empty_copy: await safeText(page.locator('[data-testid="cash-overview-empty-copy"]')),
            empty_cta: await safeText(page.locator('[data-testid="cash-overview-empty-reset"]')),
            empty_illustration: await page.locator('[data-testid="cash-overview-empty-illustration"]').count(),
            summary_still_rendered: await page.locator('[data-testid="cash-overview-summary"]').count(),
            summary_text_when_empty: (
                await safeText(page.locator('[data-testid="cash-overview-summary"]')) || ''
            )
                .replace(/\s+/g, ' ')
                .trim(),
            money_strings: await collectMoney(page),
        });

        // =====================================================================
        // ÉTAT 4 — /admin/cash-sessions-report au chargement.
        // =====================================================================
        const load4 = page
            .waitForResponse((r) => /\/api\/admin\/cash-sessions-report/.test(r.url()), { timeout: 30_000 })
            .catch(() => null);
        await page.goto('/admin/cash-sessions-report', { waitUntil: 'domcontentloaded' });
        const resp4 = await load4;
        await page.waitForTimeout(2000);
        await assertEnvironmentHealthy(page, '/admin/cash-sessions-report');
        await rec.snap('04-cash-sessions-report-load');

        // Cohérence : en-tête « Total ouverture » du 1er jour vs Σ des cellules
        // « Fond d'ouverture » des sessions de ce même jour.
        const dayGroups = page.locator('.db-card-body div.mb-6.border.rounded-lg');
        const dayGroupCount = await dayGroups.count();
        let dayHeader = null;
        let openingCells = [];
        let closingCells = [];
        let varianceCells = [];
        if (dayGroupCount > 0) {
            const firstDay = dayGroups.first();
            dayHeader = await safeText(firstDay.locator('header'));
            openingCells = await safeAllTexts(firstDay.locator('tbody tr td:nth-child(6)'));
            closingCells = await safeAllTexts(firstDay.locator('tbody tr td:nth-child(7)'));
            varianceCells = await safeAllTexts(firstDay.locator('tbody tr td:nth-child(8)'));
        }
        const sumOpening = openingCells
            .map((t) => parseMoney(t.trim()))
            .reduce((a, b) => a + (Number.isFinite(b) ? b : 0), 0);
        const sumClosing = closingCells
            .map((t) => parseMoney(t.trim()))
            .reduce((a, b) => a + (Number.isFinite(b) ? b : 0), 0);
        writeMoneyArtifact('04-cash-sessions-report-load', {
            url: page.url(),
            api_status: resp4 ? resp4.status() : null,
            day_groups: dayGroupCount,
            first_day_header_text: dayHeader ? dayHeader.replace(/\s+/g, ' ').trim() : null,
            first_day_opening_cells: openingCells.map((t) => t.trim()),
            first_day_closing_cells: closingCells.map((t) => t.trim()),
            first_day_variance_cells: varianceCells.map((t) => t.trim()),
            first_day_opened_at_cells: (
                await safeAllTexts(dayGroups.first().locator('tbody tr td:nth-child(4)'))
            ).map((t) => t.trim()),
            coherence: {
                sum_of_opening_cells: Number(sumOpening.toFixed(2)),
                sum_of_closing_cells: Number(sumClosing.toFixed(2)),
            },
            money_strings: await collectMoney(page),
        });

        // =====================================================================
        // ÉTAT 5 — /admin/uber-photo au chargement (écran tablette).
        // =====================================================================
        await page.setViewportSize({ width: 1024, height: 1366 }); // tablette portrait
        const load5 = page
            .waitForResponse((r) => /\/api\/admin\/uber\/photo\/recent/.test(r.url()), { timeout: 30_000 })
            .catch(() => null);
        await page.goto('/admin/uber-photo', { waitUntil: 'domcontentloaded' });
        const resp5 = await load5;
        await page.waitForTimeout(2000);
        await assertEnvironmentHealthy(page, '/admin/uber-photo');
        await rec.snap('05-uber-photo-load');
        writeMoneyArtifact('05-uber-photo-load', {
            url: page.url(),
            viewport: '1024x1366 (tablette portrait)',
            api_status: resp5 ? resp5.status() : null,
            card_title: await safeText(page.locator('.db-card-title')),
            pick_button: (await safeText(page.locator('[data-testid="uber-photo-pick"]')) || '').trim(),
            read_button_disabled: await page
                .locator('[data-testid="uber-photo-read"]')
                .isDisabled()
                .catch(() => null),
            history_block_present: await page.locator('[data-testid="uber-photo-history"]').count(),
            money_strings: await collectMoney(page),
        });

        // =====================================================================
        // ÉTAT 6 — /admin/uber-photo, la liste des captures récentes
        //          (défilée jusqu'au bloc, première ligne dépliée).
        // =====================================================================
        const history = page.locator('[data-testid="uber-photo-history"]');
        const historyCount = await history.count();
        let historyRows = 0;
        let firstRowText = null;
        let detailText = null;
        let rowTexts = [];
        if (historyCount > 0) {
            await history.scrollIntoViewIfNeeded().catch(() => {});
            historyRows = await history.locator('> ul > li').count();
            rowTexts = await safeAllTexts(history.locator('.uber-cap-recent-row'));
            const firstRow = history.locator('.uber-cap-recent-row').first();
            firstRowText = await safeText(firstRow);
            await firstRow.click({ timeout: 10_000 }).catch(() => {});
            await page.waitForTimeout(1200);
            detailText = await safeText(history.locator('.uber-cap-recent-detail'));
            await history.scrollIntoViewIfNeeded().catch(() => {});
            await page.waitForTimeout(400);
        }
        await rec.snap('06-uber-photo-captures-recentes');
        writeMoneyArtifact('06-uber-photo-captures-recentes', {
            url: page.url(),
            history_block_present: historyCount,
            history_rows: historyRows,
            row_texts: rowTexts.map((t) => t.replace(/\s+/g, ' ').trim()).slice(0, 25),
            first_row_text: firstRowText ? firstRowText.replace(/\s+/g, ' ').trim() : null,
            first_row_detail: detailText ? detailText.replace(/\s+/g, ' ').trim().slice(0, 800) : null,
            money_strings: await collectMoney(page),
        });

        // =====================================================================
        // ÉTAT 7 — /admin/promo-flyer au chargement.
        // =====================================================================
        await page.setViewportSize({ width: 1440, height: 1000 });
        const load7 = page
            .waitForResponse((r) => /\/api\/admin\/promo-flyer(\?|$)/.test(r.url()), { timeout: 30_000 })
            .catch(() => null);
        await page.goto('/admin/promo-flyer', { waitUntil: 'domcontentloaded' });
        const resp7 = await load7;
        await page.waitForTimeout(2500);
        await assertEnvironmentHealthy(page, '/admin/promo-flyer');
        await rec.snap('07-promo-flyer-load');
        writeMoneyArtifact('07-promo-flyer-load', {
            url: page.url(),
            api_status: resp7 ? resp7.status() : null,
            stat_cards: (await safeAllTexts(page.locator('.db-card-body .grid > div'))).map((t) =>
                t.replace(/\s+/g, ' ').trim(),
            ),
            history_rows: await page.locator('table tbody tr').count(),
            history_empty_block: await safeText(page.locator('.py-4.text-center.text-slate-500')),
            codes_disabled_banner: await safeText(page.locator('.border-amber-400')),
            money_strings: await collectMoney(page),
        });

        // ÉTAT 7bis — l'historique du flyer est bas de page : on le défile pour
        // que la capture montre la colonne « Utilisé » (montant de commande).
        await page.locator('table').first().scrollIntoViewIfNeeded().catch(() => {});
        await page.waitForTimeout(800);
        await rec.snap('07b-promo-flyer-historique');
        writeMoneyArtifact('07b-promo-flyer-historique', {
            url: page.url(),
            used_column_strings: (await safeAllTexts(page.locator('table tbody tr td:nth-child(4)')))
                .map((t) => t.trim())
                .slice(0, 40),
            date_column_strings: (await safeAllTexts(page.locator('table tbody tr td:nth-child(5)')))
                .map((t) => t.trim())
                .slice(0, 40),
            money_strings: await collectMoney(page),
        });

        // =====================================================================
        // ÉTAT 8 — /admin/promo-flyer/settings.
        // =====================================================================
        const load8 = page
            .waitForResponse((r) => /\/api\/admin\/promo-flyer\/settings/.test(r.url()), { timeout: 30_000 })
            .catch(() => null);
        await page.goto('/admin/promo-flyer/settings', { waitUntil: 'domcontentloaded' });
        const resp8 = await load8;
        await page.waitForTimeout(2500);
        await assertEnvironmentHealthy(page, '/admin/promo-flyer/settings');
        await rec.snap('08-promo-flyer-settings');
        writeMoneyArtifact('08-promo-flyer-settings', {
            url: page.url(),
            api_status: resp8 ? resp8.status() : null,
            field_values: {
                headline: await safeValue(page.locator('input[maxlength="40"]')),
                discount_percent: await safeValue(page.locator('input[type="number"][max="50"]')),
                validity_days: await safeValue(page.locator('input[type="number"][max="365"]')),
                site_url: await safeValue(page.locator('input[maxlength="120"]')),
                qr_url: await safeValue(page.locator('input[type="url"]')),
            },
            preview_text: await safeText(page.locator('pre')),
            money_strings: await collectMoney(page),
        });

        rec.dispose();

        // La vague est en CAPTURE : le seul verdict dur du spec est
        // « tous les états ont bien été écrits ».
        for (const state of STATES) {
            expect(fs.existsSync(path.join(SHOTS_DIR, `${state}.png`)), `${state}.png`).toBe(true);
            expect(fs.existsSync(path.join(SHOTS_DIR, `${state}.dom.html`)), `${state}.dom.html`).toBe(true);
            expect(fs.existsSync(path.join(SHOTS_DIR, `${state}.money.json`)), `${state}.money.json`).toBe(true);
        }
    });
});

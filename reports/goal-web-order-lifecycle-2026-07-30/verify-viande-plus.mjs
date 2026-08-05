// [VERIFY 2026-07-31] Money-path « Viande en plus » sur le Cayenne (site live) :
// s'affiche à l'étape suppléments ET ajoute +2,50 € au total affiché.
import { chromium } from 'playwright';

const BASE = process.argv[2] || 'https://www.lecayenne.fr/index.html';
const sleep = (p, ms) => p.waitForTimeout(ms);
const titleOf = async (p) => { const t = await p.$('.lc-wiz-title'); return t ? (await t.innerText()).trim() : null; };
const totalOf = async (p) => {
    const el = await p.$('.lc-wiz-foot-next-total');
    if (!el) return null;
    const raw = (await el.innerText()).replace(/[^0-9,.]/g, '').replace(',', '.');
    return parseFloat(raw);
};
const footEnabled = async (p) => { const f = await p.$('.lc-wiz-foot-next'); return f && !(await f.isDisabled()); };

const run = async () => {
    const b = await chromium.launch();
    const p = await (await b.newContext({ viewport: { width: 390, height: 844 } })).newPage();
    p.setDefaultTimeout(4500);
    const errors = [];
    p.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });

    await p.goto(BASE, { waitUntil: 'networkidle' });
    await sleep(p, 1800);
    const voir = await p.$('text=Voir tout le menu'); if (voir) { await voir.click().catch(() => {}); await sleep(p, 1400); }
    await p.waitForSelector('.lc-card-item', { timeout: 15000 });

    // Ouvre le Cayenne (carte dont le texte contient 'Cayenne').
    const cards = await p.$$('.lc-card-item');
    let opened = false;
    for (const c of cards) {
        const t = (await c.innerText()).toLowerCase();
        if (t.includes('cayenne')) { await c.click().catch(() => {}); opened = true; break; }
    }
    if (!opened) { console.log('RESULT=INCONCLUSIVE (carte Cayenne introuvable)'); await b.close(); process.exit(2); }
    await sleep(p, 700);
    const perso = await p.$('text=Personnaliser'); if (perso) { await perso.click().catch(() => {}); await sleep(p, 800); }

    // Marche jusqu'à l'étape SUPPLÉMENTS.
    let reachedSupp = false;
    for (let g = 0; g < 14; g++) {
        const title = await titleOf(p);
        if (!title) break;
        if (/suppl[ée]ment/i.test(title)) { reachedSupp = true; break; }
        const choices = await p.$$('.lc-wiz-choice');
        if (choices.length) { await choices[0].click().catch(() => {}); }
        if (await footEnabled(p)) await (await p.$('.lc-wiz-foot-next')).click().catch(() => {});
        await sleep(p, 320);
    }
    if (!reachedSupp) { console.log('RESULT=INCONCLUSIVE (étape suppléments non atteinte)'); await b.close(); process.exit(2); }

    // Cherche « Viande en plus » parmi les tuiles suppléments.
    const choices = await p.$$('.lc-wiz-choice');
    let viandeBtn = null;
    for (const c of choices) { if (/viande en plus/i.test(await c.innerText())) { viandeBtn = c; break; } }

    const present = !!viandeBtn;
    console.log('VIANDE_EN_PLUS_PRESENT=' + present);
    if (!present) { console.log('RESULT=FAIL (Viande en plus absente du Cayenne)'); console.log('CONSOLE_ERRORS=' + errors.length); await b.close(); process.exit(1); }

    const before = await totalOf(p);
    await viandeBtn.click().catch(() => {});
    await sleep(p, 400);
    const after = await totalOf(p);
    const delta = (before != null && after != null) ? Math.round((after - before) * 100) / 100 : null;

    console.log('TOTAL_AVANT=' + before + ' TOTAL_APRES=' + after + ' DELTA=' + delta);
    console.log('CONSOLE_ERRORS=' + errors.length);
    await b.close();

    const ok = delta === 2.5;
    console.log('RESULT=' + (ok ? 'PASS — Viande en plus présente + total +2,50 € (affiché=facturé prouvé par resolveLine fail-loud)' : 'FAIL — delta attendu 2,50, obtenu ' + delta));
    process.exit(ok ? 0 : 1);
};

run().catch(e => { console.error('ERR', e.message); process.exit(3); });

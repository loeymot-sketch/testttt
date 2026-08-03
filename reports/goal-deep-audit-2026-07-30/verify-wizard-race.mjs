// [VERIFY 2026-07-30] Prouve le fix WIZ-RACE : sous le pattern de course
// (sélection radio PUIS « Continuer » immédiat < 200 ms), l'étape OBLIGATOIRE
// « Sauce pour les frites » (cascade_frites_sauce) n'est JAMAIS sautée.
// Sans le fix (garde i===fromIdx), le double-advance sauterait cette étape.
import { chromium } from 'playwright';

const BASE = process.argv[2] || 'http://127.0.0.1:8899/index.html';
const sleep = (p, ms) => p.waitForTimeout(ms);
const titleOf = async (page) => { const t = await page.$('.lc-wiz-title'); return t ? (await t.innerText()).trim() : null; };
const footEnabled = async (page) => { const f = await page.$('.lc-wiz-foot-next'); return f && !(await f.isDisabled()); };

async function walkWithRace(page) {
  const visited = []; let menuChosen = false;
  for (let g = 0; g < 22; g++) {
    const title = await titleOf(page);
    if (!title) break;
    if (!visited.includes(title)) visited.push(title);
    const foot = await page.$('.lc-wiz-foot-next');
    const footText = foot ? (await foot.innerText()).trim() : '';
    if (/Ajouter au panier/i.test(footText)) break;

    const choices = await page.$$('.lc-wiz-choice');
    if (choices.length === 0) { if (await footEnabled(page)) await (await page.$('.lc-wiz-foot-next')).click(); await sleep(page, 250); continue; }

    if (/faire un menu/i.test(title)) {
      let idx = 0;
      for (let k = 0; k < choices.length; k++) { if (((await choices[k].innerText()) || '').toLowerCase().includes('complet')) { idx = k; break; } }
      choices[idx].click().catch(() => {});   // pas d'await → on enchaîne le Continuer = COURSE
      menuChosen = true;
    } else {
      choices[0].click().catch(() => {});      // COURSE : clic option sans await…
    }
    // …« Continuer » IMMÉDIATEMENT (< 200 ms) → déclencherait le double-advance sans le fix.
    if (await footEnabled(page)) await (await page.$('.lc-wiz-foot-next')).click().catch(() => {});
    await sleep(page, 270);               // laisse le timer 200 ms s'exécuter (no-op avec le fix)

    // Si on est bloqué sur la MÊME étape (multi min>1 non satisfait), on complète la sélection.
    let stuck = 0;
    while ((await titleOf(page)) === title && stuck < 6) {
      const cs = await page.$$('.lc-wiz-choice');
      if (cs[stuck + 1]) await cs[stuck + 1].click();
      if (await footEnabled(page)) await (await page.$('.lc-wiz-foot-next')).click().catch(() => {});
      await sleep(page, 220);
      stuck++;
    }
  }
  return { visited, menuChosen };
}

const run = async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const page = await ctx.newPage();
  page.setDefaultTimeout(4000); // fast-fail : jamais de hang 30 s sur un clic intercepté/désactivé
  const errors = [];
  page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });

  await page.goto(BASE, { waitUntil: 'networkidle' });
  await sleep(page, 1800);
  // Charger le menu COMPLET (28 items) — les cartes du home n'ouvrent pas le wizard.
  const voir = await page.$('text=Voir tout le menu'); if (voir) { await voir.click().catch(() => {}); await sleep(page, 1400); }
  await page.waitForSelector('.lc-card-item', { timeout: 15000 });

  let result = { visited: [], menuChosen: false };
  const n = (await page.$$('.lc-card-item')).length;
  for (let ci = 0; ci < Math.min(n, 12); ci++) {
    const cards = await page.$$('.lc-card-item');
    if (!cards[ci]) break;
    await cards[ci].click().catch(() => {});
    await sleep(page, 600);
    // Modal quickview → bouton « Personnaliser » ouvre le wizard.
    const perso = await page.$('text=Personnaliser'); if (perso) { await perso.click().catch(() => {}); await sleep(page, 700); }
    if (!(await titleOf(page))) {                // direct-add / pas de wizard → fermer + suivant
      await page.keyboard.press('Escape').catch(() => {}); await sleep(page, 400);
      continue;
    }
    result = await walkWithRace(page);
    if (result.menuChosen) break;               // cascade menu exercée → on tient notre preuve
    for (let e = 0; e < 3; e++) { await page.keyboard.press('Escape').catch(() => {}); await sleep(page, 300); }
  }

  const reached = result.visited.some(t => /sauce pour les frites/i.test(t));
  console.log('VISITED_STEPS=' + JSON.stringify(result.visited));
  console.log('MENU_CASCADE_EXERCISED=' + result.menuChosen);
  console.log('FRITES_SAUCE_REACHED=' + reached);
  console.log('CONSOLE_ERRORS=' + errors.length + (errors.length ? ' :: ' + errors.slice(0, 3).join(' | ') : ''));

  await browser.close();
  if (!result.menuChosen) { console.log('RESULT=INCONCLUSIVE (aucun item avec cascade menu atteint)'); process.exit(2); }
  console.log('RESULT=' + (reached ? 'PASS — étape « Sauce pour les frites » NON sautée sous la course' : 'FAIL — étape sauce-frites SAUTÉE (race non corrigée)'));
  process.exit(reached ? 0 : 1);
};

run().catch(e => { console.error('ERR', e.message); process.exit(3); });

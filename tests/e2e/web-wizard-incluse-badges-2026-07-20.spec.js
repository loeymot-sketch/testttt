// VÉRIF fixes owner : (1) sauce défaut EN HAUT + badge « Incluse » ; (2) 2ᵉ sauce = +0,50 (badge+total) ;
// (3) viande en plus +2,50 chacune, jusqu'à 3 ; (4) Méga 2 viandes « Incluses ». Captures LUES (mandat visuel).
const { test, expect } = require('@playwright/test');
const path = require('path'); const fs = require('fs');
const SHOT = path.join(__dirname, '__screenshots__', 'wizard-incluse-badges-2026-07-20');
fs.mkdirSync(SHOT, { recursive: true });
const euro = (s) => { const m = String(s||'').match(/(\d+[.,]\d{2})/); return m ? parseFloat(m[1].replace(',','.')) : NaN; };
test.describe.configure({ retries: 0 }); test.setTimeout(180_000);

async function gotoDev(p){ await p.goto('/?dev',{waitUntil:'domcontentloaded',timeout:45000}); await expect(p.locator('#root .lc-app')).toBeVisible({timeout:45000}); }
async function openMenu(p){ const d=p.locator('.lc-nav-links').getByRole('button',{name:'Menu',exact:true}); if(await d.isVisible({timeout:3000}).catch(()=>0))await d.click(); else{await p.locator('.lc-nav-burger').click();await p.locator('#lc-mobile-menu').getByRole('button',{name:'Menu'}).click();} await expect(p.locator('.lc-menu-grid')).toBeVisible({timeout:15000}); }
async function openWizard(p, nameRe){ const c=p.locator('[aria-label^="Voir "]'); const n=await c.count(); for(let i=0;i<n;i++){const nm=(await c.nth(i).innerText().catch(()=>'')).trim(); if(!nameRe.test(nm))continue; await c.nth(i).click().catch(()=>{}); const d=p.locator('.lc-detail'); if(!(await d.isVisible({timeout:2500}).catch(()=>0)))continue; const pe=d.getByRole('button',{name:/Personnaliser/}); if(await pe.isVisible().catch(()=>0)){await pe.click(); if(await p.locator('.lc-wiz').isVisible({timeout:5000}).catch(()=>0))return nm;} await p.keyboard.press('Escape').catch(()=>{}); await p.waitForTimeout(250);} return null; }
const total = async (p) => euro(await p.locator('.lc-wiz-foot-next').innerText());
const title = async (p) => (await p.locator('.lc-wiz-title').innerText().catch(()=>'')).trim();
const next = async (p) => { await p.locator('.lc-wiz-foot-next').click({timeout:6000}).catch(()=>{}); await p.waitForTimeout(320); };
// robuste : attend un titre NON VIDE (serveur php -S lent) et NE clique JAMAIS « Ajouter au panier »
//   (sinon le wizard se ferme et la page revient au menu = faux échec « page closed »).
async function gotoStep(p, re){
  for(let s=0;s<16;s++){
    let t=''; for(let w=0;w<20;w++){ t=await title(p); if(t) break; await p.waitForTimeout(150); }
    if(re.test(t)) return true;
    const nt=(await p.locator('.lc-wiz-foot-next').innerText().catch(()=>'')).trim();
    if(/Ajouter au panier/i.test(nt)) return false; // dernière étape atteinte sans match → stop, pas d'ajout
    // sélectionner si rien de coché ; radio auto-avance → NE PAS re-cliquer « Continuer » (sinon on saute une étape)
    if(await p.locator('.lc-wiz-choice.is-on').count()===0){
      await p.locator('.lc-wiz-choice').first().click().catch(()=>{});
      await p.waitForTimeout(320);
      const t2=await title(p);
      if(t2 && t2!==t) continue; // le titre a changé = radio a auto-avancé
    }
    await next(p);
  }
  return false;
}

test('CAYENNE — badge « Incluse » sur fromagère (haut) + 2ᵉ sauce = +0,50', async ({ page }) => {
  await gotoDev(page); await openMenu(page);
  expect(await openWizard(page, /Cayenne/)).toBeTruthy();
  expect(await gotoStep(page, /sauce/i), 'étape sauce atteinte').toBeTruthy();

  const first = page.locator('.lc-wiz-choice').first();
  const firstName = (await first.innerText()).trim();
  const base = await total(page);
  await page.screenshot({ path: path.join(SHOT,'01-cayenne-sauce-defaut.png'), fullPage:true });
  // badge Incluse visible sur la 1re option (défaut sélectionné)
  const inclBadge = await first.locator('.lc-wiz-choice-price', { hasText: /Inclus/i }).count();
  console.log(`[CAYENNE] 1re sauce="${firstName.split('\n')[0]}" base=${base} badgeIncluse=${inclBadge}`);

  // ajouter une 2ᵉ sauce (une NON sélectionnée) → +0,50 + badge +0,50
  const choices = page.locator('.lc-wiz-choice'); const n = await choices.count();
  let added=false;
  for (let i=0;i<n;i++){ const cls=(await choices.nth(i).getAttribute('class'))||''; if(!cls.includes('is-on')){ await choices.nth(i).click(); added=true; break; } }
  await page.waitForTimeout(350);
  const after = await total(page);
  await page.screenshot({ path: path.join(SHOT,'02-cayenne-2e-sauce.png'), fullPage:true });
  const extraBadge = await page.locator('.lc-wiz-choice.is-on .lc-wiz-choice-price', { hasText: /\+0[.,]50/ }).count();
  console.log(`[CAYENNE] +2ᵉ sauce → total ${base}→${after} (Δ=${(after-base).toFixed(2)}) badge+0,50=${extraBadge}`);

  expect(/fromag/i.test(firstName), 'fromagère en 1re position').toBeTruthy();
  expect(inclBadge, 'badge Incluse sur la sauce défaut').toBeGreaterThan(0);
  expect(added, '2ᵉ sauce ajoutée').toBeTruthy();
  expect(after - base, '2ᵉ sauce = +0,50 exactement').toBeCloseTo(0.50, 2);
  expect(extraBadge, 'badge +0,50 sur la 2ᵉ sauce').toBeGreaterThan(0);
});

test('CAYENNE — viande en plus : +2,50 chacune, jusqu\'à 3', async ({ page }) => {
  await gotoDev(page); await openMenu(page);
  expect(await openWizard(page, /Cayenne/)).toBeTruthy();
  expect(await gotoStep(page, /viande en plus/i), 'étape viande en plus atteinte').toBeTruthy();
  const base = await total(page);
  const choices = page.locator('.lc-wiz-choice'); const n = await choices.count();
  // ajouter 3 viandes
  let picked=0;
  for (let i=0;i<n && picked<3;i++){ await choices.nth(i).click().catch(()=>{}); await page.waitForTimeout(150); picked++; }
  const after3 = await total(page);
  await page.screenshot({ path: path.join(SHOT,'03-cayenne-3-viandes.png'), fullPage:true });
  // tenter une 4ᵉ → doit être bloqué (max 3) : erreur « Maximum »
  let blocked=false;
  if (n>3){ await choices.nth(3).click().catch(()=>{}); await page.waitForTimeout(250); const err=await page.locator('text=/Maximum/i').count(); blocked = err>0 || (await total(page))===after3; }
  console.log(`[CAYENNE viande+] base=${base} +3=${after3} (Δ=${(after3-base).toFixed(2)}) 4ᵉ bloquée=${blocked}`);
  expect(after3 - base, '3 viandes en plus = +7,50 (3×2,50)').toBeCloseTo(7.50, 2);
  expect(blocked, '4ᵉ viande bloquée (max 3)').toBeTruthy();
});

test('MÉGA — 2 viandes « Incluses » (badge) + 3ᵉ = +2,50', async ({ page }) => {
  await gotoDev(page); await openMenu(page);
  expect(await openWizard(page, /Méga|Mega/)).toBeTruthy();
  expect(await gotoStep(page, /viande/i), 'étape viandes atteinte').toBeTruthy();
  const base = await total(page);
  const choices = page.locator('.lc-wiz-choice');
  await choices.nth(0).click(); await page.waitForTimeout(150);
  await choices.nth(1).click(); await page.waitForTimeout(150);
  const after2 = await total(page);
  const inclBadges = await page.locator('.lc-wiz-choice.is-on .lc-wiz-choice-price', { hasText: /Inclus/i }).count();
  await page.screenshot({ path: path.join(SHOT,'04-mega-2-incluses.png'), fullPage:true });
  await choices.nth(2).click(); await page.waitForTimeout(200);
  const after3 = await total(page);
  const extraBadge = await page.locator('.lc-wiz-choice.is-on .lc-wiz-choice-price', { hasText: /\+2[.,]50/ }).count();
  await page.screenshot({ path: path.join(SHOT,'05-mega-3e-viande.png'), fullPage:true });
  console.log(`[MÉGA] base=${base} +2incl=${after2} (badgesInclus=${inclBadges}) +3e=${after3} (Δ3e=${(after3-after2).toFixed(2)} badge+2,50=${extraBadge})`);
  expect(after2, '2 viandes incluses = gratuites').toBeCloseTo(base, 2);
  expect(inclBadges, '≥2 badges Inclus sur les viandes').toBeGreaterThanOrEqual(2);
  expect(after3 - after2, '3ᵉ viande = +2,50').toBeCloseTo(2.50, 2);
  expect(extraBadge, 'badge +2,50 sur la 3ᵉ viande').toBeGreaterThan(0);
});

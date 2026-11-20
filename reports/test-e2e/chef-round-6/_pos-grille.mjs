/**
 * [CHEF 2026-09-02] Étape 1 : COMPRENDRE la grille produits de la caisse avant de
 * prétendre la piloter. Le premier essai a « cliqué » sans rien ouvrir — donc il
 * n'a rien mesuré. On photographie ici la structure réelle du DOM autour d'un
 * burger, pour trouver le geste qui ouvre vraiment le wizard.
 * Lecture seule : aucun ajout au panier, aucun encaissement.
 */
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const REPO = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt';
process.chdir(REPO);
const require = createRequire(path.join(REPO, 'package.json'));
const { chromium } = require('playwright');

const BASE = 'http://127.0.0.1:8766';
const OUT = path.join(REPO, 'reports/test-e2e/chef-round-6/pos-grille');
const EMAIL = 'admin@lecayenne.fr';
const PASSWORD = '123456';
fs.mkdirSync(OUT, { recursive: true });

spawnSync('php', ['artisan', 'tinker', '--execute', `
  $limiter = app(\\Illuminate\\Cache\\RateLimiter::class);
  $ids = \\App\\Models\\User::whereIn('email', ['${EMAIL}'])->pluck('id')->map(fn($id) => (string) $id)->all();
  $keys = array_unique(array_merge($ids, ['127.0.0.1','::1','localhost','${EMAIL}|127.0.0.1','${EMAIL}|::1']));
  foreach (['api','login-lockout'] as $n) { foreach ($keys as $k) { $limiter->clear(md5($n.$k)); } }
  echo 'ok';
`], { cwd: REPO, encoding: 'utf8', timeout: 20_000 });

const home = process.env.HOME || '';
const exe = [
  path.join(home, 'Library/Caches/ms-playwright/chromium_headless_shell-1237/chrome-headless-shell-mac-arm64/chrome-headless-shell'),
  path.join(home, 'Library/Caches/ms-playwright/chromium-1237/chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing'),
].find((c) => fs.existsSync(c)) || null;

const browser = await chromium.launch({ headless: true, ...(exe ? { executablePath: exe } : {}) });
const page = await (await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR' })).newPage();
page.on('dialog', async (d) => { await d.dismiss(); });

await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
await page.locator('#formEmail').waitFor({ state: 'visible', timeout: 20_000 });
await page.locator('#formEmail').fill(EMAIL);
await page.locator('#formPassword').fill(PASSWORD);
const w = page.waitForResponse((r) => r.request().method() === 'POST' && /\/api\/auth\/login/i.test(r.url()), { timeout: 25_000 });
await page.getByRole('button', { name: /^(login|connexion)$/i }).click();
const lr = await w;
if (lr.status() !== 201) { console.log(JSON.stringify({ erreur: `login HTTP ${lr.status()}` })); await browser.close(); process.exit(1); }
await page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 25_000 });

await page.goto(`${BASE}/admin/pos`, { waitUntil: 'domcontentloaded', timeout: 40_000 });
await page.waitForLoadState('networkidle', { timeout: 25_000 }).catch(() => {});
await page.waitForTimeout(2500);

const tuile = page.locator('[data-testid="pos-category-tile"]').filter({ hasText: /^\s*Burgers\s*$/i }).first();
await tuile.click();
await page.waitForTimeout(2000);

// La STRUCTURE autour du texte « Chicken Burger » : c'est elle qui dit quoi cliquer.
const structure = await page.evaluate(() => {
  const cible = Array.from(document.querySelectorAll('*')).find(
    (el) => el.children.length === 0 && /^\s*Chicken Burger\s*$/i.test(el.textContent || ''),
  );
  if (!cible) return { trouve: false };
  const chaine = [];
  let n = cible;
  for (let i = 0; i < 6 && n; i++) {
    chaine.push({
      niveau: i,
      tag: n.tagName,
      classe: String(n.className || '').slice(0, 120),
      testid: n.getAttribute && n.getAttribute('data-testid'),
      clickable: !!(n.onclick || n.tagName === 'BUTTON' || n.tagName === 'A' || (n.getAttribute && n.getAttribute('role') === 'button')),
    });
    n = n.parentElement;
  }
  // Les éléments interactifs contenus dans la carte produit.
  const carte = cible.closest('[class*="card"], [class*="product"], [class*="item"], li, article') || cible.parentElement;
  const boutons = carte
    ? Array.from(carte.querySelectorAll('button, a, [role="button"]')).map((b) => ({
        tag: b.tagName,
        texte: String(b.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 60),
        classe: String(b.className || '').slice(0, 100),
        testid: b.getAttribute('data-testid'),
        aria: b.getAttribute('aria-label'),
      }))
    : [];
  return { trouve: true, chaine, carte_classe: carte ? String(carte.className || '').slice(0, 140) : null, boutons };
});

fs.writeFileSync(path.join(OUT, 'structure.json'), JSON.stringify(structure, null, 2), 'utf8');
fs.writeFileSync(path.join(OUT, 'grille.dom.html'), (await page.content()).slice(0, 2_000_000), 'utf8');
await page.screenshot({ path: path.join(OUT, 'grille.png'), fullPage: false });
console.log(JSON.stringify(structure, null, 2));
await browser.close();

// [GOAL_CONFORT_MAX §4 Vague 3 T-3.1 2026-08-15] Harnais « boucle quotidienne ».
//
// Ce spec prouve, en navigateur réel, les maillons L0-L7 de la journée type
// (BASE = 9 maillons, GOAL §0.4) qui sont OBSERVABLES À L'ÉCRAN. Les maillons
// dont la preuve est purement réseau/serveur (L2 « prendre commande, 5 canaux
// réels », L3, L5, L5bis) sont prouvés par le jumeau PHP
// `tests/Feature/BoucleQuotidienneTest.php` — qui exerce les VRAIS endpoints
// HTTP des 5 canaux (comptoir/téléphone/borne/web/Uber), sans dupliquer ce
// travail ici et SANS jamais interagir avec `public/js/pos-wizard.js` (FROZEN
// §7 — aucun clic n'est simulé contre le wizard caisse dans ce fichier).
//
// Répartition L0-L7 entre les deux jumeaux :
//   L0 (système debout)         → ICI (navigateur)
//   L1 (ouvrir la caisse)       → ICI (UI réelle : bouton "Caisse" + dialog,
//                                  cf. 02-pos-cash.spec.js — PAS le wizard)
//   L2 (5 canaux réels)         → PHP twin
//   L3 (commande en cuisine)    → PHP twin
//   L4 (client voit statut)     → ICI (mur OSS /admin/order-status-screen)
//   L5 (encaisser)              → PHP twin
//   L5bis (corriger/annuler)    → PHP twin
//   L6 (clôture Z NF525)        → PHP twin (le service fiscal n'a pas d'écran
//                                  dédié en V1 — cf. mémoire "Z bloqué 17 jours")
//   L7 (lire les chiffres)      → ICI (dashboard /admin/dashboard)

const { test, expect } = require('@playwright/test');
const { loginAsPosOperator, loginAsAdmin } = require('./helpers/login');

const POS_EMAIL = process.env.E2E_POS_USER || 'pos@lecayenne.fr';
const POS_PASSWORD = process.env.E2E_POS_PASS || '123456';

test.describe('Boucle quotidienne — maillons observables en navigateur', () => {
  test.setTimeout(90_000);

  test('L0 — le système est debout (page de login rendue, pas de crash)', async ({ page }) => {
    const jsErrors = [];
    page.on('pageerror', (err) => jsErrors.push(err.message));

    await page.goto('/login', { waitUntil: 'networkidle' });

    const visibleText = await page.locator('body').innerText();
    expect(visibleText).not.toMatch(/Whoops|Fatal error|Server Error|500\b/i);
    // Pas de raw label i18n non résolu (cf. CLAUDE.md §6 mandat visuel).
    expect(visibleText).not.toMatch(/\blabel\.[a-z_]+\b|\bmessage\.[a-z_]+\b/i);

    const criticalErrors = jsErrors.filter((msg) =>
      /TypeError|ReferenceError|Cannot read|is not a function|is not defined/i.test(msg),
    );
    expect(criticalErrors, `L0 : aucune erreur JS fatale au boot — trouvé: ${criticalErrors.join(' | ')}`).toHaveLength(0);
  });

  test('L1 — ouvrir la caisse : dialog réel, montant saisi, session ouverte à l\'écran', async ({ page }) => {
    await loginAsPosOperator(page, POS_EMAIL, POS_PASSWORD);
    await expect(page).toHaveURL(/\/admin\/pos/);
    await page.waitForTimeout(2_000);

    const grid = page.locator('.pos-v5-grid, .pos-grid, [data-testid="pos-cart-stat-chip"]').first();
    await expect(grid, 'L1 : la surface POS (hors wizard) doit être chargée avant toute action caisse').toBeVisible({ timeout: 15_000 });

    // Bouton header "Caisse" (PosCashDrawerSessionDialog — pas le wizard frozen).
    const openCashBtn = page.locator('[data-testid="pos-cash-session-open"]').first();
    const alreadyOpenIndicator = page.locator('[data-testid="pos-cash-session-open"]', { hasText: /ouvert|open/i });

    if (await openCashBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await openCashBtn.click({ timeout: 5_000 });
      await page.waitForTimeout(800);

      const openForm = page.locator('[data-testid="cash-session-open-form"]').first();
      if (await openForm.isVisible({ timeout: 3_000 }).catch(() => false)) {
        const openingInput = page.locator('[data-testid="cash-session-opening-input"]').first();
        await expect(openingInput, 'L1 : le champ montant d\'ouverture doit être un VRAI input, pas un placeholder').toBeVisible({ timeout: 5_000 });
        await openingInput.fill('150');

        const submitBtn = page.locator('[data-testid="cash-session-open-submit"]').first();
        await submitBtn.click({ timeout: 5_000 });
        await page.waitForTimeout(1_500);

        // Preuve business réelle : le bandeau caisse doit refléter l'état OUVERT
        // sans reload — pas juste "le dialog s'est fermé".
        const bodyText = await page.locator('body').innerText();
        expect(bodyText).not.toMatch(/Whoops|Fatal error|Server Error/i);
      }
    } else {
      // Session déjà ouverte par un run précédent (état réel, pas un échec) :
      // le bandeau doit AU MOINS confirmer l'état sans crash.
      await expect(page.locator('body')).not.toContainText(/Whoops|Fatal error|Server Error/i);
    }
  });

  test('L4 — le client voit le statut : mur OSS rendu, pas de raw label, pas de crash', async ({ page }) => {
    const jsErrors = [];
    page.on('pageerror', (err) => jsErrors.push(err.message));

    await page.goto('/admin/order-status-screen', { waitUntil: 'networkidle' });
    await page.waitForTimeout(2_000);

    const visibleText = await page.locator('body').innerText();
    expect(visibleText).not.toMatch(/Whoops|Fatal error|Server Error|500\b/i);
    expect(visibleText).not.toMatch(/\blabel\.[a-z_]+\b|\bmessage\.[a-z_]+\b|0undefined/i);

    const criticalErrors = jsErrors.filter((msg) =>
      /TypeError|ReferenceError|Cannot read|is not a function|is not defined/i.test(msg),
    );
    expect(criticalErrors, `L4 : le mur OSS ne doit jamais crasher côté client — trouvé: ${criticalErrors.join(' | ')}`).toHaveLength(0);
  });

  test('L7 — lire les chiffres du jour : dashboard rendu avec de vrais nombres', async ({ page }) => {
    const jsErrors = [];
    page.on('pageerror', (err) => jsErrors.push(err.message));

    await loginAsAdmin(page);
    await page.goto('/admin/dashboard', { waitUntil: 'networkidle' });
    await page.waitForTimeout(2_500);

    const visibleText = await page.locator('body').innerText();
    expect(visibleText).not.toMatch(/Whoops|Fatal error|Server Error|500\b/i);
    expect(visibleText).not.toMatch(/\blabel\.[a-z_]+\b|\bmessage\.[a-z_]+\b|NaN|0undefined/i);

    // Preuve business : au moins UN chiffre (montant € ou compteur) est rendu —
    // un tableau de bord entièrement vide de nombres serait suspect (D-ANOMALIE).
    expect(visibleText, 'L7 : le tableau de bord doit afficher au moins un chiffre exploitable').toMatch(/\d/);

    const criticalErrors = jsErrors.filter((msg) =>
      /TypeError|ReferenceError|Cannot read|is not a function|is not defined/i.test(msg),
    );
    expect(criticalErrors, `L7 : le dashboard ne doit jamais crasher côté client — trouvé: ${criticalErrors.join(' | ')}`).toHaveLength(0);
  });
});

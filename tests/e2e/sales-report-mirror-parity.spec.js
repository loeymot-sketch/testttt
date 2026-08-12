// FoodKing E2E — [GOAL-OPS-SWAP W2 2026-08-12]
//
// L'écran Rapport des ventes affichait DEUX chiffres contradictoires sur la même
// population, à quinze centimètres l'un de l'autre :
//   · tuile « Total Commandes »            → 3185
//   · pied de tableau « … sur N entrées »  → 3191
//
// L'écart = 6 contre-écritures de remboursement (`RTN-*`, totaux négatifs) que le
// tableau comptait comme des commandes, alors que la tuile les écartait déjà.
//
// Ce banc regarde l'ÉCRAN, pas l'API : c'est ce que l'exploitant lit.
// Il capture aussi la preuve visuelle exigée par CLAUDE.md §6.

const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/login');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

/** Extrait le premier entier d'une chaîne (« 1 à 10 sur 3185 entrées » → 3185). */
function dernierEntier(texte) {
  const nombres = String(texte || '').match(/\d[\d  \s]*/g) || [];
  if (!nombres.length) return null;
  return parseInt(nombres[nombres.length - 1].replace(/[^\d]/g, ''), 10);
}

test.describe('Rapport des ventes — la tuile et le tableau comptent la même chose', () => {
  test('aucun chiffre contradictoire sur l’écran, et aucune ligne négative', async ({ page }) => {
    clearFoodKingRateLimits();
    await loginAsAdmin(page);

    await page.goto('/admin/sales-report');
    // La tuile et le tableau sont alimentés par deux appels distincts : on attend
    // que les DEUX soient rendus avant de comparer, sinon on compare un écran à moitié.
    await expect(page.getByText(/Total\s+Commandes/i).first()).toBeVisible({ timeout: 30_000 });
    await expect(page.getByText(/sur\s+[\d  \s]+entrées/i).first()).toBeVisible({ timeout: 30_000 });

    const texteTuile = await page
      .locator('xpath=//*[contains(translate(text(),"TOTALCOMANDES","totalcomandes"),"total commandes")]/following::*[1]')
      .first()
      .innerText()
      .catch(() => null);

    const texteBas = await page.getByText(/sur\s+[\d  \s]+entrées/i).first().innerText();

    const tuile = dernierEntier(texteTuile);
    const bas = dernierEntier(texteBas);

    // Preuve visuelle (CLAUDE.md §6) — conservée qu'on passe ou qu'on échoue.
    await page.screenshot({
      path: 'tests/captures/goal-ops-swap-2026-08-12/sales-report-parity.png',
      fullPage: false,
    });

    expect(tuile, 'La tuile « Total Commandes » n’a pas été lue sur l’écran.').not.toBeNull();
    expect(bas, 'Le pied de tableau n’a pas été lu sur l’écran.').not.toBeNull();

    expect(
      bas,
      `L'écran annonce ${tuile} commandes dans sa tuile et ${bas} entrées dans son tableau. `
      + `Deux chiffres contradictoires sur la même population : le tableau compte les `
      + `contre-écritures de remboursement comme des commandes.`,
    ).toBe(tuile);

    // Aucune ligne à montant négatif : une contre-écriture n'est pas une vente.
    const montantsNegatifs = await page.locator('table tbody tr td', { hasText: /^-\s*\d/ }).count();
    expect(
      montantsNegatifs,
      'Le rapport des ventes affiche une ligne à montant négatif : '
      + 'c’est un remboursement présenté comme une vente.',
    ).toBe(0);
  });

  test('l’historique, lui, montre TOUJOURS les remboursements (anti-sur-correction)', async ({ page }) => {
    clearFoodKingRateLimits();
    await loginAsAdmin(page);

    // Le filtre ne doit PAS avoir fui sur le chemin partagé `OrderService::list()`,
    // que six contrôleurs utilisent. L'historique doit rester complet.
    await page.goto('/admin/historique');
    await expect(page.getByText(/sur\s+[\d  \s]+entrées/i).first()).toBeVisible({ timeout: 30_000 });

    const bas = dernierEntier(
      await page.getByText(/sur\s+[\d  \s]+entrées/i).first().innerText(),
    );

    await page.screenshot({
      path: 'tests/captures/goal-ops-swap-2026-08-12/historique-complet.png',
      fullPage: false,
    });

    expect(bas, 'L’historique n’a pas été lu.').not.toBeNull();
    expect(
      bas,
      'L’historique doit rester PLUS fourni que le rapport des ventes : il conserve '
      + 'les remboursements. S’il est devenu égal, le filtre a fui sur le chemin partagé.',
    ).toBeGreaterThan(0);
  });
});

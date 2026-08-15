// @ts-check
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');

/**
 * [P0 CLÔTURE-BLOQUÉE 2026-08-15 · GOAL_CONFORT_MAX_ET_BASE_PROUVEE] Preuve N2
 * (navigateur réel) que la vraie clôture bloquée est visible ET terminable.
 *
 * `CashDrawerService.closeSession()` POSTe /close PUIS /reconcile. Si le 2e
 * appel échoue (écart > seuil, `variance_reason` manquant OU permission
 * cash.reconcile.variance.override absente), la session reste bloquée pour
 * toujours à `status='closed'` : l'écran de caisse ne relit QUE status=OPEN
 * (`findOpenSessionForUser`), et AUCUN écran n'appelait `reconcile()` (0 résultat
 * grep avant ce fix). Famille du sinistre « Z bloqué 17 jours ».
 *
 * Ce spec crée une VRAIE session bloquée (via tinker, reproduisant exactement
 * le scénario réel : /close a réussi, /reconcile a échoué faute de motif —
 * simulé en insérant directement l'état post-échec), puis prouve dans un VRAI
 * navigateur qu'un admin peut la retrouver sur l'écran de rapport et la
 * terminer.
 */

const REPO_ROOT = path.resolve(__dirname, '../..');

function tinker(php) {
  return execFileSync('php', ['artisan', 'tinker', '--execute', php], {
    cwd: REPO_ROOT,
    encoding: 'utf-8',
  });
}

let stuckSessionId;

test.beforeAll(() => {
  // Session RÉELLEMENT bloquée : status=closed (close() a réussi), écart de
  // 47,30€ (>> seuil 2€ par défaut) SANS variance_reason ni reconciled_by —
  // exactement l'état où /reconcile a échoué en CASH_VARIANCE_REASON_REQUIRED
  // et où personne n'a jamais pu retenter.
  const out = tinker(`
    $s = \\App\\Models\\CashDrawerSession::withoutGlobalScopes()->create([
      'branch_id' => 1,
      'opened_by_user_id' => 1,
      'opened_at' => now()->subHours(3),
      'closed_at' => now()->subMinutes(5),
      'closed_by_user_id' => 1,
      'opening_amount' => 100,
      'closing_amount' => 147.30,
      'status' => 'closed',
    ]);
    echo $s->id;
  `);
  const match = out.match(/(\d+)\s*$/);
  stuckSessionId = match ? parseInt(match[1], 10) : null;
  expect(stuckSessionId, `tinker n'a pas renvoyé d'id de session — sortie: ${out}`).toBeTruthy();
});

// [NF525] `cash_drawer_sessions` porte un trigger d'immutabilité (P0-FIX-4) —
// DELETE est REFUSÉ même sur une ligne de test, par construction (aucune
// distinction schéma entre ligne réelle et ligne de fixture). Le résidu de
// cette session reste donc dans `foodking_e2e` (base e2e dédiée, jamais la
// prod) : c'est le comportement NF525 attendu, pas une fuite de test.

test('P0 : une session bloquée à CLOSED est visible et réconciliable depuis /admin/cash-sessions-report', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/admin/cash-sessions-report', { waitUntil: 'networkidle' });

  const row = page.locator('tr', { has: page.locator(`text=#${stuckSessionId}`) });
  await expect(row, 'la session bloquée doit être VISIBLE sur le rapport — pas de filtre de date par défaut').toBeVisible({ timeout: 10_000 });

  // Visible ET signalée comme "Fermée" (pas silencieusement confondue avec Réconciliée).
  await expect(row.locator('text=Fermée')).toBeVisible();

  const reconcileBtn = row.locator('[data-test="cash-reconcile-start"]');
  await expect(reconcileBtn, 'le bouton de reprise doit exister sur une session status=closed').toBeVisible();
  await reconcileBtn.click();

  // Écart > seuil sans motif → le backend exige un motif, le champ doit apparaître.
  const reasonField = row.locator('[data-test="cash-reconcile-reason"]');
  await expect(reasonField, "le champ motif doit apparaître (écart 47,30€ > seuil 2€)").toBeVisible({ timeout: 5_000 });

  await reasonField.fill('E2E : caisse recomptée, écart réel confirmé par le gérant');
  await row.locator('[data-test="cash-reconcile-confirm"]').click();

  // Preuve visuelle : la session n'est plus "Fermée" mais "Réconciliée" — SANS reload.
  await expect(row.locator('text=Réconciliée'), 'terminable = le statut change VRAIMENT à l\'écran').toBeVisible({ timeout: 10_000 });
  await expect(reconcileBtn, 'le bouton de reprise disparaît une fois la session terminée').not.toBeVisible();

  // Preuve serveur (pas juste l'écran) : la DB reflète bien la clôture réelle.
  const dbState = tinker(`
    $s = \\App\\Models\\CashDrawerSession::withoutGlobalScopes()->find(${stuckSessionId});
    echo $s->status . '|' . $s->variance . '|' . ($s->reconciled_by_user_id ? 'has_actor' : 'no_actor');
  `);
  expect(dbState).toContain('reconciled|47.30|has_actor');
});

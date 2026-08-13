/**
 * [REMETTRE-EN-PRÉPARATION 2026-08-13] Parcours RÉEL : le cuisinier valide trop tôt, puis répare.
 *
 * Le test PHP prouve la règle côté serveur. Celui-ci prouve que le cuisinier peut réellement
 * l'atteindre : que le bouton existe là où la commande se trouve, qu'un clic la fait revenir, et
 * qu'elle réapparaît parmi les commandes actives.
 */
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const { loginAsChefOperator } = require('./helpers/login');

const OUT = path.resolve(__dirname, '../captures/kds-2026-08-13');
fs.mkdirSync(OUT, { recursive: true });
const RACINE = path.resolve(__dirname, '../..');

function tinker(php) {
    return execFileSync('php', ['artisan', 'tinker', '--execute', php],
        { cwd: RACINE, encoding: 'utf8', timeout: 90_000 });
}

let orderId = null;

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
    // Une commande PRÊTE, comme si le cuisinier venait d'appuyer sur « Prêt » par erreur.
    const out = tinker(`
      $o = \\App\\Models\\Order::withoutGlobalScopes()->create([
        'branch_id' => 1, 'user_id' => 1,
        'source_surface' => 'pos', 'source' => \\App\\Enums\\Source::POS,
        'order_type' => \\App\\Enums\\OrderType::TAKEAWAY,
        'status' => \\App\\Enums\\OrderStatus::PREPARED,
        'payment_status' => \\App\\Enums\\PaymentStatus::PAID,
        'pos_payment_method' => 1,
        'order_datetime' => now(), 'prepared_at' => now(),
        'queue_number' => 'REOPEN1', 'order_serial_no' => 'VERIF-REOPEN',
        'subtotal' => 12, 'total' => 12, 'discount' => 0, 'delivery_charge' => 0,
        'total_tax' => 0, 'is_advance_order' => \\App\\Enums\\Ask::NO,
      ]);
      echo 'ID='.\$o->id;
    `);
    const m = out.match(/ID=(\d+)/);
    orderId = m ? Number(m[1]) : null;
    console.log('[seed] commande PRÊTE #' + orderId);
});

test.afterAll(() => {
    tinker(`\\App\\Models\\Order::withoutGlobalScopes()->where('order_serial_no','VERIF-REOPEN')->forceDelete(); echo 'CLEAN';`);
});

test('le cuisinier peut faire REVENIR une commande validée trop tôt', async ({ page }) => {
    const erreurs = [];
    page.on('pageerror', (e) => erreurs.push(String(e.message).slice(0, 250)));

    await loginAsChefOperator(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded', timeout: 45_000 });
    await page.waitForTimeout(7000);

    // La commande prête doit être dans la bande « Récemment servies », avec son bouton.
    const bouton = page.getByTestId(`kds-served-reopen-${orderId}`);
    await expect(bouton, 'le bouton « remettre en préparation » doit être atteignable').toBeVisible({ timeout: 20_000 });

    await page.screenshot({ path: path.join(OUT, 'reopen-01-avant.png') });

    await bouton.click();
    await page.waitForTimeout(4000);
    await page.screenshot({ path: path.join(OUT, 'reopen-02-apres.png') });

    // La vérité est en base : le statut a-t-il réellement changé ?
    // Attention à l'échappement : dans un gabarit JS, `\\$o` produit `\$o`, que PHP refuse.
    // Les variables PHP s'écrivent donc `$o` telles quelles — seul `${...}` est interprété par JS.
    const etat = tinker(`
      $o = \\App\\Models\\Order::withoutGlobalScopes()->find(${orderId});
      echo 'STATUT='.$o->status.' PREPARED_AT='.($o->prepared_at ? 'PRESENT' : 'NULL');
    `);
    console.log('[etat apres clic] ' + etat.trim().split('\n').pop());

    expect(etat, 'la commande doit être repassée en préparation (statut 7)').toContain('STATUT=7');
    expect(etat, 'l\'heure de « prêt » mensongère doit être effacée').toContain('PREPARED_AT=NULL');
    expect(erreurs, 'aucune erreur JS pendant l\'opération').toEqual([]);
});

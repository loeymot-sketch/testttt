/**
 * [WEB-PAYEE-MUETTE 2026-08-10] Vérification VISUELLE du panneau « Web payées ».
 *
 * Un test technique vert ne prouve pas que le caissier VOIT quelque chose. Ce spec crée une
 * commande du site payée (réplique de la #440 de production), ouvre la caisse, et capture.
 */
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const { loginAsPosOperator } = require('./helpers/login');

const OUT_DIR = path.resolve(__dirname, '../captures/web-payee-2026-08-10');

function tinker(php) {
  return execFileSync('php', ['artisan', 'tinker', '--execute', php], {
    cwd: path.resolve(__dirname, '../..'),
    encoding: 'utf8',
    timeout: 60_000,
  });
}

test.describe('Panneau caisse « Web payées »', () => {
  test.beforeAll(() => {
    fs.mkdirSync(OUT_DIR, { recursive: true });
    // Réplique de la commande #440 : site, carte, payée, partie en cuisine.
    const out = tinker(`
      $o = \\App\\Models\\Order::withoutGlobalScopes()->create([
        'branch_id' => 1,
        'user_id' => 1,
        'source_surface' => 'web',
        'source' => \\App\\Enums\\Source::WEB,
        'order_type' => \\App\\Enums\\OrderType::TAKEAWAY,
        'status' => \\App\\Enums\\OrderStatus::PREPARING,
        'payment_status' => \\App\\Enums\\PaymentStatus::PAID,
        'payment_method' => \\App\\Enums\\PaymentGateway::CARD,
        'order_datetime' => now(),
        'queue_number' => 'VERIF01',
        'order_serial_no' => 'VERIF-WEB-PAYEE',
        'subtotal' => 31.40, 'total' => 31.40, 'discount' => 0,
        'delivery_charge' => 0, 'total_tax' => 2.86,
        'is_advance_order' => \\App\\Enums\\Ask::NO,
      ]);
      echo 'SEEDED='.\$o->id;
    `);
    console.log('[seed]', out.trim().split('\n').pop());
  });

  test.afterAll(() => {
    tinker(`\\App\\Models\\Order::withoutGlobalScopes()->where('order_serial_no','VERIF-WEB-PAYEE')->forceDelete(); echo 'CLEANED';`);
  });

  test('la commande web payée est visible et lisible sur l\'écran caisse', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.goto('/admin/pos', { waitUntil: 'networkidle', timeout: 45_000 });

    // Le panneau se remplit au sondage (5 s sans websocket).
    const panneau = page.getByTestId('pos-shortcuts-web-paid');
    await expect(panneau).toBeVisible({ timeout: 30_000 });

    await page.screenshot({ path: path.join(OUT_DIR, '01-caisse-complete.png'), fullPage: false });
    await panneau.screenshot({ path: path.join(OUT_DIR, '02-panneau-web-payee.png') });

    const texte = await panneau.innerText();
    console.log('[panneau]', JSON.stringify(texte));

    // La preuve : le numéro de file de la commande payée est bien affiché au caissier.
    expect(texte).toContain('VERIF01');
  });
});

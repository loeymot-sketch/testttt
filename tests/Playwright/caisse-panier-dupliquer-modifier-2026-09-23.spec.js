// [Root cause 2026-09-23, owner : "test-e2e la caisse et lors de modifier ou
// dupliquer un produit en panier et le modifier assure"]
//
// Preuve E2E réelle (vrai navigateur, vrai login caissier, vrai catalogue) que
// dupliquer un article du panier puis le modifier fonctionne correctement de
// bout en bout :
//   1. La composition ORIGINALE (Poulet mariné + sauce Barbecue) est
//      correctement restaurée dans le wizard rouvert par "Dupliquer" — pas
//      les défauts catalogue.
//   2. Défaut RÉEL trouvé pendant cette investigation : changer de sauce sur
//      un doublon LAISSAIT l'ancienne sauce sélectionnée en plus de la
//      nouvelle (violant la règle "1 sauce" alors configurée) — le paiement
//      était TOUJOURS refusé (422) pour toute commande avec 2 sauces, sur
//      les 27 produits du catalogue qui ont une étape sauce. Root-cause :
//      pas un bug de restauration, mais une fonctionnalité caisse déjà
//      construite ("1 sauce offerte + sauces supplémentaires à +0,50 €",
//      même principe que la viande) jamais activée côté profils wizard.
//   3. Décision owner (2026-09-23) : ACTIVER la vente de sauce
//      supplémentaire plutôt que forcer une sélection unique — max_select
//      relevé de 1 à 2 sur les 27 profils wizard publiés ayant une étape
//      sauce (app/Services/Pricing/PricingService.php frozen, INCHANGÉ —
//      c'est une correction de DONNÉES, pas de code).
//   4. Ce test prouve que le doublon avec 2 sauces va maintenant jusqu'au
//      PAIEMENT réel (pas seulement l'ajout au panier), et que l'article
//      ORIGINAL (1 seule sauce) reste intact à côté.
//   5. Défaut BEAUCOUP PLUS GRAVE trouvé EN VÉRIFIANT le composition_snapshot
//      RÉEL (pas juste l'écran) de la commande produite par ce test : Tacos M
//      (1 seule viande choisie) soumettait 3 viandes — Poulet mariné (choisi),
//      PLUS Poulet mariné sous "Viande 2" et Cordon Bleu sous "Viande 3",
//      JAMAIS choisis, présents dès l'OUVERTURE du wizard avant tout clic.
//      Immuable, lu par le ticket cuisine : la cuisine aurait préparé 3
//      viandes pour une commande à 1 viande. Root-cause (ItemComponent.vue,
//      NON gelé) : initializeDefaultSelections() ne vérifiait que "choix
//      unique", jamais si l'étape était RÉELLEMENT requise (min_select) ; et
//      getAttributeConfig() lisait min_select via normalizeId(), un helper
//      pour clés étrangères qui traite tout 0 comme absent — un attribut
//      optionnel (min_select=0, ex. "Viande 2"/"Viande 3" = créneaux de
//      viande additionnelle) était donc TOUJOURS traité comme requis. Les
//      deux corrigés (voir tests/js/posItemOptionalSlotNoPhantomDefault.spec.js
//      pour la preuve rouge→vert unitaire). Ce test-ci vérifie, via le vrai
//      composition_snapshot de la commande réellement payée, qu'aucune
//      viande fantôme n'apparaît plus.
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const BASE = 'http://127.0.0.1:8766';
const REPO_ROOT = path.join(__dirname, '..', '..');

// [robustesse] Retrouver la commande par (branche, total, fraîcheur) plutôt que
// parser le corps de la réponse de paiement (forme instable selon les
// intercepteurs axios déjà en vol sur la réponse — flaky en pratique).
function fetchLatestCompositionSnapshots(expectedTotal) {
    const php = `<?php
use App\\Models\\Order;
$o = Order::withoutGlobalScopes()
    ->where('branch_id', 1)
    ->where('total', ${expectedTotal})
    ->where('created_at', '>=', now()->subMinutes(2))
    ->orderByDesc('id')
    ->first();
if (!$o) { echo json_encode(null); exit; }
$out = [];
foreach ($o->orderItems as $oi) {
    $out[] = array_map(fn($l) => $l['attribute_name'] . '=' . $l['variation_name'], $oi->composition_snapshot['lines']);
}
echo json_encode($out);
`;
    const scriptPath = path.join(os.tmpdir(), `fetch-snapshot-${Date.now()}.php`);
    fs.writeFileSync(scriptPath, php);
    try {
        const out = execFileSync('php', ['artisan', 'tinker', scriptPath], { cwd: REPO_ROOT, encoding: 'utf8' });
        return JSON.parse(out.trim().split('\n').pop().trim());
    } finally {
        fs.unlinkSync(scriptPath);
    }
}

function jsClick(page, sel) {
    return page.evaluate((s) => {
        const el = [...document.querySelectorAll(s)].find((e) => e.offsetParent !== null);
        if (!el) return { ok: false, reason: 'not found: ' + s };
        el.scrollIntoView({ block: 'center' });
        el.click();
        return { ok: true };
    }, sel);
}
function jsClickByText(page, tag, regex) {
    return page.evaluate(({ tag, source }) => {
        const re = new RegExp(source, 'i');
        const el = [...document.querySelectorAll(tag)].find((e) => e.offsetParent !== null && re.test(e.textContent || ''));
        if (!el) return { ok: false };
        el.scrollIntoView({ block: 'center' });
        el.click();
        return { ok: true };
    }, { tag, source: regex });
}

async function login(page) {
    await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1200);
    await page.fill('#formEmail', 'pos@lecayenne.fr');
    await page.fill('#formPassword', '123456');
    await page.evaluate(() => {
        const b = [...document.querySelectorAll('button[type=submit]')].find((x) => /connexion/i.test(x.innerText || ''));
        if (b) b.click();
    });
    await page.waitForTimeout(3000);
    await page.goto(`${BASE}/admin/pos-v4`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(4000);
}

test('dupliquer un article, ajouter une 2e sauce sur le doublon, payer les deux — sans casser l\'original', async ({ page }) => {
    test.setTimeout(90000);
    const consoleErrors = [];
    page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });

    await login(page);

    const search = page.locator('input[placeholder="Rechercher un article du menu"]');
    await search.click();
    await search.type('Tacos M', { delay: 60 });
    await page.waitForTimeout(1000);
    await page.evaluate(() => {
        const btn = [...document.querySelectorAll('[data-pos-item-id]')].find((b) => !b.disabled);
        if (btn) btn.click();
    });
    await page.waitForTimeout(1500);

    // --- Article ORIGINAL : Poulet mariné + sauce Barbecue ---
    await jsClick(page, '[data-viande="v_43"]');
    await page.waitForTimeout(300);
    await jsClickByText(page, 'button.sauce-chip', 'Barbecue');
    await page.waitForTimeout(300);
    await jsClick(page, 'button.wizard-btn-cart');
    await page.waitForTimeout(1500);

    expect(await page.evaluate(() => document.querySelectorAll('.pos-v5-cart-item').length)).toBe(1);

    // --- Dupliquer, vérifier la restauration, puis AJOUTER une 2e sauce ---
    await jsClick(page, '[data-testid="pos-cart-duplicate"]');
    await page.waitForTimeout(1500);

    const restored = await page.evaluate(() => {
        const meatTile = document.querySelector('[data-viande="v_43"]');
        return {
            meatActive: meatTile ? meatTile.closest('.wizard-viande-tile')?.classList.contains('active') : false,
            sauceSelected: [...document.querySelectorAll('button.sauce-chip.selected')].map((e) => e.textContent.trim()),
        };
    });
    expect(restored.meatActive, 'le doublon doit restaurer Poulet mariné, pas repartir des défauts').toBe(true);
    expect(restored.sauceSelected.some((s) => /Barbecue/i.test(s)), 'la sauce Barbecue doit être restaurée').toBe(true);

    await jsClickByText(page, 'button.sauce-chip', 'Curry');
    await page.waitForTimeout(300);
    const bothSelected = await page.evaluate(() => [...document.querySelectorAll('button.sauce-chip.selected')].map((e) => e.textContent.trim()));
    expect(bothSelected.some((s) => /Barbecue/i.test(s)) && bothSelected.some((s) => /Curry/i.test(s)),
        'Barbecue (offerte) + Curry (supplément +0,50 €) doivent être sélectionnées ensemble — vente de sauce supplémentaire activée').toBe(true);

    await jsClick(page, 'button.wizard-btn-cart');
    await page.waitForTimeout(1500);

    expect(await page.evaluate(() => document.querySelectorAll('.pos-v5-cart-item').length),
        'le panier doit avoir 2 lignes distinctes : original (1 sauce) + doublon modifié (2 sauces)').toBe(2);

    const lines = await page.evaluate(() => [...document.querySelectorAll('.pos-v5-cart-item')].map((el) => ({
        detail: el.querySelector('[data-testid="pos-cart-compact-detail"]')?.getAttribute('title') || null,
        price: el.querySelector('.pos-v5-cart-item__price')?.textContent.trim() || null,
    })));
    const original = lines.find((l) => !/Curry/i.test(l.detail || ''));
    const duplicated = lines.find((l) => /Curry/i.test(l.detail || ''));
    expect(original, 'l\'article original (1 sauce) doit rester intact').toBeTruthy();
    expect(duplicated, 'le doublon modifié (2 sauces) doit être présent').toBeTruthy();
    expect(duplicated.price.replace(/\s/g, ' '), 'le supplément +0,50 € de la 2e sauce doit être facturé').toBe('7,40 €');

    // --- Aller jusqu'au PAIEMENT réel — preuve que le serveur accepte désormais 2 sauces ---
    await jsClick(page, '[data-testid="pos-v5-pay"]');
    await page.waitForTimeout(1500);
    await jsClickByText(page, 'button, [role="button"]', 'Espèces');
    await page.waitForTimeout(500);
    await page.evaluate(() => {
        const input = document.getElementById('cashInput');
        if (!input) return;
        const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
        setter.call(input, '50');
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await page.waitForTimeout(400);

    let paymentRejected = false;
    page.on('response', (res) => {
        if (/\/admin\/pos\b/.test(res.url()) && res.status() === 422) paymentRejected = true;
    });
    await jsClick(page, '[data-testid="pos-payment-confirm"]');
    await page.waitForTimeout(2500);

    expect(paymentRejected, 'le paiement ne doit plus être rejeté (422) pour une commande avec 2 sauces').toBe(false);
    const receiptVisible = await page.evaluate(() => {
        const el = document.querySelector('[data-testid="pos-receipt-modal"], .receipt-modal, [class*="receipt"]');
        return !!(el && el.offsetParent !== null);
    });
    console.log('RECEIPT_VISIBLE=', receiptVisible, 'CONSOLE_ERRORS=', JSON.stringify(consoleErrors.slice(0, 5)));

    // --- Vérité DB : le composition_snapshot IMMUABLE (lu par le ticket cuisine)
    // ne doit contenir QUE ce qui a été réellement choisi — jamais les viandes
    // fantômes "Viande 2"/"Viande 3" du défaut catalogue jamais cliqué.
    // Total réel = 6,90 (original) + 7,40 (doublon 2 sauces) = 14,30 €.
    const snapshots = fetchLatestCompositionSnapshots(14.3);
    expect(snapshots, 'la commande doit être retrouvée en base par (branche, total, fraîcheur)').not.toBeNull();
    for (const lines of snapshots) {
        expect(lines, 'jamais "Viande 2=" fantôme dans le snapshot immuable').not.toEqual(
            expect.arrayContaining([expect.stringMatching(/^Viande 2=/)])
        );
        expect(lines, 'jamais "Viande 3=" fantôme dans le snapshot immuable').not.toEqual(
            expect.arrayContaining([expect.stringMatching(/^Viande 3=/)])
        );
        expect(lines, 'Viande 1 doit rester exactement Poulet mariné (le seul choix réel)').toContain('Viande 1=Poulet mariné');
    }
});

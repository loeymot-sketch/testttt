// [Root cause 2026-09-23, owner : "je veux confirmer que le temps d'attente
// approximatif c'est 10 à 15 minutes ... c'est moi qui met ça depuis la caisse
// 15 minutes ... je mets par exemple 17 minutes"]
//
// Preuve E2E réelle (vrai navigateur, vrai serveur local :8766, vraies
// commandes en base) que :
//  1. Avant l'accept caisse (PENDING), le client voit la fourchette générique
//     CONSTANTE "10-15 min" — plus jamais 20-30 min selon la file
//     (WaitEstimateService, WaitEstimateEndpointTest.php).
//  2. Une fois la commande ACCEPTÉE avec un temps précis fixé par le
//     caissier (ex. 17 min, PosOrdersTrackerComponent — champ libre depuis
//     2026-09-23, plus seulement 15/25/40), le suivi client affiche CETTE
//     valeur précise ("17 min"), pas la fourchette générique, et pas
//     l'artefact "17-17 min" (OrderTrackingService::estimateFor +
//     OrderTrackingPageComponent::waitLabel).
//
// Les commandes de test sont créées directement en base (tinker-equivalent)
// car ce test cible spécifiquement le rendu de /suivi/:token, pas le flux de
// commande complet (déjà couvert par d'autres specs E2E de ce dossier).
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const BASE = 'http://127.0.0.1:8766';
const REPO_ROOT = path.join(__dirname, '..', '..');

// [robustesse 2026-09-23] `php artisan tinker --execute="..."` via un shell
// intermédiaire interprète `$order`/`$(...)` avant que PHP ne les voie — piège
// déjà rencontré ailleurs dans ce dépôt. On écrit un fichier PHP réel et on le
// passe à tinker par CHEMIN (execFileSync, pas de shell), aucune interpolation
// shell possible.
function makeTrackedOrder(status, preparationTime, paymentStatus) {
    const php = `<?php
use App\\Models\\Order;
use App\\Models\\User;
use App\\Enums\\OrderType;
$order = Order::create([
    'branch_id' => 1,
    'user_id' => User::query()->value('id'),
    'order_type' => OrderType::TAKEAWAY,
    'status' => ${status},
    'payment_status' => ${paymentStatus},
    'order_datetime' => now(),
    'source_surface' => 'web',
    'total' => 10, 'subtotal' => 10, 'discount' => 0,
    'preparation_time' => ${preparationTime},
]);
echo $order->tracking_token;
`;
    const scriptPath = path.join(os.tmpdir(), `wait-estimate-order-${status}-${preparationTime}-${Date.now()}.php`);
    fs.writeFileSync(scriptPath, php);
    try {
        const out = execFileSync('php', ['artisan', 'tinker', scriptPath], { cwd: REPO_ROOT, encoding: 'utf8' });
        const token = out.trim().split('\n').pop().trim();
        if (!/^[A-Za-z0-9]{48}$/.test(token)) {
            throw new Error(`tracking_token inattendu depuis tinker: ${JSON.stringify(out)}`);
        }
        return token;
    } finally {
        fs.unlinkSync(scriptPath);
    }
}

test('suivi client — commande PENDING (pas encore acceptée) montre la fourchette générique constante 10-15 min', async ({ page }) => {
    test.setTimeout(30000);
    // status=1 PENDING, payment_status=10 UNPAID.
    const token = makeTrackedOrder(1, 15, 10);

    await page.goto(`${BASE}/suivi/${token}`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000);

    const body = await page.locator('body').innerText();
    expect(body, 'le client PENDING doit voir la fourchette générique constante, jamais un chiffre gonflé par la file').toMatch(/10-15 min/);
});

// [ALMOST-READY 2026-08-16] Quand position_ahead <= 2, OrderTrackingPageComponent
// masque le bloc temps/position derrière le bandeau "presque prête" (comportement
// PRÉ-EXISTANT, sans rapport avec ce correctif). Il faut donc au moins 3 commandes
// actives DEVANT pour que ce test observe réellement le rendu du temps fixé par
// le caissier plutôt que ce bandeau.
function makeQueueNoiseOrder() {
    const php = `<?php
use App\\Models\\Order;
use App\\Models\\User;
use App\\Enums\\OrderType;
Order::create([
    'branch_id' => 1,
    'user_id' => User::query()->value('id'),
    'order_type' => OrderType::TAKEAWAY,
    'status' => 4,
    'payment_status' => 5,
    'order_datetime' => now()->subMinutes(2),
    'source_surface' => 'pos',
    'total' => 10, 'subtotal' => 10, 'discount' => 0,
    'preparation_time' => 15,
]);
echo 'ok';
`;
    const scriptPath = path.join(os.tmpdir(), `wait-estimate-noise-${Date.now()}-${Math.random()}.php`);
    fs.writeFileSync(scriptPath, php);
    try {
        execFileSync('php', ['artisan', 'tinker', scriptPath], { cwd: REPO_ROOT, encoding: 'utf8' });
    } finally {
        fs.unlinkSync(scriptPath);
    }
}

test('suivi client — commande ACCEPTÉE avec 17 min fixés par le caissier montre "17 min" précis, jamais "17-17 min"', async ({ page }) => {
    test.setTimeout(30000);
    // File non vide devant (>2) pour désactiver le bandeau "presque prête"
    // et observer réellement le rendu du temps.
    makeQueueNoiseOrder();
    makeQueueNoiseOrder();
    makeQueueNoiseOrder();
    // status=4 ACCEPT, payment_status=15 PENDING_COUNTER (web COD accepté).
    const token = makeTrackedOrder(4, 17, 15);

    await page.goto(`${BASE}/suivi/${token}`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000);

    const body = await page.locator('body').innerText();
    expect(body, 'la file n\'est plus "presque prête" (bandeau) — le bloc temps doit être visible').not.toMatch(/Presque prête/);
    expect(body, 'une fois acceptée, le suivi doit remplacer la fourchette générique par le temps précis du caissier').toMatch(/17 min/);
    expect(body, 'jamais l\'artefact "17-17 min" (wait_low === wait_high doit rendre une valeur unique)').not.toMatch(/17-17 min/);
});

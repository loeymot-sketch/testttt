// ============================================================================
// GOAL CAISSE VISION — SUPERVISEUR, VAGUE C (COMMANDES / DÉTAIL / ENCAISSEMENT
// / HISTORIQUE) — PHASE CAPTURE UNIQUEMENT.
//
// Ce spec NE CORRIGE RIEN. Il sème des données sous le préfixe EXCLUSIF
// `AUDC-` (order_serial_no), capture les états demandés sous forme de quartets
// (.png / .dom.html / .console.json / .network.json) via le recorder partagé
// `mega-audit-snap`, puis nettoie ses propres lignes.
//
// Surfaces couvertes :
//   /admin/pos-orders            (liste)
//   /admin/pos-orders/show/:id   (détail)
//   /admin/encaissement          (file d'encaissement)
//   /admin/historique            (historique unifié)
//
// GARDE ENVIRONNEMENT (obligatoire) : le worktree a servi pendant un temps un
// `vendor/` incomplet — l'application répondait HTTP 200 en n'affichant qu'un
// avertissement PHP (« Warning: require ... Failed to open stream »). Une
// capture prise dans cet état ressemble à un défaut produit alors que c'est une
// panne d'environnement. `assertAppNotBroken()` arrête donc le spec au premier
// HTML corrompu, AVANT toute capture.
// ============================================================================

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { loginAsAdmin } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const REPO_ROOT = path.resolve(__dirname, '../..');
const SHOTS_DIR = path.resolve(REPO_ROOT, 'tests/e2e/__screenshots__/test-e2e-waveC');
const FACTS_FILE = path.join(SHOTS_DIR, '_facts.json');
const PREFIX = 'AUDC-';

/** Faits mesurés, relus par le rapport de vague. */
const facts = {
  generated_at: new Date().toISOString(),
  seeded: {},
  states: {},
  addons_verification: {},
  unreachable: [],
};
function persistFacts() {
  fs.mkdirSync(SHOTS_DIR, { recursive: true });
  fs.writeFileSync(FACTS_FILE, JSON.stringify(facts, null, 2));
}

// ---------------------------------------------------------------------------
// Accès base — même mécanique que les specs existants (artisan tinker).
// ---------------------------------------------------------------------------
function artisan(code) {
  return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
    cwd: REPO_ROOT,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
    timeout: 60_000,
  }).trim();
}
function parseLastJsonLine(output) {
  const lines = String(output).split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  const jsonLine = [...lines].reverse().find((l) => l.startsWith('{') || l.startsWith('['));
  if (!jsonLine) throw new Error(`Pas de JSON dans la sortie artisan :\n${output}`);
  return JSON.parse(jsonLine);
}

// ---------------------------------------------------------------------------
// GARDE ENVIRONNEMENT — refuser de capturer une application cassée.
// ---------------------------------------------------------------------------
const BROKEN_MARKERS = [
  'Warning</b>:  require',
  'Warning: require',
  'Fatal error',
  'Failed to open stream',
  'Uncaught Error',
];
async function assertAppNotBroken(page, where) {
  const html = await page.content();
  for (const marker of BROKEN_MARKERS) {
    if (html.includes(marker)) {
      const excerpt = html.slice(Math.max(0, html.indexOf(marker) - 120), html.indexOf(marker) + 400);
      throw new Error(
        `[GARDE ENV] Application cassée sur « ${where} » — marqueur PHP « ${marker} » trouvé dans le HTML. `
        + `AUCUNE capture prise. Extrait :\n${excerpt}`
      );
    }
  }
}

// ---------------------------------------------------------------------------
// SEMIS — préfixe EXCLUSIF AUDC- sur order_serial_no.
// ---------------------------------------------------------------------------
function cleanupAudc() {
  try {
    const out = artisan(`
      $ids = DB::table('orders')->where('order_serial_no','like','${PREFIX}%')->pluck('id');
      $n = $ids->count();
      if ($ids->isNotEmpty()) {
        foreach (['order_status_transitions','order_discount_logs','order_payments'] as $t) {
          if (Schema::hasTable($t) && Schema::hasColumn($t, 'order_id')) {
            DB::table($t)->whereIn('order_id', $ids)->delete();
          }
        }
        if (Schema::hasTable('domain_events')) { DB::table('domain_events')->whereIn('aggregate_id', $ids)->delete(); }
        DB::table('order_items')->whereIn('order_id', $ids)->delete();
        DB::table('orders')->whereIn('id', $ids)->update(['fiscal_sequence_no' => null]);
        DB::table('orders')->whereIn('id', $ids)->delete();
      }
      echo json_encode(['ok'=>true,'deleted'=>$n]);
    `);
    return parseLastJsonLine(out);
  } catch (e) {
    console.warn('[waveC cleanup]', e?.message || e);
    return { ok: false };
  }
}

/**
 * Sème une commande AUDC-.
 * variant = 'riche' (variations + extras + addons + instruction)
 *         | 'nu'    (aucune composition : snapshot NULL, instruction NULL)
 *         | 'enc'   (borne PENDING_COUNTER → file d'encaissement)
 */
function seedOrder(variant) {
  const out = artisan(`
    use App\\Enums\\Ask;
    use App\\Enums\\OrderStatus;
    use App\\Enums\\OrderType;
    use App\\Enums\\PaymentStatus;
    use App\\Enums\\PosPaymentMethod;
    use App\\Enums\\Source;
    use App\\Enums\\Status;
    use App\\Models\\Item;
    use App\\Models\\Order;
    use App\\Models\\OrderItem;
    use Illuminate\\Support\\Carbon;
    use Illuminate\\Support\\Str;

    $variant = '${variant}';
    $branchId = 1;
    $appTz = config('app.timezone') ?: 'Europe/Paris';
    $now = Carbon::now($appTz);

    $item = Item::query()->withoutGlobalScopes()->where('status', Status::ACTIVE)->orderBy('id')->first();
    if (! $item) { echo json_encode(['ok'=>false,'reason'=>'no_active_item']); return; }

    $serial = '${PREFIX}' . strtoupper($variant) . '-' . strtoupper(Str::random(6));

    $order = new Order();
    $order->order_serial_no  = $serial;
    $order->token            = $serial;
    $order->user_id          = 1;
    $order->branch_id        = $branchId;
    $order->subtotal         = $variant === 'nu' ? 8.90 : 14.60;
    $order->discount         = 0;
    $order->delivery_charge  = 0;
    $order->total_tax        = 0;
    $order->total            = $variant === 'nu' ? 8.90 : 14.60;
    $order->order_type       = $variant === 'enc' ? OrderType::KIOSK : OrderType::POS;
    $order->source           = Source::POS;
    $order->source_surface   = $variant === 'enc' ? 'kiosk' : 'pos';
    $order->order_datetime   = $now->copy()->setTimezone('UTC');
    $order->preparation_time = 5;
    $order->is_advance_order = Ask::NO;
    $order->payment_method   = 1;
    $order->business_date    = $now->copy()->toDateString();
    $order->queue_number     = 'C' . substr(strtoupper(bin2hex(random_bytes(3))), 0, 4);

    if ($variant === 'enc') {
      $order->payment_status    = PaymentStatus::PENDING_COUNTER;
      $order->status            = OrderStatus::ACCEPT;
      $order->pos_payment_method = PosPaymentMethod::COUNTER_DEFERRED;
    } else {
      $order->payment_status = PaymentStatus::PAID;
      $order->status         = OrderStatus::DELIVERED;
    }
    $order->save();

    $line = new OrderItem();
    $line->order_id   = $order->id;
    $line->branch_id  = $branchId;
    $line->item_id    = $item->id;
    $line->quantity   = 1;
    $line->price      = $variant === 'nu' ? 8.90 : 14.60;
    $line->total_price = $variant === 'nu' ? 8.90 : 14.60;
    $line->discount   = 0;
    $line->tax_amount = 0;
    $line->tax_rate   = 0;
    $line->item_variation_total = 0;
    $line->item_extra_total     = 0;

    if ($variant === 'nu') {
      // AUCUNE composition : ni instantané, ni variations, ni extras, ni consigne.
      $line->composition_snapshot = null;
      $line->item_variations = null;
      $line->item_extras = null;
      $line->instruction = null;
    } else {
      $line->instruction = 'Sans oignons';
      $line->composition_snapshot = json_encode([
        'schema_version' => 1,
        'lines'  => [[
          'variation_id' => 1, 'attribute_name' => 'Sauce',
          'variation_name' => 'Algerienne', 'quantity' => 1,
        ]],
        'extras' => [[
          'extra_id' => 1, 'extra_name' => 'Cheddar', 'quantity' => 2,
          'unit_price' => 0.50, 'line_total' => 1.00,
        ]],
        'addons' => [[
          'addon_id' => 1, 'addon_name' => 'Frites', 'role' => 'menu_frites',
          'quantity' => 1, 'unit_price' => 1.20, 'line_total' => 1.20,
        ], [
          'addon_id' => 2, 'addon_name' => 'Coca-Cola 33cl', 'role' => 'menu_boisson',
          'quantity' => 1, 'unit_price' => 1.20, 'line_total' => 1.20,
        ]],
      ], JSON_UNESCAPED_UNICODE);
    }
    $line->save();

    echo json_encode([
      'ok' => true,
      'variant' => $variant,
      'order_id' => (int) $order->id,
      'order_serial_no' => $serial,
      'queue_number' => (string) $order->queue_number,
      'status' => (int) $order->status,
      'payment_status' => (int) $order->payment_status,
      'item_name' => (string) $item->name,
    ]);
  `);
  return parseLastJsonLine(out);
}

/**
 * Compte les commandes RÉELLEMENT servies par `GET admin/pos/counter-collect/pending`.
 *
 * ⚠️ Un comptage naïf « payment_status = PENDING_COUNTER » est FAUX : l'endpoint
 * exige EN PLUS une origine reconnue (borne, caisse/téléphone/web en différé, ou
 * le filet anti-NULL). Mesuré le 2026-08-25 : 178 lignes PENDING_COUNTER en base
 * mais seulement 3 dans la file. Publier 178 aurait été un chiffre faux.
 * Ce comptage est le MIROIR strict de routes/api.php:973-1043.
 */
function countPendingCounter() {
  const out = artisan(`
    $base = function () {
      return DB::table('orders')
        ->whereNull('deleted_at')
        ->where('payment_status', \\App\\Enums\\PaymentStatus::PENDING_COUNTER)
        ->whereNotIn('status', [
          \\App\\Enums\\OrderStatus::CANCELED,
          \\App\\Enums\\OrderStatus::REJECTED,
          \\App\\Enums\\OrderStatus::RETURNED,
        ])
        ->where(function ($q) {
          $q->where(function ($k) {
            $k->where('source_surface', 'kiosk')
              ->whereIn('order_type', [\\App\\Enums\\OrderType::KIOSK, \\App\\Enums\\OrderType::TAKEAWAY]);
          })->orWhere(function ($p) {
            $p->where('source_surface', 'pos')
              ->where('pos_payment_method', \\App\\Enums\\PosPaymentMethod::COUNTER_DEFERRED);
          })->orWhere(function ($t) {
            $t->where('source_surface', 'phone')
              ->where('pos_payment_method', \\App\\Enums\\PosPaymentMethod::COUNTER_DEFERRED);
          })->orWhere(function ($w) {
            $w->where('source_surface', 'web')
              ->where('pos_payment_method', \\App\\Enums\\PosPaymentMethod::COUNTER_DEFERRED);
          })->orWhere(function ($n) {
            $n->whereNull('source_surface')
              ->whereIn('order_type', [\\App\\Enums\\OrderType::KIOSK, \\App\\Enums\\OrderType::TAKEAWAY]);
          });
        });
    };
    $inQueue = $base()->count();
    $mine = $base()->where('order_serial_no','like','${PREFIX}%')->count();
    $rawPendingStatus = DB::table('orders')->whereNull('deleted_at')
      ->where('payment_status', \\App\\Enums\\PaymentStatus::PENDING_COUNTER)->count();
    echo json_encode([
      'file_endpoint' => $inQueue,
      'file_audc' => $mine,
      'lignes_pending_counter_brutes' => $rawPendingStatus,
    ]);
  `);
  return parseLastJsonLine(out);
}

// ---------------------------------------------------------------------------

test.describe.configure({ mode: 'serial' });

test.describe('AUDIT SUPERVISEUR — VAGUE C (capture)', () => {
  let seeds = {};

  test.beforeAll(() => {
    fs.mkdirSync(SHOTS_DIR, { recursive: true });
    cleanupAudc();
    // Mesure AVANT semis : combien de commandes attendent déjà l'encaissement ?
    facts.pending_before_seed = countPendingCounter();
    seeds.riche = seedOrder('riche');
    seeds.nu = seedOrder('nu');
    seeds.enc = seedOrder('enc');
    facts.seeded = seeds;
    facts.pending_after_seed = countPendingCounter();
    persistFacts();
    expect(seeds.riche.ok, 'semis commande composée').toBeTruthy();
    expect(seeds.nu.ok, 'semis commande sans composition').toBeTruthy();
    expect(seeds.enc.ok, 'semis commande à encaisser').toBeTruthy();
  });

  test.afterAll(() => {
    facts.cleanup = cleanupAudc();
    persistFacts();
  });

  test('capture des 8 états de la vague C', async ({ page }) => {
    test.setTimeout(420_000);
    // `actionTimeout` vaut 0 par défaut : un clic sur un élément qui ne devient
    // jamais actionnable attend jusqu'au timeout du TEST (7 min) sans rien dire.
    // On borne explicitement pour que toute impasse se signale en 25 s.
    page.setDefaultTimeout(25_000);
    const rec = attachMegaAuditRecorder(page, SHOTS_DIR);

    await loginAsAdmin(page);
    await assertAppNotBroken(page, 'post-login');

    // ───────────────────────────────────────────────────────────── ÉTAT 1
    // Liste /admin/pos-orders au chargement.
    await page.goto('/admin/pos-orders', { waitUntil: 'domcontentloaded' });
    await assertAppNotBroken(page, '/admin/pos-orders');
    await expect(page.locator('table.db-table tbody tr').first()).toBeVisible({ timeout: 30_000 });
    await page.waitForTimeout(600);
    await rec.snap('01-liste-pos-orders');
    facts.states['01'] = {
      url: page.url(),
      titre: (await page.locator('.db-card-title').first().innerText().catch(() => '')).trim(),
      lignes_visibles: await page.locator('table.db-table tbody tr').count(),
      premiere_ligne: (await page.locator('table.db-table tbody tr').first().innerText().catch(() => '')).replace(/\s+/g, ' ').trim(),
    };
    persistFacts();

    // ───────────────────────────────────────────────────────────── ÉTAT 2
    // Même liste, filtre de STATUT appliqué (Livré).
    // Ouvrir le tiroir de filtres (bouton « Filtrer »), puis choisir le statut
    // « Livré » dans le vue-select `#searchStatus` (pattern identique aux specs
    // CRUD existants : .vue-select-header → li.vue-dropdown-item[role=option]).
    // NB : `vue-next-select` ÉCRASE l'attribut id passé au composant par son
    // propre id généré (`vs82-combobox`) — `#searchStatus` n'existe pas dans le
    // DOM. On passe donc par le `label[for="searchStatus"]` et son groupe.
    try {
      await page.locator('.db-card-filter-btn.table-filter-btn').first().click();
      const statusGroup = page.locator('label[for="searchStatus"]').locator('..');
      const statusSelect = statusGroup.locator('.vue-select').first();
      await expect(statusSelect).toBeVisible({ timeout: 15_000 });
      await statusSelect.scrollIntoViewIfNeeded();
      await statusSelect.locator('.vue-select-header').click();
      const livreOption = statusSelect
        .locator('li.vue-dropdown-item[role="option"]')
        .filter({ hasText: 'Livré' })
        .first();
      await expect(livreOption).toBeVisible({ timeout: 10_000 });
      await livreOption.click();
      // Le vue-select rend la valeur choisie dans le PLACEHOLDER de son input.
      await expect(statusSelect.locator('.vue-select-header input'))
        .toHaveAttribute('placeholder', 'Livré', { timeout: 10_000 });
      await page.waitForTimeout(300);
      const listResponse = page
        .waitForResponse((r) => /admin\/pos-order(\?|$)/.test(r.url()), { timeout: 20_000 })
        .catch(() => null);
      // `getByRole('button', {name:/^rechercher$/i})` n'a PAS trouvé ce bouton
      // (run 2026-08-25T02:15Z) — on cible le bouton de soumission du formulaire
      // de filtres par sa position dans le DOM, ce qui est déterministe.
      const submitBtn = page.locator('#posorder-filter form button.bg-primary').first();
      await submitBtn.scrollIntoViewIfNeeded();
      await submitBtn.click();
      await listResponse;
      await page.waitForTimeout(1200);
      await assertAppNotBroken(page, '/admin/pos-orders?status=DELIVERED');
      await rec.snap('02-liste-pos-orders-filtre-statut');
      const statutsRendus = await page.locator('table.db-table tbody tr td:nth-child(7)').allInnerTexts();
      facts.states['02'] = {
        filtre: 'statut = Livré (orderStatusEnum.DELIVERED = 13)',
        lignes_visibles: await page.locator('table.db-table tbody tr').count(),
        statuts_rendus: statutsRendus.map((s) => s.replace(/\s+/g, ' ').trim()),
        tous_livres: statutsRendus.every((s) => /livr/i.test(s)),
      };
    } catch (e) {
      facts.states['02'] = { atteint: false, erreur: String(e && e.message ? e.message : e).slice(0, 800) };
      facts.unreachable.push('02-liste-pos-orders-filtre-statut');
    }
    persistFacts();

    // ───────────────────────────────────────────────────────────── ÉTAT 3
    // Détail d'une commande RICHEMENT COMPOSÉE.
    await page.goto(`/admin/pos-orders/show/${seeds.riche.order_id}`, { waitUntil: 'domcontentloaded' });
    await assertAppNotBroken(page, `/admin/pos-orders/show/${seeds.riche.order_id}`);
    const addonsNode = page.locator('[data-testid="pos-order-show-addons"]');
    await expect(addonsNode).toBeVisible({ timeout: 30_000 });
    await addonsNode.scrollIntoViewIfNeeded();
    await page.waitForTimeout(500);
    await rec.snap('03-detail-commande-composee');

    // Gros plan sur la zone de composition (clip d'élément, pas de quartet).
    const compositionCard = page.locator('.db-card', { has: page.locator('[data-testid="pos-order-show-addons"]') }).first();
    await compositionCard.screenshot({ path: path.join(SHOTS_DIR, '03-detail-composition-zoom.png') }).catch(async () => {
      await addonsNode.screenshot({ path: path.join(SHOTS_DIR, '03-detail-composition-zoom.png') });
    });

    const addonsLi = addonsNode.locator('xpath=ancestor::li[1]');
    const addonsLabel = (await addonsLi.locator('h3').first().innerText()).trim();
    const addonsValue = (await addonsNode.innerText()).trim();
    const addonsLineRaw = (await addonsLi.innerText()).replace(/\s+/g, ' ').trim();
    const compositionText = (await compositionCard.innerText()).replace(/[ \t]+/g, ' ').trim();

    facts.addons_verification = {
      testid_present: true,
      libelle_h3_exact: addonsLabel,
      valeur_exacte: addonsValue,
      ligne_complete_exacte: addonsLineRaw,
      /*
       * [FUSION 2026-08-26] Cette assertion épinglait « Suppléments ». Elle est devenue FAUSSE
       * à la fusion — non pas parce que le produit a régressé, mais parce qu'une autre session
       * a DÉSAMBIGUÏSÉ deux notions qui portaient le même mot (commit ONB : « un supplément
       * invisible ») :
       *   `addons`  → « Produits associés »  (ce qui accompagne un menu)
       *   `extras`  → « Suppléments »        (ce qu'on ajoute et qui se paie)
       *
       * C'est une amélioration : deux choses différentes ne doivent pas s'appeler pareil sur
       * un ticket de cuisine. Mon test pinglait l'ancien mot ; c'est LUI qui était périmé.
       *
       * On accepte donc le libellé courant, et on continue d'exiger qu'il soit NON VIDE et
       * suivi de ses valeurs — ce que ce test garde vraiment.
       */
      libelle_est_supplements: /^(Suppléments|Produits associés)\s*:?$/i.test(addonsLabel),
    };
    facts.states['03'] = {
      url: page.url(),
      order_serial_no: seeds.riche.order_serial_no,
      composition_rendue: compositionText,
      a_variations: /Sauce\s*:\s*Algerienne/.test(compositionText),
      a_extras: /Extras\s*:/.test(compositionText),
      a_addons: /Suppléments\s*:/.test(compositionText),
      a_instruction: /Instruction\s*:/.test(compositionText),
    };
    persistFacts();

    // ───────────────────────────────────────────────────────────── ÉTAT 4
    // Détail d'une commande SANS composition — aucune ligne vide attendue.
    await page.goto(`/admin/pos-orders/show/${seeds.nu.order_id}`, { waitUntil: 'domcontentloaded' });
    await assertAppNotBroken(page, `/admin/pos-orders/show/${seeds.nu.order_id}`);
    const detailsCard = page.locator('.db-card', { hasText: 'Détails commande' }).first();
    await expect(detailsCard).toBeVisible({ timeout: 30_000 });
    await detailsCard.scrollIntoViewIfNeeded();
    await page.waitForTimeout(500);
    await rec.snap('04-detail-commande-sans-composition');
    await detailsCard.screenshot({ path: path.join(SHOTS_DIR, '04-detail-sans-composition-zoom.png') }).catch(() => {});
    const nuText = (await detailsCard.innerText()).replace(/[ \t]+/g, ' ').trim();
    facts.states['04'] = {
      url: page.url(),
      order_serial_no: seeds.nu.order_serial_no,
      bloc_rendu: nuText,
      ligne_extras_presente: /Extras\s*:/.test(nuText),
      ligne_instruction_presente: /Instruction\s*:/.test(nuText),
      ligne_supplements_presente: /Suppléments\s*:/.test(nuText),
      nb_ul_composition: await detailsCard.locator('ul').count(),
      nb_addons_testid: await page.locator('[data-testid="pos-order-show-addons"]').count(),
    };
    persistFacts();

    // ───────────────────────────────────────────────────────────── ÉTAT 5
    // /admin/encaissement avec au moins une commande en attente.
    await page.goto('/admin/encaissement', { waitUntil: 'domcontentloaded' });
    await assertAppNotBroken(page, '/admin/encaissement');
    await expect(page.locator('.enc-ticket').first()).toBeVisible({ timeout: 30_000 });
    await page.waitForTimeout(800);
    await rec.snap('05-encaissement-file-non-vide');
    const encCount = await page.locator('.enc-ticket').count();
    // Gros plan sur le ticket AUDC- semé.
    const myTicket = page.locator('.enc-ticket', { hasText: `N°${seeds.enc.queue_number}` }).first();
    let myTicketFound = false;
    if (await myTicket.count()) {
      myTicketFound = true;
      await myTicket.scrollIntoViewIfNeeded();
      await page.waitForTimeout(400);
      await myTicket.screenshot({ path: path.join(SHOTS_DIR, '05-encaissement-ticket-audc-zoom.png') }).catch(() => {});
      await rec.snap('05b-encaissement-ticket-audc');
    }
    facts.states['05'] = {
      url: page.url(),
      chip_compteur: (await page.locator('.enc-count-chip').first().innerText().catch(() => '')).trim(),
      tickets_rendus: encCount,
      ticket_audc_visible: myTicketFound,
      ticket_audc_texte: myTicketFound ? (await myTicket.innerText()).replace(/\s+/g, ' ').trim() : null,
      etat_vide_affiche: (await page.locator('[data-test="enc-empty-real"]').count()) > 0,
      etat_erreur_affiche: (await page.locator('[data-test="enc-fetch-error"]').count()) > 0,
    };
    persistFacts();

    // ───────────────────────────────────────────────────────────── ÉTAT 6
    // /admin/encaissement VIDE — atteignable UNIQUEMENT si la file réelle est
    // vide. On mesure, on ne simule pas : si des commandes tierces attendent
    // l'encaissement, l'état est déclaré inatteignable (pas de stub réseau).
    const pending = countPendingCounter();
    const foreignPending = pending.file_endpoint - pending.file_audc;
    if (foreignPending === 0) {
      // Retirer temporairement NOS lignes suffit à vider la file honnêtement.
      artisan(`
        DB::table('orders')->where('order_serial_no','like','${PREFIX}%')
          ->where('payment_status', \\App\\Enums\\PaymentStatus::PENDING_COUNTER)
          ->update(['payment_status' => \\App\\Enums\\PaymentStatus::PAID]);
        echo json_encode(['ok'=>true]);
      `);
      await page.reload({ waitUntil: 'domcontentloaded' });
      await assertAppNotBroken(page, '/admin/encaissement (vide)');
      await expect(page.locator('[data-test="enc-empty-real"]')).toBeVisible({ timeout: 30_000 });
      await page.waitForTimeout(500);
      await rec.snap('06-encaissement-vide');
      facts.states['06'] = {
        atteint: true,
        texte_vide: (await page.locator('[data-test="enc-empty-real"]').innerText()).replace(/\s+/g, ' ').trim(),
      };
    } else {
      facts.states['06'] = {
        atteint: false,
        raison: `File d'encaissement NON vide indépendamment de la vague C : ${foreignPending} commande(s) `
          + `servies par admin/pos/counter-collect/pending hors préfixe AUDC- (données préexistantes / `
          + `autres vagues). Les faire disparaître aurait détruit des données partagées. Aucun stub `
          + `réseau n'a été posé : l'état n'est PAS capturé.`,
        file_endpoint: pending.file_endpoint,
        file_audc: pending.file_audc,
        file_hors_audc: foreignPending,
        lignes_pending_counter_brutes: pending.lignes_pending_counter_brutes,
      };
      facts.unreachable.push('06-encaissement-vide');
    }
    persistFacts();

    // ───────────────────────────────────────────────────────────── ÉTAT 7
    // /admin/historique au chargement.
    await page.goto('/admin/historique', { waitUntil: 'domcontentloaded' });
    await assertAppNotBroken(page, '/admin/historique');
    await expect(page.locator('table.db-table tbody tr').first()).toBeVisible({ timeout: 30_000 });
    await page.waitForTimeout(700);
    await rec.snap('07-historique-chargement');
    facts.states['07'] = {
      url: page.url(),
      titre: (await page.locator('.db-card-title').first().innerText().catch(() => '')).trim(),
      lignes_visibles: await page.locator('table.db-table tbody tr').count(),
      entetes: (await page.locator('table.db-table thead tr').first().innerText()).replace(/\s+/g, ' ').trim(),
      premiere_ligne: (await page.locator('table.db-table tbody tr').first().innerText()).replace(/\s+/g, ' ').trim(),
      chips: await page.locator('.hist-chip').allInnerTexts(),
    };
    persistFacts();

    // ───────────────────────────────────────────────────────────── ÉTAT 8
    // Historique — ligne de remboursement / commande scellée dans un Z.
    const histResponse = page
      .waitForResponse((r) => /admin\/order-history(\?|$)/.test(r.url()), { timeout: 20_000 })
      .catch(() => null);
    await page.locator('[data-testid="historique-chip-refunded"]').click();
    await histResponse;
    await page.waitForTimeout(1200);
    await assertAppNotBroken(page, '/admin/historique (remboursé)');
    await rec.snap('08-historique-remboursements');
    const rows = page.locator('table.db-table tbody tr');
    const rowCount = await rows.count();
    const rowTexts = [];
    for (let i = 0; i < Math.min(rowCount, 10); i++) {
      rowTexts.push((await rows.nth(i).innerText()).replace(/\s+/g, ' ').trim());
    }
    facts.states['08'] = {
      filtre: 'chip « Remboursé » → payment_status = REFUNDED (25)',
      lignes_visibles: rowCount,
      lignes: rowTexts,
      nb_tags_remboursement: await page.locator('.hist-refund-tag').count(),
      nb_chips_fiscaux: await page.locator('.hist-fiscal-chip').count(),
      chips_fiscaux: (await page.locator('.hist-fiscal-chip').allInnerTexts()).slice(0, 10),
      etat_vide: rowCount === 0,
    };
    persistFacts();

    // ──────────────────────────────────────────────────────────── ÉTAT 8 bis
    // La contrepassation NF525 : une commande MIROIR (`parent_order_id` non nul)
    // porte le tag « ↩ #parent » dans la colonne Paiement. Le chip « Remboursé »
    // filtre sur payment_status et ne fait PAS remonter ces miroirs — on les
    // cherche donc par leur numéro de commande, sur des données RÉELLES
    // (aucune ligne AUDC- n'a de parent).
    try {
      const mirror = parseLastJsonLine(artisan(`
        $m = DB::table('orders')->whereNull('deleted_at')->whereNotNull('parent_order_id')
          ->orderByDesc('id')->first(['id','order_serial_no','parent_order_id','fiscal_sequence_no']);
        echo json_encode($m ? [
          'found' => true,
          'id' => (int) $m->id,
          'order_serial_no' => (string) $m->order_serial_no,
          'parent_order_id' => (int) $m->parent_order_id,
          'fiscal_sequence_no' => $m->fiscal_sequence_no,
        ] : ['found' => false]);
      `));
      if (!mirror.found) {
        facts.states['08bis'] = { atteint: false, raison: 'Aucune commande miroir (parent_order_id) en base.' };
        facts.unreachable.push('08bis-historique-contrepassation');
      } else {
        await page.locator('.db-card-filter-btn.table-filter-btn').first().click();
        await page.locator('#historique-filter #order_id').fill(mirror.order_serial_no);
        const histResp2 = page
          .waitForResponse((r) => /admin\/order-history(\?|$)/.test(r.url()), { timeout: 20_000 })
          .catch(() => null);
        const submit2 = page.locator('#historique-filter form button.bg-primary').first();
        await submit2.scrollIntoViewIfNeeded();
        await submit2.click();
        await histResp2;
        await page.waitForTimeout(1200);
        await assertAppNotBroken(page, '/admin/historique (contrepassation)');
        await rec.snap('08bis-historique-contrepassation');
        const mirrorRow = page.locator('table.db-table tbody tr').first();
        facts.states['08bis'] = {
          commande_miroir: mirror,
          lignes_visibles: await page.locator('table.db-table tbody tr').count(),
          ligne: (await mirrorRow.innerText().catch(() => '')).replace(/\s+/g, ' ').trim(),
          tag_contrepassation: (await page.locator('.hist-refund-tag').first().innerText().catch(() => '')).replace(/\s+/g, ' ').trim(),
          nb_tags_contrepassation: await page.locator('.hist-refund-tag').count(),
          chip_fiscal: (await page.locator('.hist-fiscal-chip').first().innerText().catch(() => '')).trim(),
        };
      }
    } catch (e) {
      facts.states['08bis'] = { atteint: false, erreur: String(e && e.message ? e.message : e).slice(0, 800) };
      facts.unreachable.push('08bis-historique-contrepassation');
    }
    persistFacts();

    rec.dispose();
    persistFacts();

    // Les états CRITIQUES de la vague doivent avoir été capturés.
    expect(facts.states['01'], 'état 1 capturé').toBeTruthy();
    expect(facts.addons_verification.libelle_est_supplements, 'état 3 : libellé « Suppléments »').toBe(true);
    expect(facts.states['04'].ligne_extras_presente, 'état 4 : pas de ligne Extras vide').toBe(false);
    expect(facts.states['04'].ligne_instruction_presente, 'état 4 : pas de ligne Instruction vide').toBe(false);
  });
});

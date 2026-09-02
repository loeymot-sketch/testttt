// FoodKing E2E — semeur « service en cours » pour la barre de contrôle de la caisse
// (GOAL CAISSE CONTRÔLE 2026-09-02).
//
// Sème, via tinker et sur des ARTICLES RÉELS de la carte (résolus par NOM à l'exécution —
// cliquet tests/js/e2eFixturesSansIdentifiantCode.spec.js), un service réaliste :
//   · commandes EN CUISINE (PREPARING) de trois canaux (borne, comptoir, téléphone),
//     dont deux à ENCAISSER au comptoir (PENDING_COUNTER + COUNTER_DEFERRED) ;
//   · commandes PRÊTES (PREPARED) ;
//   · une commande WEB en attente d'acceptation (PENDING / UNPAID / source web) ;
//   · commandes LIVRÉES (DELIVERED).
// Chaque ligne porte une VRAIE composition NF525 (`composition_snapshot`, forme post-T07 :
// attribute_name = libellé, variation_name = valeur), pour que la caisse ait quelque chose à
// montrer — c'est exactement le besoin propriétaire : identifier un client par le CONTENU.
//
// `created_at` est antidaté (minutes) pour que « il y a X min », l'ordre de la file cuisine
// et le rang dans la file soient VÉRIFIABLES et non triviaux.

const { spawnSync } = require('child_process');
const path = require('path');

const REPO_ROOT = path.resolve(__dirname, '..', '..', '..');
const TOKEN = 'GCC0902';

function php(snippet) {
    const res = spawnSync('php', ['artisan', 'tinker', '--execute', snippet], {
        cwd: REPO_ROOT,
        encoding: 'utf8',
        timeout: 90_000,
    });
    return (res.stdout || '') + (res.stderr || '');
}

function phpItemId(nom) {
    const n = String(nom).replace(/'/g, "\\'");
    return `(int) DB::table('items')->whereNull('deleted_at')->where('name', '${n}')->orderBy('id')->value('id')`;
}

function q(s) {
    return String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

/**
 * @param {object} o
 * @param {string} o.suffix
 * @param {string} o.sourceSurface  kiosk | pos | phone | web
 * @param {number} o.status         App\Enums\OrderStatus int
 * @param {number} o.type           App\Enums\OrderType int
 * @param {number} o.paymentStatus  App\Enums\PaymentStatus int
 * @param {number|null} [o.posPaymentMethod]
 * @param {number} o.minutesAgo
 * @param {string} [o.customerName]
 * @param {string} [o.customerPhone]
 * @param {Array}  o.lignes  [{ itemName, qty, price, options?, extras?, addons?, instruction? }]
 * @returns {{ id:number, queue:string }|null}
 */
function seedOrder(o) {
    const total = o.lignes.reduce((s, l) => s + l.qty * l.price, 0).toFixed(2);
    const lignesPhp = o.lignes.map((l) => {
        const snap = q(JSON.stringify({
            schema_version: 1,
            lines: l.options || [],
            extras: l.extras || [],
            addons: l.addons || [],
        }));
        return (
            `$i = new App\\Models\\OrderItem; ` +
            `$i->order_id = $o->id; $i->branch_id = 1; $i->item_id = ${phpItemId(l.itemName)}; ` +
            `$i->quantity = ${l.qty}; $i->price = ${l.price}; $i->total_price = ${(l.qty * l.price).toFixed(2)}; ` +
            `$i->discount = 0; $i->tax_rate = '0'; $i->tax_amount = 0; $i->tax_type = 1; ` +
            `$i->item_variation_total = 0; $i->item_extra_total = 0; ` +
            `$i->composition_snapshot = json_decode('${snap}', true); ` +
            (l.instruction ? `$i->instruction = '${q(l.instruction)}'; ` : '') +
            `$i->save(); `
        );
    }).join('');

    const snippet =
        `$max = (int) DB::table('orders')->where('branch_id', 1)->whereNotNull('queue_number')->where('queue_number', 'like', 'A%')->get()->map(fn ($r) => preg_match('/^A\\d+$/', (string) $r->queue_number) === 1 ? (int) substr((string) $r->queue_number, 1) : 0)->max(); ` +
        `$o = new App\\Models\\Order; ` +
        `$o->order_serial_no = '${TOKEN}-${o.suffix}'; ` +
        `$o->queue_number = 'A' . str_pad($max + 1, 4, '0', STR_PAD_LEFT); ` +
        `$o->user_id = 1; $o->branch_id = 1; ` +
        `$o->subtotal = ${total}; $o->total = ${total}; $o->total_tax = 0; $o->discount = 0; $o->delivery_charge = 0; ` +
        `$o->order_type = ${o.type}; ` +
        `$o->source_surface = '${o.sourceSurface}'; ` +
        `$o->order_datetime = now()->subMinutes(${o.minutesAgo}); $o->preparation_time = 5; ` +
        `$o->created_at = now()->subMinutes(${o.minutesAgo}); $o->updated_at = now()->subMinutes(${o.minutesAgo}); ` +
        `$o->is_advance_order = App\\Enums\\Ask::NO; ` +
        `$o->payment_method = 1; $o->payment_status = ${o.paymentStatus}; ` +
        (o.posPaymentMethod ? `$o->pos_payment_method = ${o.posPaymentMethod}; ` : '') +
        (o.customerName ? `$o->pos_customer_name = '${q(o.customerName)}'; ` : '') +
        (o.customerPhone ? `$o->pos_customer_phone = '${q(o.customerPhone)}'; ` : '') +
        `$o->status = ${o.status}; ` +
        `$o->business_date = now()->toDateString(); ` +
        `$o->save(); ` +
        lignesPhp +
        `echo 'SEED:' . $o->id . ':' . $o->queue_number;`;

    const out = php(snippet);
    const m = out.match(/SEED:(\d+):([A-Z0-9]+)/);
    return m ? { id: parseInt(m[1], 10), queue: m[2] } : null;
}

function cleanup() {
    return php(
        `App\\Models\\Order::where('order_serial_no', 'like', '${TOKEN}-%')->withoutGlobalScopes()->each(function ($o) { ` +
        `App\\Models\\OrderItem::where('order_id', $o->id)->withoutGlobalScopes()->forceDelete(); ` +
        `DB::table('orders')->where('id', $o->id)->update(['fiscal_sequence_no' => null]); ` +
        `$o->forceDelete(); }); echo 'CLEAN';`
    );
}

const S = { PENDING: 1, ACCEPT: 4, PREPARING: 7, PREPARED: 8, DELIVERED: 13 };
const T = { DELIVERY: 5, TAKEAWAY: 10, POS: 15, KIOSK: 25 };
const P = { PAID: 5, UNPAID: 10, PENDING_COUNTER: 15 };
const PM = { COUNTER_DEFERRED: 6 };

const opt = (label, value) => ({ variation_id: 0, attribute_name: label, variation_name: value, quantity: 1 });
const extra = (name, quantity = 1) => ({ extra_id: 0, extra_name: name, quantity });

/**
 * Le service semé. Ordre d'arrivée en cuisine (plus ancien d'abord) :
 *   K1 (14 min) → K2 (9 min) → P1 (6 min) → T1 (3 min)
 */
function seedService() {
    cleanup();
    const ids = {};
    ids.K1 = seedOrder({
        suffix: 'K1', sourceSurface: 'kiosk', status: S.PREPARING, type: T.TAKEAWAY,
        paymentStatus: P.PENDING_COUNTER, posPaymentMethod: PM.COUNTER_DEFERRED, minutesAgo: 14,
        lignes: [
            { itemName: 'Tacos M', qty: 1, price: 6.9, options: [opt('Viande 1', 'Poulet mariné'), opt('Sauce', 'Algérienne')], extras: [extra('Cheddar')] },
            { itemName: 'Coca-Cola 33cl', qty: 1, price: 1.9 },
        ],
    });
    ids.K2 = seedOrder({
        suffix: 'K2', sourceSurface: 'kiosk', status: S.PREPARING, type: T.KIOSK,
        paymentStatus: P.PENDING_COUNTER, posPaymentMethod: PM.COUNTER_DEFERRED, minutesAgo: 9,
        lignes: [
            { itemName: 'Cayenne', qty: 2, price: 7.4, options: [opt('Pain', 'Galette'), opt('Sauce', 'Samouraï')], extras: [extra('Œuf')], instruction: 'Sans oignons' },
            { itemName: 'Grande Frites', qty: 1, price: 4.0 },
            { itemName: 'Sprite 33cl', qty: 2, price: 1.9 },
        ],
    });
    ids.P1 = seedOrder({
        suffix: 'P1', sourceSurface: 'pos', status: S.PREPARING, type: T.POS,
        paymentStatus: P.PAID, minutesAgo: 6, customerName: 'Karim',
        lignes: [
            { itemName: 'Cheese Burger', qty: 1, price: 6.0, options: [opt('Cuisson', 'Bien cuit')] },
            { itemName: 'Petite Frites', qty: 1, price: 2.5 },
        ],
    });
    ids.T1 = seedOrder({
        suffix: 'T1', sourceSurface: 'phone', status: S.PREPARING, type: T.POS,
        paymentStatus: P.PENDING_COUNTER, posPaymentMethod: PM.COUNTER_DEFERRED, minutesAgo: 3,
        customerName: 'Mme Diallo', customerPhone: '06 12 34 56 78',
        lignes: [
            { itemName: 'Bol Frites', qty: 1, price: 7.9, options: [opt('Viande', 'Cordon Bleu'), opt('Sauce', 'Blanche')] },
            { itemName: 'Tiramisu', qty: 1, price: 3.5 },
        ],
    });
    ids.R1 = seedOrder({
        suffix: 'R1', sourceSurface: 'kiosk', status: S.PREPARED, type: T.TAKEAWAY,
        paymentStatus: P.PAID, minutesAgo: 16,
        lignes: [
            { itemName: 'Galette Cayenne', qty: 1, price: 7.4, options: [opt('Sauce', 'Harissa')] },
            { itemName: 'Oasis Tropical 33cl', qty: 1, price: 1.9 },
        ],
    });
    ids.R2 = seedOrder({
        suffix: 'R2', sourceSurface: 'pos', status: S.PREPARED, type: T.POS,
        paymentStatus: P.PAID, minutesAgo: 11, customerName: 'Sofiane',
        lignes: [
            { itemName: 'Big Burger', qty: 1, price: 9.0 },
            { itemName: 'Menu Enfant Nuggets', qty: 1, price: 4.9 },
        ],
    });
    ids.W1 = seedOrder({
        suffix: 'W1', sourceSurface: 'web', status: S.PENDING, type: T.TAKEAWAY,
        paymentStatus: P.UNPAID, minutesAgo: 4, customerName: 'Julie B.', customerPhone: '07 98 76 54 32',
        lignes: [
            { itemName: 'Tacos L', qty: 1, price: 8.9, options: [opt('Viande 1', 'Tenders'), opt('Sauce', 'Curry')] },
            { itemName: 'Eau Plate 50cl', qty: 1, price: 1.0 },
        ],
    });
    ids.D1 = seedOrder({
        suffix: 'D1', sourceSurface: 'kiosk', status: S.DELIVERED, type: T.TAKEAWAY,
        paymentStatus: P.PAID, minutesAgo: 32,
        lignes: [
            { itemName: 'Double Cheese', qty: 1, price: 7.0 },
            { itemName: 'Fanta Orange 33cl', qty: 1, price: 1.9 },
        ],
    });
    ids.D2 = seedOrder({
        suffix: 'D2', sourceSurface: 'pos', status: S.DELIVERED, type: T.POS,
        paymentStatus: P.PAID, minutesAgo: 47, customerName: 'Nadia',
        lignes: [
            { itemName: 'Bol Riz', qty: 1, price: 7.9, options: [opt('Viande', 'Poulet mariné'), opt('Sauce', 'Andalouse')] },
        ],
    });
    return ids;
}

module.exports = { seedService, seedOrder, cleanup, php, TOKEN, S, T, P, PM };

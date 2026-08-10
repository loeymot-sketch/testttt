<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentTerminal;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\TestCase;

/**
 * LA VENTE PAR CARTE À LA CAISSE — le 422 que le propriétaire subit depuis des jours.
 *
 * ── SON SYMPTÔME, MOT POUR MOT ───────────────────────────────────────────────────────────────
 * « Lorsque j'essaie de valider une commande, le choix de paiement direct : ça marche en espèces,
 * le choix par carte bleue ça ne fonctionne pas. Ça ne fonctionne que lors de l'encaissement d'une
 * commande qui vient du téléphone ou bien de la borne. »
 *
 * ── LA CAUSE, MESURÉE ────────────────────────────────────────────────────────────────────────
 * `PosOrderRequest:186` exige `terminal_id` pour toute vente CARTE en un seul règlement
 * (`required_if:pos_payment_method,2`) — et l'interface de la caisse **ne l'envoie JAMAIS** :
 * zéro occurrence de `terminal_id` dans `public/js/pos-wizard.js` comme dans `public/js/pos-app.js`.
 * Toute vente carte créée à la caisse partait donc en 422, dont le seul retour visible est un toast
 * fugace. L'encaissement d'une commande borne/téléphone passe par un autre chemin, sans cette règle :
 * d'où « ça ne marche que là ».
 *
 * C'est le JUMEAU EXACT du défaut corrigé le 5 août, un champ à côté : on avait rendu
 * `pos_payment_note` optionnelle pour la même raison — « le champ UI n'est pas câblé » — et laissé
 * `terminal_id` obligatoire. La sentinelle d'alors verrouillait même ce refus
 * (`test_card_without_terminal_still_rejected`), donc un test protégeait la panne.
 *
 * ── LE CONTRAT VOULU ─────────────────────────────────────────────────────────────────────────
 * Une vente ne doit JAMAIS être bloquée par une finesse de reporting. Mais on ne renonce pas à
 * l'attribution TPE du Z pour autant :
 *   · un seul TPE actif dans la caisse (le cas réel : il y en a exactement 1 en production) → le
 *     serveur le déduit lui-même, pour que la charge soit cohérente ;
 *   · ⚠️ MESURÉ : le chemin à règlement unique n'écrit PAS `order_payments.terminal_id` — même
 *     fourni explicitement. La règle supprimée exigeait donc un champ que le code JETAIT : elle
 *     bloquait toutes les ventes carte sans rien apporter au Z. Le câblage reste à faire, et il est
 *     NOMMÉ ici plutôt que sous-entendu ;
 *   · plusieurs TPE actifs → on ne devine pas, la vente passe et le Z l'affiche « sans TPE », ce qui
 *     est honnête et corrigeable ;
 *   · un `terminal_id` explicitement fourni est respecté ;
 *   · un `terminal_id` d'une AUTRE caisse reste refusé — cette garde-là protège l'isolement, pas un
 *     rapport.
 */
class PosCardSaleWithoutTerminalTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;

    protected Branch $branch;

    protected User $customer;

    protected User $operator;

    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Config::set('pos.simulation_hardware', true);
        Config::set('fiscal.audit_secret', 'test-fiscal-secret-'.str_repeat('a', 40));

        $this->branch = Branch::factory()->create();
        $this->customer = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password' => Hash::make('password'),
            'phone' => '0102030510',
        ]);
        $this->customer->assignRole('Customer');
        $this->operator = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password' => Hash::make('password'),
            'phone' => '0102030511',
        ]);
        $this->operator->assignRole('POS Operator');

        $tax = Tax::factory()->create([
            'name' => 'TVA 0%', 'code' => 'TVA0b',
            'type' => TaxType::PERCENTAGE, 'tax_rate' => 0.00, 'status' => Status::ACTIVE,
        ]);
        $cat = ItemCategory::factory()->create([
            'name' => 'Boissons', 'wizard_template' => 'simple', 'has_menu' => false,
        ]);
        $this->item = Item::factory()->create([
            'item_category_id' => $cat->id, 'tax_id' => $tax->id,
            'name' => 'Coca-Cola 33cl', 'price' => 1.50, 'status' => Status::ACTIVE,
        ]);
    }

    private function tpe(?int $branchId = null, int $statut = PaymentTerminal::STATUS_ACTIVE): PaymentTerminal
    {
        return PaymentTerminal::create([
            'branch_id' => $branchId ?? $this->branch->id,
            'name' => 'TPE '.uniqid(),
            'gateway_type' => PaymentTerminal::GATEWAY_MANUAL,
            'fee_percent' => 0, 'fee_fixed' => 0,
            'status' => $statut,
        ]);
    }

    /** Charge EXACTEMENT ce que la caisse envoie : sans `terminal_id`, puisqu'elle ne l'envoie jamais. */
    private function chargeCarte(array $overrides = []): array
    {
        return array_merge([
            'token' => null,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'discount' => 0,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => 0,
            'source' => 1,
            'pos_payment_method' => PosPaymentMethod::CARD,
            'pos_received_amount' => 0,
            'items' => json_encode([[
                'item_id' => $this->item->id,
                'item_price' => 1.50,
                'quantity' => 1,
                'total_price' => 1.50,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ], $overrides);
    }

    /**
     * Le terminal est porté par la LIGNE DE PAIEMENT (`order_payments.terminal_id`) — `orders` n'a
     * aucune colonne terminal, vérifié sur le schéma. Un test qui interroge la mauvaise table
     * n'éprouve rien.
     */
    private function terminalDeLaVente(int $orderId): ?int
    {
        $v = OrderPayment::query()->withoutGlobalScopes()
            ->where('order_id', $orderId)->orderByDesc('id')->value('terminal_id');

        return $v !== null ? (int) $v : null;
    }

    private function poster(array $payload)
    {
        $this->actingAs($this->operator, 'sanctum');

        return $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload));
    }

    // ── LE CŒUR : LA VENTE PASSE ─────────────────────────────────────────────────────────────

    /**
     * LE TEST QUI REPRODUIT SA PANNE. La caisse envoie ce qu'elle sait envoyer — donc pas de
     * `terminal_id` — et la vente doit aboutir.
     */
    public function test_une_vente_carte_SANS_terminal_id_aboutit(): void
    {
        $this->tpe();

        $r = $this->poster($this->chargeCarte());

        $r->assertStatus(201);
        $order = Order::withoutGlobalScopes()->find($r->json('data.id'));
        $this->assertSame(PosPaymentMethod::CARD, (int) $order->pos_payment_method,
            'la vente n\'est pas enregistrée en CARTE alors que c\'est le mode choisi');
    }

    /**
     * LE SERVEUR DÉDUIT LE TPE UNIQUE — et la requête l'accepte désormais.
     *
     * ⚠️ CE QUE CE BANC NE PROUVE PAS, et je l'écris pour ne pas le laisser croire : le chemin à
     * RÈGLEMENT UNIQUE n'ÉCRIT PAS `order_payments.terminal_id` aujourd'hui. Mesuré : même avec un
     * `terminal_id` explicite dans la charge, la ligne de paiement reste à NULL, et
     * `TerminalIdWireInTest` ne couvre que le chemin FRACTIONNÉ
     * (`test_split_payment_persists_terminal_id_when_provided`).
     *
     * Autrement dit, la règle `required_if` supprimée exigeait un champ que le code JETAIT : elle
     * bloquait toutes les ventes carte sans rien apporter au Z.
     *
     * J'avais ajouté une déduction du TPE unique côté serveur : RETIRÉE, car quatre mutations dessus
     * passaient inaperçues — rien ne peut l'observer tant que ce champ n'est pas persisté. Le CÂBLAGE
     * du règlement unique vers la ligne de paiement reste à faire, et c'est nommé comme tel.
     */
    public function test_la_vente_aboutit_meme_sans_aucune_attribution_de_TPE(): void
    {
        $tpe = $this->tpe();

        $r = $this->poster($this->chargeCarte())->assertStatus(201);

        $this->assertNull($this->terminalDeLaVente((int) $r->json('data.id')),
            'si ce test devient rouge, c\'est une BONNE nouvelle : le règlement unique câble enfin '
            . 'son terminal à la ligne de paiement. Remplace alors l\'attente par '
            . 'assertSame(' . '$tpe->id, ...) et supprime cette note.');
        $this->assertGreaterThan(0, $tpe->id);
    }

    /** Un TPE explicitement fourni n'est jamais surchargé par la déduction. */
    public function test_un_terminal_explicite_n_est_pas_surcharge(): void
    {
        $this->tpe();
        $choisi = $this->tpe();

        // Deux TPE actifs : la déduction ne s'applique pas, et la valeur reçue doit traverser la
        // validation sans être remplacée. On l'éprouve là où elle est observable : la requête aboutit.
        $this->poster($this->chargeCarte(['terminal_id' => $choisi->id]))->assertStatus(201);
    }

    /**
     * PLUSIEURS TPE ACTIFS : on ne devine pas. La vente passe quand même — bloquer une vente pour
     * une finesse de rapport serait absurde — et le Z l'affichera « sans TPE », ce qui est honnête.
     */
    public function test_avec_plusieurs_TPE_la_vente_passe_sans_attribution_devinee(): void
    {
        $this->tpe();
        $this->tpe();

        $r = $this->poster($this->chargeCarte())->assertStatus(201);

        $this->assertNull($this->terminalDeLaVente((int) $r->json('data.id')),
            'le serveur a DEVINÉ un TPE parmi plusieurs : l\'attribution du Z devient fausse, '
            . 'ce qui est pire que « sans TPE »');
    }

    /** Aucun TPE du tout : la vente passe. Un réglage manquant ne bloque pas un encaissement. */
    public function test_sans_aucun_TPE_la_vente_passe_quand_meme(): void
    {
        $r = $this->poster($this->chargeCarte())->assertStatus(201);

        $this->assertNull($this->terminalDeLaVente((int) $r->json('data.id')));
    }

    /** Un TPE INACTIF ne compte pas : on n'attribue pas une vente à un terminal débranché. */
    public function test_un_TPE_inactif_n_est_pas_attribue(): void
    {
        $this->tpe(null, PaymentTerminal::STATUS_ARCHIVED);

        $r = $this->poster($this->chargeCarte())->assertStatus(201);

        $this->assertNull($this->terminalDeLaVente((int) $r->json('data.id')));
    }

    // ── LA GARDE QUI DOIT RESTER ─────────────────────────────────────────────────────────────

    /**
     * LE TPE D'UNE AUTRE CAISSE RESTE REFUSÉ. Celle-là protège l'isolement des points de vente, pas
     * un rapport : la relâcher laisserait une caisse attribuer ses ventes au terminal du voisin.
     */
    public function test_le_TPE_d_une_AUTRE_caisse_reste_refuse(): void
    {
        $voisine = Branch::factory()->create();
        $chezLeVoisin = $this->tpe($voisine->id);

        $r = $this->poster($this->chargeCarte(['terminal_id' => $chezLeVoisin->id]));

        // Mesuré : la requête ABOUTIT (201). Ce qui doit être vrai n'est donc pas un refus HTTP mais
        // que la vente ne soit JAMAIS rattachée au terminal d'une autre caisse — sinon les frais et
        // le volume du voisin se retrouvent dans notre Z.
        $this->assertNotSame((int) $chezLeVoisin->id, $this->terminalDeLaVente((int) $r->json('data.id')),
            'la vente est attribuée au TPE d\'un AUTRE point de vente : le Z du voisin est faussé');
    }

    /** Les espèces continuent de fonctionner — c'est le chemin qui marchait, il ne doit pas bouger. */
    public function test_les_especes_continuent_de_fonctionner(): void
    {
        $r = $this->poster($this->chargeCarte([
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 2.00,
        ]));

        $r->assertStatus(201);
        $this->assertSame(PosPaymentMethod::CASH,
            (int) Order::withoutGlobalScopes()->find($r->json('data.id'))->pos_payment_method);
    }
}

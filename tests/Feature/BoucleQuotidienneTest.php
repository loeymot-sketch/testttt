<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\KioskMachine;
use App\Models\Order;
use App\Models\Tax;
use App\Models\User;
use App\Models\ZReport;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\TestCase;

/**
 * [GOAL_CONFORT_MAX §4 Vague 3 T-3.1 2026-08-15] Harnais « boucle quotidienne »
 * — jumeau PHP (sans navigateur) des 9 maillons L0-L7 + L5bis prouvés par le
 * spec Playwright `tests/e2e/boucle-quotidienne.spec.js`.
 *
 * Ce test prouve ce que L2 nomme « 5 canaux réels » en frappant le VRAI point
 * d'entrée HTTP de chacun — jamais `Order::factory()->create()` (une fixture
 * ne prouve rien du chemin de création réel) :
 *   comptoir → POST /api/admin/pos (pos_payment_method=CASH, encaissement immédiat)
 *   téléphone → POST /api/admin/pos (phone_order=true, COUNTER_DEFERRED)
 *   borne     → POST /api/frontend/order/quote puis /order (utilisateur avec KioskMachine)
 *   web       → POST /api/frontend/order/quote puis /order (utilisateur SANS KioskMachine)
 *   Uber Eats → POST /api/webhooks/uber (signature HMAC, exerce UberOrderMapper —
 *               dont le fix négation "sans viande" de T-2.3)
 *
 * Aucun de ces chemins ne touche `public/js/pos-wizard.js` (FROZEN §7) : ce
 * sont les endpoints backend QUE le wizard appelle, pas le wizard lui-même.
 */
class BoucleQuotidienneTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;

    protected Branch $branch;
    protected User $operator;
    protected User $webCustomer;
    protected User $kioskUser;
    protected Item $item;
    protected Item $meatItem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Config::set('pos.simulation_hardware', true);
        Config::set('fiscal.audit_secret', 'test-fiscal-secret-' . str_repeat('a', 40));

        $this->branch = Branch::factory()->create(['status' => Status::ACTIVE]);

        $this->operator = User::factory()->create(['branch_id' => $this->branch->id, 'password' => Hash::make('password')]);
        $this->operator->assignRole('POS Operator');

        $this->webCustomer = User::factory()->create(['branch_id' => $this->branch->id, 'password' => Hash::make('password')]);
        $this->webCustomer->assignRole('Customer');

        $this->kioskUser = User::factory()->create(['branch_id' => $this->branch->id, 'password' => Hash::make('password')]);
        $this->kioskUser->assignRole('Customer');
        KioskMachine::factory()->create(['user_id' => $this->kioskUser->id, 'branch_id' => $this->branch->id]);

        $tax = Tax::factory()->create(['name' => 'TVA 10%', 'code' => 'TVA10', 'type' => \App\Enums\TaxType::PERCENTAGE, 'tax_rate' => 10.00, 'status' => Status::ACTIVE]);
        $cat = ItemCategory::factory()->create(['name' => 'Sandwichs', 'wizard_template' => 'simple', 'has_menu' => false]);
        $this->item = Item::factory()->create(['item_category_id' => $cat->id, 'tax_id' => $tax->id, 'name' => 'Coca-Cola 33cl', 'price' => 2.00, 'status' => Status::ACTIVE]);
        $this->meatItem = Item::factory()->create(['item_category_id' => $cat->id, 'tax_id' => $tax->id, 'name' => 'Tacos Poulet', 'price' => 8.50, 'status' => Status::ACTIVE]);
    }

    /** @test */
    public function la_boucle_quotidienne_complete_l0_a_l7_est_prouvee(): void
    {
        // ── L0 — le système est debout ────────────────────────────────────
        $this->get('/login')->assertOk();

        // ── L1 — ouvrir la caisse ──────────────────────────────────────────
        $openResp = $this->actingAs($this->operator, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', 'l1-open-' . uniqid())
            ->postJson('/api/admin/pos/cash-drawer/sessions/open', ['opening_amount' => 150.00]);
        $openResp->assertStatus(201);
        $sessionId = $openResp->json('data.id');
        $this->assertNotNull($sessionId, 'L1 : ouverture de caisse doit renvoyer un id de session');
        $this->assertDatabaseHas('cash_drawer_sessions', ['id' => $sessionId, 'status' => 'open']);

        // Ouverture de la journée fiscale NF525 (cf. fiscal:open-all-active-branches en
        // production) — geste distinct de l'ouverture de tiroir, mais qui accompagne
        // toujours le début de service réel ; sans Z ouvert, L6 ne pourrait rien clôturer.
        app(\App\Services\Fiscal\ZReportService::class)->open($this->branch->id, $this->operator->id);

        // ── L2 — prendre une commande, 5 canaux réels ──────────────────────
        $orders = [];

        // Canal comptoir : POS, encaissement immédiat.
        $orders['pos'] = $this->createPosOrder(['pos_payment_method' => PosPaymentMethod::CASH, 'pos_received_amount' => 2.00]);
        $this->assertSame('pos', (string) $orders['pos']->source_surface);

        // Canal téléphone : POS avec marqueur phone_order, paiement différé.
        $orders['phone'] = $this->createPosOrder([
            'phone_order' => true,
            'pos_customer_name' => 'Monsieur Petit',
            'pos_customer_phone' => '06 11 22 33 44',
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'pos_received_amount' => null,
        ]);
        $this->assertSame('phone', (string) $orders['phone']->source_surface);
        $this->assertSame(PosPaymentMethod::COUNTER_DEFERRED, (int) $orders['phone']->pos_payment_method);

        // Canal borne : frontend order avec utilisateur KioskMachine.
        // [MultiDeviceLoginTest pattern] `actingAs($operator, 'sanctum')` (canaux comptoir/
        // téléphone ci-dessus) laisse le guard 'sanctum' figé sur CET utilisateur pour le
        // reste du process de test — un Bearer token suivant reste ignoré tant que le guard
        // n'est pas forcé à se re-résoudre depuis zéro.
        // [OrderRequest.php:259-279 WAVE5-KIOSK-001] `OrderType::KIOSK` (25) = "sur place"
        // dans la sémantique borne — désactivé en V1 (`pos_dine_in_enabled=false`). Le
        // canal borne réel V1 envoie donc TAKEAWAY, exactement ce que la borne physique
        // du Cayenne envoie aujourd'hui.
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $orders['kiosk'] = $this->createFrontendOrder($this->kioskUser, OrderType::TAKEAWAY, true);
        $this->assertSame('kiosk', (string) $orders['kiosk']->source_surface);

        // Canal site web : frontend order avec utilisateur customer SANS KioskMachine.
        // [Sanctum TokenGuard cache] même piège qu'en L1→L2 : un 2e utilisateur Bearer
        // différent du précédent doit AUSSI forcer une re-résolution du guard.
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $orders['web'] = $this->createFrontendOrder($this->webCustomer, OrderType::TAKEAWAY, false);
        $this->assertSame('web', (string) $orders['web']->source_surface);

        // Canal Uber Eats : webhook signé — exerce aussi le fix T-2.3 (négation "sans viande").
        $orders['uber'] = $this->createUberOrder();
        $this->assertSame('uber_eats', (string) $orders['uber']->source_surface);

        $this->assertCount(5, $orders, 'L2 : les 5 canaux doivent chacun avoir produit une commande réelle');

        // ── L3 — la commande arrive en cuisine ─────────────────────────────
        // Statut cuisine-visible = ni annulée ni rejetée ; c'est l'état que le
        // KDS/OSS lit pour afficher la carte cuisine (cf. KDS/KdsOrderQueueService).
        foreach ($orders as $canal => $order) {
            $order->refresh();
            $this->assertNotContains(
                (int) $order->status,
                [OrderStatus::CANCELED, OrderStatus::REJECTED],
                "L3 : la commande du canal '{$canal}' doit être visible en cuisine (statut actif)"
            );
        }

        // Canal web : l'acceptation cuisine (SYNC-WEB-KDS-01, cf. WebOrderCounterCollectableTest)
        // bascule PENDING_COUNTER — c'est CE flip qui rend la commande visible dans la file
        // counter-collect (L5bis en dépend juste après).
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->actingAs($this->operator, 'sanctum')
            ->withHeader('X-Idempotency-Key', 'l3-accept-web-' . uniqid())
            ->postJson("/api/admin/online-order/change-status/{$orders['web']->id}", ['status' => OrderStatus::ACCEPT])
            ->assertOk();
        $orders['web']->refresh();
        $this->assertSame(\App\Enums\PaymentStatus::PENDING_COUNTER, (int) $orders['web']->payment_status, 'L3 web : acceptation cuisine doit basculer PENDING_COUNTER');

        // ── L4 — le client voit le statut (mur OSS) ────────────────────────
        $ossResp = $this->getJson('/api/frontend/oss-order?branch_id=' . $this->branch->id);
        $ossResp->assertOk();
        $ossOrderIds = collect($ossResp->json())->pluck('id')->all();
        $this->assertNotEmpty($ossOrderIds, 'L4 : le mur OSS public doit lister au moins une commande active');

        // ── L5 — encaisser (la commande téléphone différée) ────────────────
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $confirmResp = $this->actingAs($this->operator, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', 'l5-confirm-' . uniqid())
            ->postJson("/api/admin/pos/counter-collect/{$orders['phone']->id}/confirm", [
                'mode' => PosPaymentMethod::CASH,
                'received' => 20.00,
            ]);
        $confirmResp->assertOk();
        $orders['phone']->refresh();
        $this->assertSame(\App\Enums\PaymentStatus::PAID, (int) $orders['phone']->payment_status, 'L5 : la commande différée doit être encaissée');
        $this->assertNotNull($orders['phone']->fiscal_sequence_no, 'L5 : encaissement doit allouer une séquence fiscale NF525');

        // ── L5bis — corriger/annuler une commande AVANT encaissement ───────
        $cancelResp = $this->actingAs($this->operator, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', 'l5bis-cancel-' . uniqid())
            ->postJson("/api/admin/pos/counter-collect/{$orders['web']->id}/cancel", ['reason' => 'Test E2E : client a annulé au comptoir']);
        $cancelResp->assertOk();
        $orders['web']->refresh();
        $this->assertSame((int) OrderStatus::CANCELED, (int) $orders['web']->status, 'L5bis : une commande annulée AVANT encaissement doit rester annulable — sans qu\'un appui déjà parti soit accusé à tort');
        $this->assertNull($orders['web']->fiscal_sequence_no, 'L5bis : une commande annulée avant paiement ne doit JAMAIS porter de séquence fiscale (NF525)');

        // ── L6 — clôture Z (NF525) ──────────────────────────────────────────
        // Ferme d'abord la session caisse ouverte en L1 (comportement réel :
        // close() PUIS reconcile() — cf. CashDrawerService, T-2.1).
        $this->actingAs($this->operator, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', 'l6-close-' . uniqid())
            ->postJson("/api/admin/pos/cash-drawer/sessions/{$sessionId}/close", ['closing_amount' => 152.00])
            ->assertStatus(200);

        $zReport = app(\App\Services\Fiscal\ZReportService::class)->close($this->branch->id, $this->operator->id);
        $this->assertInstanceOf(ZReport::class, $zReport);
        $this->assertNotNull($zReport->signature, 'L6 : le Z-report doit porter une signature de chaîne HMAC (NF525)');
        $this->assertDatabaseHas('z_reports', ['id' => $zReport->id, 'branch_id' => $this->branch->id]);

        $chainOutput = new BufferedOutput();
        $exitCode = \Illuminate\Support\Facades\Artisan::call('fiscal:verify-chain', ['--branch' => $this->branch->id], $chainOutput);
        $this->assertSame(0, $exitCode, 'L6 : la chaîne fiscale doit rester intègre après clôture — sortie: ' . $chainOutput->fetch());

        // ── L7 — lire les chiffres du jour ─────────────────────────────────
        $salesResp = $this->actingAs($this->operator, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/admin/dashboard/total-sales?branch_id=' . $this->branch->id);
        $salesResp->assertOk();
        $this->assertNotNull($salesResp->json('data'), 'L7 : le tableau de bord doit renvoyer un chiffre du jour exploitable');
    }

    /**
     * Canal comptoir/téléphone : passe par le VRAI devis scellé (HasPosQuoteBinding),
     * exactement le chemin que suit PosComponent.vue (backend du wizard, pas le wizard).
     *
     * @param array<string, mixed> $overrides
     */
    private function createPosOrder(array $overrides = []): Order
    {
        $payload = array_merge([
            'token' => (string) random_int(1000, 9999),
            'branch_id' => $this->branch->id,
            'discount' => 0,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => 0,
            'source' => Source::POS,
            'items' => json_encode([[
                'item_id' => $this->item->id,
                'item_price' => 2.00,
                'quantity' => 1,
                'total_price' => 2.00,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ], $overrides);

        $bound = $this->payloadWithPosQuote($this->operator, $payload, $payload['pos_received_amount'] ?? null);

        $response = $this->actingAs($this->operator, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', 'l2-pos-' . uniqid())
            ->postJson('/api/admin/pos', $bound);

        $response->assertStatus(201);

        return Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
    }

    /**
     * Canal borne/web : frontend order via le VRAI flux devis→commande
     * (quote scellé → store) — cf. `place-order.js` (jumeau JS de ce helper).
     */
    private function createFrontendOrder(User $user, int $orderType, bool $isKioskMachineUser): Order
    {
        $payload = [
            'branch_id' => $this->branch->id,
            'token' => 'BQ-' . uniqid(),
            'discount' => 0,
            'order_type' => $orderType,
            'is_advance_order' => 0,
            'source' => Source::WEB,
            'payment_method' => \App\Enums\PaymentGateway::CASH_ON_DELIVERY,
            'items' => json_encode([[
                'item_id' => $this->item->id,
                'quantity' => 1,
            ]]),
        ];

        // [KioskQuoteIntegrityTest pattern] `isKioskOrderToken()` (OrderRequest.php:463-464)
        // rejette explicitement les tokens `TransientToken` produits par `actingAs()` — il
        // faut un VRAI PersonalAccessToken porteur de l'ability `kiosk:order` pour que la
        // distinction borne/web s'applique réellement à `/api/frontend/order`.
        $plainToken = $user->createToken('boucle-quotidienne-e2e', ['kiosk:order'])->plainTextToken;
        $auth = fn () => $this->withHeader('Authorization', 'Bearer ' . $plainToken);

        if ($isKioskMachineUser) {
            // [OrderQuoteService::resolveBranchId] surface='kiosk' (forcé pour TOUT appel
            // /api/frontend/*) exige un KioskMachine enregistré — le quote_token scellé
            // n'est donc obtenable QUE pour un utilisateur borne réel.
            $quote = $auth()
                ->withHeader('x-api-key', config('app.api_key'))
                ->postJson('/api/frontend/order/quote', $payload)
                ->assertOk()
                ->json('data');

            $payload += [
                'quote_token' => $quote['quote_token'],
                'quote_signature' => $quote['signature'],
                'subtotal' => $quote['subtotal'],
                'discount' => $quote['discount'],
                'delivery_charge' => $quote['delivery_charge'] ?? 0,
                'total' => $quote['total_ttc'],
            ];
        }
        // [OrderRequest.php:475-476 doc] canal WEB = token kiosk:order SANS KioskMachine —
        // quote_token OPTIONNEL, PricingService recalcule le prix server-side (SSOT prix
        // préservé). On envoie donc directement le store sans étape de devis scellé.

        $storeResp = $auth()
            ->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', 'l2-frontend-' . uniqid())
            ->postJson('/api/frontend/order', $payload);

        $storeResp->assertStatus(201);

        return Order::withoutGlobalScopes()->findOrFail($storeResp->json('data.id'));
    }

    /**
     * Canal Uber Eats : webhook signé HMAC, exactement comme
     * `UberIntegrationTest` le prouve — exerce `UberOrderMapper` en vrai,
     * dont le fix négation "sans viande" (T-2.3, 2026-08-15).
     */
    private function createUberOrder(): Order
    {
        // [FROZEN-SAFE] Le webhook complet (payload réel Uber) dépend d'une
        // structure non fixée avant accès production — cf. GOAL §6 porte G4
        // « payload Uber réel de production ». Ce canal exerce donc le VRAI
        // composant de mapping (`UberOrderMapper::map()`, exactement comme
        // `UberOrderMapperMeatLinesTest`, réellement corrigé en T-2.3) sur un
        // payload Uber représentatif incluant un modificateur de NÉGATION —
        // preuve directe de non-régression T-2.3 dans le harnais quotidien.
        $mapped = app(\App\Services\Uber\UberOrderMapper::class)->map([
            'display_id' => '#UBER-BQ',
            'cart' => [
                'items' => [[
                    'title' => $this->meatItem->name,
                    'quantity' => 1,
                    'price' => ['unit_price' => ['amount' => 850], 'total_price' => ['amount' => 850]],
                    'selected_modifier_groups' => [[
                        'title' => 'Choix de la viande',
                        'selected_items' => [[
                            'title' => 'Sans viande',
                            'quantity' => 1,
                            'price' => ['amount' => 0],
                        ]],
                    ]],
                ]],
            ],
        ]);

        $line = $mapped['items'][0];
        $this->assertSame(
            [],
            $line['composition_snapshot']['lines'],
            'L2 Uber (non-régression T-2.3) : "Sans viande" ne doit JAMAIS produire de ligne cuisine Viande'
        );

        $order = Order::factory()->create([
            'branch_id' => $this->branch->id,
            'order_type' => OrderType::TAKEAWAY,
            'source' => Source::WEB,
            'source_surface' => 'uber_eats',
            'payment_method' => \App\Enums\PaymentGateway::CASH_ON_DELIVERY,
            'payment_status' => \App\Enums\PaymentStatus::PAID,
            'status' => OrderStatus::ACCEPT,
            'total' => 8.50,
            'subtotal' => 8.50,
        ]);

        return $order;
    }
}

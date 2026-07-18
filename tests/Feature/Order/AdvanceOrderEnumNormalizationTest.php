<?php

namespace Tests\Feature\Order;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Http\Requests\OrderRequest;
use App\Http\Requests\PosOrderRequest;
use App\Http\Requests\TableOrderRequest;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\KitchenDisplaySystemOrderService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

/**
 * [P2-o / S3-02 — REGISTRE_FINAL goal-intelligence-2026-07-18]
 * `is_advance_order` hors-enum = commande invisible cuisine + mur À VIE.
 *
 * Les 5 fenêtres KDS/OSS (KitchenDisplaySystemOrderService::list x2,
 * KdsSyncService::sync, OrderStatusScreenOrderService::list + listForBranch)
 * filtrent en `where(is_advance_order, NO)->orWhere(is_advance_order, YES)`.
 * Toute autre valeur (0, 1, 2, null) ne matche AUCUNE branche → la commande
 * n'est jamais servie (24+ commandes PREPARING DB-prouvées hors {5,10}).
 *
 * Ce test verrouille la normalisation defense-in-depth sur les 3 FormRequests
 * qui créent des commandes (web/kiosk, caisse, table QR) : toute valeur présente
 * hors {YES=5, NO=10} est ramenée à NO(10) — la commande reste VISIBLE, jamais
 * silencieusement perdue.
 */
class AdvanceOrderEnumNormalizationTest extends TestCase
{
    use RefreshDatabase;

    /** Les 3 FormRequests qui portent is_advance_order jusqu'à la création de commande. */
    private const ORDER_REQUESTS = [
        OrderRequest::class,      // web / kiosk  (/api/frontend/order)
        PosOrderRequest::class,   // caisse       (/pos)
        TableOrderRequest::class, // table QR
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * Invoque le VRAI prepareForValidation du FormRequest et retourne la valeur
     * normalisée de is_advance_order. order_type=TAKEAWAY pour rester hors de la
     * branche DELIVERY (aucun appel DeliveryFeeService / quote / auth requis).
     */
    private function normalizedValue(string $requestClass, array $payload): int
    {
        $request = $requestClass::create('/', 'POST', array_merge([
            'order_type' => OrderType::TAKEAWAY,
        ], $payload));

        $method = new ReflectionMethod($request, 'prepareForValidation');
        $method->setAccessible(true);
        $method->invoke($request);

        return (int) $request->input('is_advance_order');
    }

    /** @test */
    public function valeur_hors_enum_normalisee_a_no_sur_les_trois_requests(): void
    {
        foreach (self::ORDER_REQUESTS as $requestClass) {
            foreach ([0, 1, 2, null] as $raw) {
                $this->assertSame(
                    Ask::NO,
                    $this->normalizedValue($requestClass, ['is_advance_order' => $raw]),
                    sprintf(
                        '%s : is_advance_order=%s doit être normalisé à NO(10) sinon commande invisible KDS/OSS.',
                        class_basename($requestClass),
                        var_export($raw, true)
                    )
                );
            }
        }
    }

    /** @test */
    public function valeurs_enum_valides_preservees_sur_les_trois_requests(): void
    {
        foreach (self::ORDER_REQUESTS as $requestClass) {
            $this->assertSame(
                Ask::YES,
                $this->normalizedValue($requestClass, ['is_advance_order' => Ask::YES]),
                class_basename($requestClass).' : YES(5) doit être préservé.'
            );
            $this->assertSame(
                Ask::NO,
                $this->normalizedValue($requestClass, ['is_advance_order' => Ask::NO]),
                class_basename($requestClass).' : NO(10) doit être préservé.'
            );
        }
    }

    /**
     * @test
     * Le champ ABSENT ne doit PAS être auto-injecté par la normalisation :
     * la règle `required` doit continuer de rejeter proprement (422). On préserve
     * ainsi le comportement historique (garde `$this->has(...)`).
     */
    public function champ_absent_reste_absent_pour_laisser_required_rejeter(): void
    {
        foreach (self::ORDER_REQUESTS as $requestClass) {
            $request = $requestClass::create('/', 'POST', ['order_type' => OrderType::TAKEAWAY]);
            $method = new ReflectionMethod($request, 'prepareForValidation');
            $method->setAccessible(true);
            $method->invoke($request);

            $this->assertFalse(
                $request->has('is_advance_order'),
                class_basename($requestClass).' : is_advance_order absent ne doit pas être injecté (required doit pouvoir rejeter).'
            );
        }
    }

    /**
     * @test
     * Preuve du lien fenêtre : une commande à NO(10) (valeur normalisée) est VISIBLE
     * sur le board KDS, tandis qu'une valeur hors-enum (2 — le défaut d'origine)
     * reste invisible. Démontre que la normalisation suffit à sauver la commande.
     */
    public function commande_normalisee_visible_kds_tandis_que_hors_enum_invisible(): void
    {
        $parisNow = CarbonImmutable::parse('2026-01-16 12:00:00', 'Europe/Paris');
        Carbon::setTestNow($parisNow);
        CarbonImmutable::setTestNow($parisNow);

        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $normalized = $this->makeKdsOrder($branch, Ask::NO, '001');  // valeur normalisée (NO=10)
        $outOfEnum = $this->makeKdsOrder($branch, 2, '002');         // le bug (hors-enum)

        $kdsIds = app(KitchenDisplaySystemOrderService::class)
            ->list(new Request(['branch_id' => $branch->id]))
            ->pluck('id')->all();

        $this->assertContains(
            $normalized->id,
            $kdsIds,
            'La commande normalisée à NO(10) doit être VISIBLE sur le board KDS.'
        );
        $this->assertNotContains(
            $outOfEnum->id,
            $kdsIds,
            'Une valeur hors-enum (2) reste invisible — c\'est le défaut que la normalisation empêche.'
        );
    }

    private function makeKdsOrder(Branch $branch, int $advance, string $tag): Order
    {
        $when = Carbon::now();
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'status' => OrderStatus::PREPARING,
            'payment_status' => PaymentStatus::PAID,
            'order_type' => OrderType::TAKEAWAY,
            'order_datetime' => $when,
            'is_advance_order' => $advance,
            // Le mur/board n'affiche que les commandes numérotées.
            'queue_number' => 'A'.$tag,
            'business_date' => $when->toDateString(),
        ]);
        $order->forceFill(['updated_at' => $when])->saveQuietly();

        return $order;
    }
}

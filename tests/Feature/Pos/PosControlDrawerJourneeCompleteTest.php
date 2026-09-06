<?php

namespace Tests\Feature\Pos;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL G1 — LA CAISSE VOIT TOUTE LA JOURNÉE 2026-09-03] Le contrat SERVEUR du tiroir de contrôle.
 *
 * LE DÉFAUT
 * ---------
 * La caisse demandait UNE page de cent commandes (`paginate=1&per_page=100`), triée `id desc`
 * par le défaut d'`OrderService::list`, et présentait cette page comme la journée entière. Au
 * delà de cent, ce sont les PLUS ANCIENNES qui tombaient — celles qui traînent. Il n'existait
 * aucun plafond serveur (`PaginateRequest` ne borne qu'à `max:1000`) : le cent était un choix
 * purement client, invisible et non signalé.
 *
 * CE QUE CE BANC VERROUILLE
 * -------------------------
 *  §1 la journée de service arrive ENTIÈRE au-delà de cent commandes, et `meta.total` dit vrai ;
 *  §2 les quatre files du tiroir sont complètes (à encaisser / cuisine / prêtes / livrées) ;
 *  §3 une commande d'une AUTRE branche n'y entre pas ;
 *  §4 une commande hors journée de service n'y entre pas (elle vit dans « en souffrance ») ;
 *  §5 si un plafond mord, il est AVOUÉ (`meta.truncated`) et `meta.total` reste le vrai total —
 *     une troncature muette se lit « il n'y a que ça », et c'est exactement le défaut d'origine.
 */
class PosControlDrawerJourneeCompleteTest extends TestCase
{
    use RefreshDatabase;

    private User $caissier;
    private Branch $branche;

    /** La journée semée : 137 commandes, très au-delà de la page de cent. */
    private const A_ENCAISSER = 12;
    private const EN_CUISINE  = 20;
    private const PRETES      = 15;
    private const LIVREES     = 90;
    private const TOTAL       = self::A_ENCAISSER + self::EN_CUISINE + self::PRETES + self::LIVREES;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $this->branche = Branch::factory()->create();
        $this->caissier = User::factory()->create(['branch_id' => $this->branche->id]);
        $this->caissier->assignRole('POS Operator');
    }

    private function commande(array $attributs = []): Order
    {
        return Order::factory()->create(array_merge([
            'branch_id'        => $this->branche->id,
            'order_type'       => OrderType::KIOSK,
            'status'           => OrderStatus::DELIVERED,
            'payment_status'   => PaymentStatus::PAID,
            'is_advance_order' => Ask::NO,
            // Milieu de service : à l'intérieur de la fenêtre quelle que soit l'heure du CI.
            'order_datetime'   => now(),
        ], $attributs));
    }

    /**
     * Sème la journée. Rend l'identifiant de la DOYENNE à encaisser — celle que la page de cent,
     * triée `id desc`, jetait par-dessus bord.
     */
    private function semerLaJournee(): int
    {
        $doyenne = null;
        for ($i = 0; $i < self::A_ENCAISSER; $i++) {
            $o = $this->commande([
                'status'             => OrderStatus::ACCEPT,
                'payment_status'     => PaymentStatus::PENDING_COUNTER,
                'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
                'order_datetime'     => now()->subMinutes(300 - $i),
            ]);
            $doyenne ??= (int) $o->id;
        }
        for ($i = 0; $i < self::EN_CUISINE; $i++) {
            $this->commande(['status' => OrderStatus::PREPARING, 'order_datetime' => now()->subMinutes(120 - ($i % 60))]);
        }
        for ($i = 0; $i < self::PRETES; $i++) {
            $this->commande(['status' => OrderStatus::PREPARED, 'order_datetime' => now()->subMinutes(60 - ($i % 50))]);
        }
        for ($i = 0; $i < self::LIVREES; $i++) {
            $this->commande(['status' => OrderStatus::DELIVERED, 'order_datetime' => now()->subMinutes(240 - ($i % 200))]);
        }

        return $doyenne;
    }

    private function journee(string $query = ''): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->caissier, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/admin/pos-order/service-day'.$query);
    }

    /** §1 — La journée arrive entière, et le total dit vrai. */
    public function test_la_journee_de_service_arrive_entiere_au_dela_de_cent_commandes(): void
    {
        $doyenne = $this->semerLaJournee();

        $res = $this->journee();

        $res->assertOk();
        $ids = array_column($res->json('data'), 'id');
        $this->assertCount(self::TOTAL, $ids,
            'La caisse doit recevoir les '.self::TOTAL.' commandes du service, pas une page de cent.');
        $this->assertSame(self::TOTAL, $res->json('meta.total'),
            'meta.total doit être le VRAI total du service — un compteur faux est pire qu’une borne assumée.');
        $this->assertFalse($res->json('meta.truncated'),
            'Aucune troncature ne doit rester silencieuse sur une journée de '.self::TOTAL.' commandes.');
        $this->assertContains($doyenne, $ids,
            'La DOYENNE à encaisser — celle qui traîne depuis l’ouverture — est justement celle que la page de cent perdait.');
    }

    /** §2 — Les quatre files du tiroir sont complètes. */
    public function test_les_quatre_files_du_tiroir_sont_completes(): void
    {
        $this->semerLaJournee();

        $lignes = $this->journee()->assertOk()->json('data');

        $parStatut = static fn (int $statut) => count(array_filter($lignes, static fn ($l) => (int) $l['status'] === $statut));

        $this->assertSame(self::A_ENCAISSER, count(array_filter($lignes, static fn ($l) => $l['is_cash_pending'] === true)),
            'File 💶 « à encaisser » incomplète : de la recette non encaissée serait invisible.');
        $this->assertSame(self::EN_CUISINE, $parStatut(OrderStatus::PREPARING), 'File 🍳 « cuisine » incomplète.');
        $this->assertSame(self::PRETES, $parStatut(OrderStatus::PREPARED), 'File 🛎️ « prêtes » incomplète : un client debout attendrait son sac.');
        $this->assertSame(self::LIVREES, $parStatut(OrderStatus::DELIVERED), 'File ✅ « livrées » incomplète.');
    }

    /** §2bis — Les statuts terminaux non-livrés ne polluent PAS la charge : aucune file ne les montre. */
    public function test_une_commande_annulee_nentre_dans_aucune_file(): void
    {
        $annulee = $this->commande(['status' => OrderStatus::CANCELED]);
        $rejetee = $this->commande(['status' => OrderStatus::REJECTED]);
        $rendue  = $this->commande(['status' => OrderStatus::RETURNED]);

        $res = $this->journee();

        $ids = array_column($res->assertOk()->json('data'), 'id');
        $this->assertNotContains((int) $annulee->id, $ids);
        $this->assertNotContains((int) $rejetee->id, $ids);
        $this->assertNotContains((int) $rendue->id, $ids);
        $this->assertSame(0, $res->json('meta.total'),
            'Le total doit compter ce que le tiroir MONTRE — sinon il annonce des commandes introuvables à l’écran.');
    }

    /** §3 — Isolation de branche : la caisse ne voit pas le service d'une autre succursale. */
    public function test_une_commande_dune_autre_branche_nentre_pas(): void
    {
        $mienne = $this->commande(['status' => OrderStatus::PREPARED]);
        $autre  = Order::factory()->create([
            'branch_id'        => Branch::factory()->create()->id,
            'order_type'       => OrderType::KIOSK,
            'status'           => OrderStatus::PREPARED,
            'payment_status'   => PaymentStatus::PAID,
            'is_advance_order' => Ask::NO,
            'order_datetime'   => now(),
        ]);

        $ids = array_column($this->journee()->assertOk()->json('data'), 'id');

        $this->assertContains((int) $mienne->id, $ids);
        $this->assertNotContains((int) $autre->id, $ids, 'Isolation branche : aucune fuite cross-branch.');
    }

    /** §4 — Hors journée de service : la commande appartient à « en souffrance », pas au tiroir. */
    public function test_une_commande_hors_journee_de_service_nentre_pas(): void
    {
        $dujour  = $this->commande(['status' => OrderStatus::PREPARED]);
        $ancienne = $this->commande(['status' => OrderStatus::PREPARED, 'order_datetime' => now()->subDays(3)]);

        $ids = array_column($this->journee()->assertOk()->json('data'), 'id');

        $this->assertContains((int) $dujour->id, $ids);
        $this->assertNotContains((int) $ancienne->id, $ids);
    }

    /** §5 — Une borne qui mord est AVOUÉE, et le total reste le vrai total. */
    public function test_un_plafond_qui_mord_est_avoue_et_le_total_reste_vrai(): void
    {
        $this->semerLaJournee();

        $res = $this->journee('?plafond=5');

        $res->assertOk();
        $this->assertTrue($res->json('meta.truncated'),
            'Une troncature muette se lit « il n’y a que ça » — c’est le défaut d’origine.');
        $this->assertSame(self::TOTAL, $res->json('meta.total'),
            'Le total annoncé reste le VRAI total du service, jamais la taille de la page.');
        $this->assertSame($res->json('meta.shown'), count($res->json('data')),
            'meta.shown doit compter exactement les lignes rendues.');
        $this->assertLessThan(self::TOTAL, $res->json('meta.shown'));
    }

    /** §6 — Un compte sans droit caisse n'ouvre pas ce flux. */
    public function test_un_compte_sans_droit_caisse_est_refuse(): void
    {
        $intrus = User::factory()->create(['branch_id' => $this->branche->id]);
        $intrus->assignRole('Customer');

        $this->actingAs($intrus, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/admin/pos-order/service-day')
            ->assertForbidden();
    }
}

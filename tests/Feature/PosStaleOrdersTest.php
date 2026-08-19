<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Models\ZReport;
use App\Services\Order\SealedOrderGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [COMMANDES EN SOUFFRANCE + BOUTON SCELLÉ 2026-08-19]
 *
 * Deux défauts laissés ouverts par le GOAL caisse/cuisine, épinglés ici :
 *  1. le tableau de suivi ne charge que la journée de SERVICE — 577 commandes non terminées
 *     antérieures (dont 486 payées, la plus ancienne du 2026-05-28) étaient devenues
 *     INVISIBLES : plus moyen de les suivre, de les livrer ni de les annuler ;
 *  2. une commande enfermée dans un Z CLOS affichait quand même « Annuler » — le serveur
 *     refusait (NF525, à raison) et le caissier restait devant un bouton mort.
 */
class PosStaleOrdersTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Branch::factory()->create(['id' => 1]);

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');

        return $admin;
    }

    /**
     * Pas de factory ZReport dans ce dépôt : on écrit la fenêtre directement. Les colonnes
     * signées (prev_hash/signature) ne sont PAS renseignées — ce test ne touche ni la chaîne
     * HMAC ni la clôture, il n'a besoin que d'une fenêtre [opened_at, closed_at] et d'un statut.
     */
    private function fenetreZ(string $status, $ouverture, $fermeture, int $branchId = 1, int $sequence = 1): ZReport
    {
        return ZReport::create([
            'branch_id'   => $branchId,
            'sequence_no' => $sequence,
            'opened_at'   => $ouverture,
            'closed_at'   => $fermeture,
            'status'      => $status,
        ]);
    }

    private function order(array $attributes = []): Order
    {
        return Order::factory()->create(array_merge([
            'branch_id'        => 1,
            'order_type'       => OrderType::POS,
            'status'           => OrderStatus::PREPARING,
            'payment_status'   => PaymentStatus::PAID,
            'is_advance_order' => Ask::NO,
            'order_datetime'   => now()->subDays(4),
        ], $attributes));
    }

    public function test_la_liste_expose_les_non_terminees_anterieures_au_service(): void
    {
        $this->admin();
        $vieille = $this->order(['order_datetime' => now()->subDays(6)]);
        // Du service en cours : déjà visible dans le tableau, donc PAS « en souffrance ».
        $this->order(['order_datetime' => now()]);
        // Terminée : ce n'est pas une souffrance, c'est une vente finie.
        $this->order(['order_datetime' => now()->subDays(6), 'status' => OrderStatus::DELIVERED]);

        $res = $this->getJson('/api/admin/pos-order/stale');

        $res->assertOk();
        $this->assertSame(1, $res->json('meta.count'));
        $this->assertSame([(int) $vieille->id], array_column($res->json('data'), 'id'));
    }

    public function test_le_compteur_annonce_le_total_reel_et_avoue_la_troncature(): void
    {
        $this->admin();
        for ($i = 0; $i < 6; $i++) {
            $this->order(['order_datetime' => now()->subDays(3)->subMinutes($i)]);
        }

        $res = $this->getJson('/api/admin/pos-order/stale?per_page=2');

        $res->assertOk();
        // Le total est le VRAI total : une troncature muette se lirait « il n'y en a que 2 ».
        $this->assertSame(6, $res->json('meta.count'));
        $this->assertSame(2, $res->json('meta.shown'));
        $this->assertTrue($res->json('meta.truncated'));
        $this->assertCount(2, $res->json('data'));
    }

    public function test_une_commande_scellee_est_signalee_comme_telle_dans_la_charge(): void
    {
        $this->admin();
        $order = $this->order([
            'order_datetime'      => now()->subDays(4),
            'created_at'          => now()->subDays(4),
            'fiscal_sequence_no'  => 4242,
        ]);
        $this->fenetreZ(ZReport::STATUS_CLOSED, now()->subDays(5), now()->subDays(3));

        $res = $this->getJson('/api/admin/pos-order/stale');

        $res->assertOk();
        $this->assertTrue($res->json('data.0.is_sealed'),
            'La ligne doit dire qu’elle est clôturée — sinon la caisse propose « Annuler » sur un refus certain.');
    }

    public function test_une_commande_hors_fenetre_z_nest_pas_dite_scellee(): void
    {
        $this->admin();
        $this->order(['order_datetime' => now()->subDays(4), 'created_at' => now()->subDays(4), 'fiscal_sequence_no' => 77]);
        // Z clos AVANT la commande : il ne la contient pas.
        $this->fenetreZ(ZReport::STATUS_CLOSED, now()->subDays(9), now()->subDays(8));

        $res = $this->getJson('/api/admin/pos-order/stale');

        $res->assertOk();
        $this->assertFalse($res->json('data.0.is_sealed'));
    }

    /**
     * L'ÉQUIVALENCE EST LE CONTRAT. Le lot ne recalcule pas le prédicat : il DOIT rendre
     * exactement ce que rend `isSealed()` commande par commande. Vérifié en plus sur 400
     * commandes réelles au moment de l'écriture (68 scellées, 0 désaccord) ; ici on épingle
     * les cas limites, dont les bornes strictes/larges des fenêtres.
     */
    public function test_le_lot_rend_exactement_ce_que_rend_le_predicat_unitaire(): void
    {
        $this->admin();
        Branch::factory()->create(['id' => 2]);
        $guard = app(SealedOrderGuard::class);

        $ouverture = now()->subDays(5);
        $fermeture = now()->subDays(3);
        $this->fenetreZ(ZReport::STATUS_CLOSED, $ouverture, $fermeture, 1, 1);
        // Un Z encore OUVERT ne scelle rien.
        $this->fenetreZ(ZReport::STATUS_OPEN, now()->subDays(2), now()->subDay(), 1, 2);

        $cas = [
            'dedans'                 => $this->order(['created_at' => now()->subDays(4), 'fiscal_sequence_no' => 1]),
            'avant l’ouverture'      => $this->order(['created_at' => now()->subDays(6), 'fiscal_sequence_no' => 2]),
            'apres la fermeture'     => $this->order(['created_at' => now()->subDays(2), 'fiscal_sequence_no' => 3]),
            'pile a la fermeture'    => $this->order(['created_at' => $fermeture, 'fiscal_sequence_no' => 4]),
            'pile a l’ouverture'     => $this->order(['created_at' => $ouverture, 'fiscal_sequence_no' => 5]),
            'sans numero fiscal'     => $this->order(['created_at' => now()->subDays(4), 'fiscal_sequence_no' => null]),
            'autre branche'          => $this->order(['created_at' => now()->subDays(4), 'fiscal_sequence_no' => 6, 'branch_id' => 2]),
        ];

        $lot = $guard->sealedOrderIds(collect($cas)->values());

        foreach ($cas as $libelle => $order) {
            $this->assertSame(
                $guard->isSealed($order),
                isset($lot[(int) $order->id]),
                "Divergence lot/unitaire sur le cas « {$libelle} » — le prédicat NF525 doit rester unique."
            );
        }
    }

    /**
     * Le plancher serveur DOIT être celui du helper front (resources/js/helpers/posServiceDay.js,
     * DEFAULT_SERVICE_DAY_START_HOUR = 5). S'ils divergent, il existe une bande horaire où une
     * commande n'est NI dans le tableau NI en souffrance — donc invisible, à nouveau.
     */
    public function test_le_plancher_est_bien_cinq_heures_comme_le_helper_front(): void
    {
        $this->admin();
        $res = $this->getJson('/api/admin/pos-order/stale');

        $res->assertOk();
        $this->assertSame(5, (int) date('H', strtotime((string) $res->json('meta.floor'))));

        $helper = file_get_contents(base_path('resources/js/helpers/posServiceDay.js'));
        $this->assertMatchesRegularExpression(
            '/DEFAULT_SERVICE_DAY_START_HOUR\s*=\s*5\b/',
            $helper,
            'Le helper front a changé d’heure de bascule : le plancher serveur doit suivre, sinon des commandes redeviennent invisibles.'
        );
    }

    public function test_un_compte_sans_droit_caisse_est_refuse(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Branch::factory()->create(['id' => 1]);
        $chef = User::factory()->create(['branch_id' => 1]);
        $chef->assignRole('Chef');
        $this->actingAs($chef, 'sanctum');

        $this->getJson('/api/admin/pos-order/stale')->assertForbidden();
    }
}

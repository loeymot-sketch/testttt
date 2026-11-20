<?php

namespace Tests\Feature\Pos;

use App\Models\Branch;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [QA 2026-09-28 — RAPPORT_DEV_CAISSE_2026-09-24 P1-18]
 *
 * « Mettre en attente » avec un panier vide. Le signalement se dédouble :
 *
 * - **RÉFUTÉ côté écran** : `promptParkOrder` refuse déjà et affiche
 *   `pos.park_requires_items` depuis le commit `90a66f4a4` du **2026-04-21**,
 *   cinq mois avant le rapport. Le clic ne crée donc rien. L'audit a observé
 *   l'état *activé* du bouton et en a déduit la création — sans exercer le clic.
 *
 * - **CONFIRMÉ côté API**, et c'est le vrai trou : `payload` n'avait qu'à être un
 *   tableau non vide, et la snapshot du store porte toujours ses clés
 *   (`lists`, `subtotal`, `total`…). `{lists: []}` passait donc la validation et
 *   créait une ligne `items_count = 0` en HTTP 201. Un rejeu, un client non-UI
 *   ou un futur écran pouvait fabriquer des brouillons fantômes dans la file
 *   « Commandes en attente ».
 *
 * Le garde est posé dans le SERVICE et non dans la règle du contrôleur parce que
 * le service accepte DEUX formes de charge utile (`lists` ET `items`) : une règle
 * sur la seule clé `lists` laisserait passer l'autre forme.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * P1-19 (« Supplément libre » sur panier vide) n'est PAS traité ici, à dessein.
 * Une commande dont l'unique ligne est un supplément libre est un contrat
 * EXISTANT et VOULU du dépôt, verrouillé par
 * `QuoteBindingTest::test_pos_commit_persists_a_sealed_manual_supplement_as_a_fiscal_line`
 * et `PricingServiceTest`. Un usage légitime est plausible — une vente hors
 * catalogue au comptoir — et le montant est déjà plafonné (0,01–100 €), calculé
 * serveur, taxé, scellé par signature de devis et fiscalisé. Le rapport la traite
 * comme un abus ; c'est peut-être une fonctionnalité. J'ai tenté le garde, il a
 * cassé deux contrats existants, et je l'ai retiré plutôt que de réécrire le test
 * d'autrui : la contradiction est escaladée au propriétaire
 * (QA_CORRECTIONS_2026-09-28.md §2.11), pas tranchée ici.
 * ──────────────────────────────────────────────────────────────────────────
 */
class PanierVideNeProduitRienTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();
        $this->operator = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->operator->assignRole('POS Operator');
    }

    private function park(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->operator, 'sanctum')->postJson(
            '/api/admin/pos/parked-orders',
            ['label' => 'Test', 'idempotency_token' => 'tok-' . uniqid(), 'payload' => $payload]
        );
    }

    private function ligneCatalogue(): array
    {
        return [
            'line_type' => OrderItem::LINE_TYPE_CATALOG,
            'item_id' => 1,
            'quantity' => 1,
            'price' => 6.90,
        ];
    }

    /** Le cas du rapport : aucun brouillon vide ne doit être créé. */
    public function test_l_api_refuse_de_parker_une_commande_sans_article(): void
    {
        $this->park(['lists' => [], 'subtotal' => 0, 'discount' => 0, 'total' => 0])
            ->assertStatus(422);

        $this->assertDatabaseCount('pos_parked_orders', 0);
    }

    /**
     * Le service accepte DEUX formes de charge utile : le garde doit couvrir les
     * deux, sinon une règle sur la seule clé `lists` laisserait passer l'autre.
     */
    public function test_l_api_refuse_aussi_la_forme_items_vide(): void
    {
        $this->park(['items' => [], 'total' => 0])->assertStatus(422);

        $this->assertDatabaseCount('pos_parked_orders', 0);
    }

    /** Charge utile sans aucune clé de lignes : toujours pas de brouillon. */
    public function test_l_api_refuse_une_charge_utile_sans_lignes(): void
    {
        $this->park(['total' => 0])->assertStatus(422);

        $this->assertDatabaseCount('pos_parked_orders', 0);
    }

    /** NON-RÉGRESSION : une vraie mise en attente continue de fonctionner. */
    public function test_une_mise_en_attente_avec_articles_fonctionne_toujours(): void
    {
        $this->park(['lists' => [$this->ligneCatalogue()], 'total' => 6.90])
            ->assertCreated()
            ->assertJsonPath('data.items_count', 1);

        $this->assertDatabaseCount('pos_parked_orders', 1);
    }

    /**
     * NON-RÉGRESSION de l'idempotence : un rejeu d'une mise en attente LÉGITIME
     * doit toujours rendre la même ligne, sans être bloqué par le nouveau garde
     * (qui s'applique après le court-circuit d'idempotence).
     */
    public function test_le_rejeu_d_une_mise_en_attente_legitime_reste_idempotent(): void
    {
        $payload = ['lists' => [$this->ligneCatalogue()], 'total' => 6.90];

        $premier = $this->actingAs($this->operator, 'sanctum')->postJson(
            '/api/admin/pos/parked-orders',
            ['label' => 'Test', 'idempotency_token' => 'tok-rejeu', 'payload' => $payload]
        )->assertCreated();

        $this->actingAs($this->operator, 'sanctum')->postJson(
            '/api/admin/pos/parked-orders',
            ['label' => 'Test', 'idempotency_token' => 'tok-rejeu', 'payload' => $payload]
        )->assertSuccessful();

        $this->assertDatabaseCount('pos_parked_orders', 1);
    }

    /**
     * Garde de PÉRIMÈTRE sur l'écran : le bouton « Mettre en attente » ne doit
     * plus être présenté comme actionnable sur un panier vide. Le gestionnaire
     * refusait déjà, mais le bouton restait cliquable — l'audit en a tiré une
     * fausse conclusion, et un contrôle activé qui refuse est une mauvaise
     * affordance.
     */
    public function test_le_bouton_de_mise_en_attente_est_desactive_sur_panier_vide(): void
    {
        $source = file_get_contents(base_path('resources/js/components/admin/pos/PosComponent.vue'));

        $this->assertStringContainsString(
            'parkingInFlight || carts.length === 0',
            $source,
            'Le bouton « Mettre en attente » doit être désactivé quand le panier est vide.'
        );
    }
}

<?php

namespace Tests\Feature\Cash;

use App\Models\Branch;
use App\Models\CashDrawerSession;
use App\Models\CashMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * UNE CLÔTURE RÉELLE DOIT PRODUIRE UN ÉCART CHIFFRÉ — PAS SEULEMENT « NE PAS PLANTER ».
 *
 * ── MESURÉ EN PRODUCTION (GOAL_CAYENNE_FINITION §1.1) ────────────────────────────────────────
 * 2 sessions de caisse ouvertes, 0 close, 237 mouvements pour 3 818,30 €, ZÉRO variance jamais
 * calculée. Le backend (`CashDrawerService::closeSession` / `reconcileSession`) semblait complet ;
 * ce test exerce le VRAI chemin HTTP que le frontend appelle (`CashDrawerSessionController`),
 * pas seulement le service en PHP direct — c'est exactement là qu'un bug de contrat frontend↔API
 * (paramètre attendu par le backend mais jamais envoyé) serait invisible à un test service-only.
 *
 * ── LE BUG TROUVÉ EN AUDITANT (voir resources/js/services/CashDrawerService.js) ─────────────
 * Le frontend appelait POST /reconcile avec un corps VIDE `{}` — `variance_reason`, pourtant saisi
 * par le caissier dans le dialog, n'était jamais transmis. Le backend exige cette raison dès que
 * |variance| dépasse `cash.variance_threshold_eur` (2,00 € par défaut) → 422 systématique dès
 * qu'une session accumule un écart réel (quasi certain sur 36-49 jours). Corrigé côté JS
 * (`CashDrawerService.closeSession` forwarde désormais `variance_reason` au POST /reconcile) —
 * verrouillé côté JS par tests/js/cashDrawerServiceReconcileReason.spec.js.
 *
 * Ce fichier verrouille le CONTRAT BACKEND que ce correctif frontend dépend : la clôture calcule
 * une variance NUMÉRIQUE réelle, le seuil I6 exige la raison au-dessus du seuil (pas en-dessous),
 * et l'écart devient visible dans la réponse JSON — exactement ce que l'écran affiche.
 */
class CashDrawerCloseVarianceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $cashier;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();

        $this->branch = Branch::factory()->create();

        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier->assignRole('POS Operator');

        $this->manager = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->manager->assignRole('Branch Manager');

        Config::set('cash.variance_threshold_eur', 2.00);
        Config::set('cash.variance_manager_approval_required', true);
    }

    private function openSessionHttp(User $actor, float $opening): int
    {
        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/admin/pos/cash-drawer/sessions/open', [
                'opening_amount' => $opening,
            ]);
        $response->assertStatus(201);

        return (int) $response->json('data.id');
    }

    /**
     * LE CŒUR : ouvrir, enregistrer un mouvement, fermer avec un montant DIFFÉRENT de l'attendu,
     * et vérifier qu'une variance NUMÉRIQUE (pas juste "aucune erreur") est calculée et retournée.
     */
    public function test_une_cloture_reelle_avec_ecart_produit_une_variance_numerique(): void
    {
        $sessionId = $this->openSessionHttp($this->cashier, 100.00);

        // 60,30€ de mouvements caisse pendant le service.
        app(\App\Services\Cash\CashDrawerService::class)->recordMovement(
            $sessionId,
            CashMovement::TYPE_ORDER_PAYMENT,
            60.30,
            CashMovement::DIRECTION_IN,
        );

        // Le caissier ferme le tiroir avec un montant compté DIFFÉRENT de l'attendu (100+60,30=160,30).
        $closeResponse = $this->actingAs($this->cashier, 'sanctum')
            ->postJson("/api/admin/pos/cash-drawer/sessions/{$sessionId}/close", [
                'closing_amount' => 145.10,
            ]);
        $closeResponse->assertStatus(200);
        $this->assertSame('closed', $closeResponse->json('data.status'));

        // Écart de -15,20€ > seuil 2€ → la raison est exigée (miroir du contrat que le JS honore désormais).
        $reconcileResponse = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/admin/pos/cash-drawer/sessions/{$sessionId}/reconcile", [
                'variance_reason' => 'Fond de caisse mal recompté en fin de service',
            ]);

        $reconcileResponse->assertStatus(200);
        $reconcileResponse->assertJsonPath('data.status', 'reconciled');
        $this->assertEqualsWithDelta(160.30, (float) $reconcileResponse->json('data.expected'), 0.001);
        $this->assertEqualsWithDelta(-15.20, (float) $reconcileResponse->json('data.variance'), 0.001);
        $this->assertNotEqualsWithDelta(0.0, (float) $reconcileResponse->json('data.variance'), 0.001, 'la variance est retombée à zéro — elle n\'a pas vraiment été calculée depuis les mouvements réels');

        $session = CashDrawerSession::withoutGlobalScopes()->findOrFail($sessionId);
        $this->assertSame('reconciled', $session->status);
        $this->assertEqualsWithDelta(-15.20, (float) $session->variance, 0.001);
        $this->assertSame('Fond de caisse mal recompté en fin de service', $session->variance_reason);
    }

    /**
     * LE CHEMIN QUI ÉTAIT CASSÉ EN PRODUCTION : écart au-dessus du seuil, réclamé SANS raison
     * (exactement ce que faisait le frontend avant correctif — corps `{}`) → 422 explicite,
     * jamais un succès silencieux qui masquerait l'écart.
     */
    public function test_reconcile_au_dessus_du_seuil_sans_raison_est_refuse_422(): void
    {
        $sessionId = $this->openSessionHttp($this->cashier, 100.00);

        $closeResponse = $this->actingAs($this->cashier, 'sanctum')
            ->postJson("/api/admin/pos/cash-drawer/sessions/{$sessionId}/close", [
                'closing_amount' => 150.00, // +50€ > seuil 2€
            ]);
        $closeResponse->assertStatus(200);

        // Reproduit EXACTEMENT le bug frontend : /reconcile appelé avec un corps vide.
        $reconcileResponse = $this->actingAs($this->cashier, 'sanctum')
            ->postJson("/api/admin/pos/cash-drawer/sessions/{$sessionId}/reconcile", []);

        $reconcileResponse->assertStatus(422);
        $reconcileResponse->assertJsonPath('code', 'CASH_VARIANCE_REASON_REQUIRED');

        $session = CashDrawerSession::withoutGlobalScopes()->findOrFail($sessionId);
        $this->assertSame('closed', $session->status, 'la session ne doit PAS être scellée reconciled sans raison au-dessus du seuil');
        $this->assertNull($session->variance, 'aucune variance ne doit être figée tant que le seuil n\'est pas respecté');
    }

    /**
     * Sous le seuil : la raison n'est PAS exigée — un simple arrondi de comptage ne doit pas
     * bloquer la clôture routinière d'un caissier seul.
     */
    public function test_reconcile_sous_le_seuil_ne_requiert_pas_de_raison(): void
    {
        $sessionId = $this->openSessionHttp($this->cashier, 100.00);

        $closeResponse = $this->actingAs($this->cashier, 'sanctum')
            ->postJson("/api/admin/pos/cash-drawer/sessions/{$sessionId}/close", [
                'closing_amount' => 101.30, // +1,30€, sous le seuil 2€
            ]);
        $closeResponse->assertStatus(200);

        $reconcileResponse = $this->actingAs($this->cashier, 'sanctum')
            ->postJson("/api/admin/pos/cash-drawer/sessions/{$sessionId}/reconcile", []);

        $reconcileResponse->assertStatus(200);
        $reconcileResponse->assertJsonPath('data.status', 'reconciled');
        $this->assertEqualsWithDelta(1.30, (float) $reconcileResponse->json('data.variance'), 0.001);
    }

    /**
     * Au-dessus du seuil, AVEC raison mais un simple caissier (pas de permission
     * `cash.reconcile.variance.override`) → refusé, la clôture attend un manager.
     */
    public function test_reconcile_au_dessus_du_seuil_avec_raison_mais_sans_permission_est_refuse(): void
    {
        $sessionId = $this->openSessionHttp($this->cashier, 100.00);

        $this->actingAs($this->cashier, 'sanctum')
            ->postJson("/api/admin/pos/cash-drawer/sessions/{$sessionId}/close", [
                'closing_amount' => 150.00,
            ])->assertStatus(200);

        $reconcileResponse = $this->actingAs($this->cashier, 'sanctum')
            ->postJson("/api/admin/pos/cash-drawer/sessions/{$sessionId}/reconcile", [
                'variance_reason' => 'Je ne sais pas où sont passés les 50€',
            ]);

        $reconcileResponse->assertStatus(422);
        $reconcileResponse->assertJsonPath('code', 'CASH_VARIANCE_MANAGER_APPROVAL_REQUIRED');
    }
}

<?php

namespace Tests\Feature\Onboarding;

use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [ONB-04/10 2026-08-28] Quatre façons de se tromper en silence, fermées.
 *
 * Trouvées par deux balayages adverses en lecture seule sur les missions que la
 * session n'avait pas retouchées.
 *
 * 1. **Un taux de TVA sans plafond.** `max:9999999999999` : saisir « 2000 » en
 *    pensant « 20 % » donnait un taux de 2000 %, accepté sans un mot et facturé au
 *    client. Aucun régime n'a de TVA au-delà de 100 % — borner n'enlève rien de
 *    réel et attrape la faute de frappe la plus naturelle qui soit.
 *
 * 2. **Un statut de taxe hors énumération.** `max:24` acceptait des valeurs
 *    qu'aucun code ne sait lire.
 *
 * 3. **Une taxe cassée, irréparable depuis le Dashboard.** La création forçait
 *    `type = PERCENTAGE`, la modification ne le touchait jamais. Il a fallu une
 *    MIGRATION pour réparer l'incident documenté — « Frites 2,00 € → TOTAL 22 €,
 *    TVA 20 € ». Le commerçant peut maintenant corriger lui-même.
 *
 * 4. **Le terminal de paiement, jumeau du défaut imprimante.** Clé étrangère sur
 *    `branch_id`, règle `nullable`, et un compte propriétaire à `branch_id = 0` :
 *    violation de contrainte SQL au lieu d'un message.
 */
class LeCommercantNePeutPasSeTromperSilencieusementTest extends TestCase
{
    use RefreshDatabase;

    private User $patron;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->patron = User::factory()->create(['branch_id' => 0]);
        $this->patron->assignRole('Admin');

        foreach (['settings', 'tax', 'tax_create', 'tax_edit'] as $droit) {
            Permission::findOrCreate($droit, 'sanctum');
        }
        $this->patron->givePermissionTo(['settings', 'tax', 'tax_create', 'tax_edit']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->patron, 'sanctum');
    }

    public function test_un_taux_de_tva_de_2000_pour_cent_est_refuse_avec_une_raison(): void
    {
        $reponse = $this->postJson('/api/admin/setting/tax', [
            'name'     => 'TVA normale',
            'code'     => 'TVA20',
            'tax_rate' => 2000,   // la faute de frappe : 2000 pour 20 %
            'status'   => Status::ACTIVE,
        ]);

        $reponse->assertStatus(422);
        $reponse->assertJsonValidationErrors(['tax_rate']);

        $this->assertStringContainsString(
            '100',
            (string) $reponse->json('errors.tax_rate.0'),
            "Le refus ne dit pas où est la limite ni comment saisir correctement."
        );

        $this->assertSame(0, Tax::query()->count(), 'La taxe absurde a quand même été créée.');
    }

    public function test_un_taux_normal_passe_toujours(): void
    {
        // Contrôle de non-régression : borner ne doit rien casser d'utile.
        foreach ([0, 5.5, 10, 20, 100] as $taux) {
            $reponse = $this->postJson('/api/admin/setting/tax', [
                'name'     => 'Taux ' . $taux,
                'code'     => 'T' . str_replace('.', '', (string) $taux),
                'tax_rate' => $taux,
                'status'   => Status::ACTIVE,
            ]);

            $this->assertContains(
                $reponse->status(),
                [200, 201, 202],
                "Le taux {$taux} % est refusé alors qu'il est parfaitement légitime : "
                . mb_substr((string) $reponse->getContent(), 0, 200)
            );
        }
    }

    public function test_une_taxe_bloquee_en_montant_fixe_se_repare_en_la_reenregistrant(): void
    {
        // L'INCIDENT DOCUMENTÉ : une taxe restée en `FIXED` transforme un produit à
        // 2,00 € en total de 22 €. Il avait fallu une migration pour la réparer,
        // parce que la modification ne touchait jamais le type.
        $cassee = Tax::factory()->create([
            'tax_rate' => 20,
            'status'   => Status::ACTIVE,
        ]);
        $cassee->forceFill(['type' => TaxType::FIXED])->saveQuietly();

        $this->assertSame(TaxType::FIXED, (int) $cassee->fresh()->type, 'Départ incohérent.');

        $this->putJson('/api/admin/setting/tax/' . $cassee->id, [
            'name'     => $cassee->name,
            'code'     => $cassee->code,
            'tax_rate' => 20,
            'status'   => Status::ACTIVE,
        ])->assertSuccessful();

        $this->assertSame(
            TaxType::PERCENTAGE,
            (int) $cassee->fresh()->type,
            "La taxe reste bloquée en montant fixe. Le commerçant ne peut pas réparer\n"
            . "depuis son Dashboard une configuration qui facture 22 € un produit à 2 €."
        );
    }

    public function test_le_proprietaire_declare_son_terminal_sans_choisir_d_etablissement(): void
    {
        // LE JUMEAU DU DÉFAUT IMPRIMANTE. Une seule filiale : rien à choisir.
        $seule = Branch::factory()->create();

        $reponse = $this->postJson('/api/admin/payment-terminals', [
            'name'         => 'TPE comptoir',
            'gateway_type' => \App\Models\PaymentTerminal::GATEWAY_SIMULATION,
            'status'       => Status::ACTIVE,
        ]);

        // Assertion POSITIVE. Ma première version disait `assertNotSame(500, …)` :
        // elle aurait été satisfaite par un 422, un 403 ou un 404 — c'est-à-dire par
        // presque tous les échecs. C'est le piège que ce chantier documente depuis
        // trois jours, et je venais de le retendre moi-même.
        $this->assertContains(
            $reponse->status(),
            [200, 201, 202],
            "Le propriétaire ne peut pas déclarer son terminal : "
            . mb_substr((string) $reponse->getContent(), 0, 250)
        );

        $this->assertDatabaseHas('payment_terminals', [
            'name'      => 'TPE comptoir',
            'branch_id' => $seule->id,
        ]);
    }
}

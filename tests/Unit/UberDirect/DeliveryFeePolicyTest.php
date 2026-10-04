<?php

namespace Tests\Unit\UberDirect;

use App\Services\UberDirect\DeliveryFeePolicy;
use PHPUnit\Framework\TestCase;

/**
 * [UBER-DIRECT 2026-09-06] La règle tarifaire, isolée du transport.
 *
 * Décision propriétaire, verbatim : « delivery_fee_customer = montant Uber Direct. Aucune
 * remise. Aucune majoration. Aucun montant minimum permettant une livraison gratuite.
 * Aucune prise en charge du coût par Le Cayenne. » Et : « Ne code jamais 5,90 € ou un
 * autre tarif fixe. »
 *
 * Cette classe existe pour que les évolutions déjà annoncées — livraison offerte au-delà
 * de X €, participation du restaurant, prix plafonné, promotions — se posent ICI, sans
 * jamais toucher à l'intégration Uber. En V1 elle est neutre : elle rend exactement ce
 * qu'Uber facture.
 *
 * Tout est en CENTIMES ENTIERS. Aucun flottant : 742 = 7,42 €.
 */
class DeliveryFeePolicyTest extends TestCase
{
    private function policy(array $reglages = []): DeliveryFeePolicy
    {
        return new DeliveryFeePolicy(array_merge([
            'free_above_cents' => null,
            'restaurant_subsidy_cents' => 0,
            'cap_cents' => null,
        ], $reglages));
    }

    /** @test */
    public function en_V1_le_client_paie_exactement_ce_qu_uber_facture(): void
    {
        $p = $this->policy();

        // Les deux exemples donnés par le propriétaire, au centime près.
        $this->assertSame(590, $p->customerFeeCents(590, 2000));
        $this->assertSame(742, $p->customerFeeCents(742, 2000));
    }

    /** @test */
    public function aucun_tarif_n_est_ecrit_en_dur(): void
    {
        // Un montant Uber arbitraire ressort tel quel : rien dans la classe ne connaît
        // un prix de livraison.
        foreach ([1, 99, 313, 1234, 9999] as $uber) {
            $this->assertSame($uber, $this->policy()->customerFeeCents($uber, 2000));
        }
    }

    /** @test */
    public function un_montant_uber_negatif_ou_absurde_ne_peut_pas_creer_un_avoir(): void
    {
        // Défense : une réponse Uber malformée ne doit jamais produire un frais négatif,
        // qui viendrait DÉDUIRE du total et faire payer le client moins que son panier.
        $this->assertSame(0, $this->policy()->customerFeeCents(-500, 2000));
        $this->assertSame(0, $this->policy()->customerFeeCents(0, 2000));
    }

    /** @test */
    public function la_livraison_offerte_au_dela_d_un_seuil_est_prete_mais_eteinte(): void
    {
        // Évolution annoncée. Éteinte en V1 (null) ; le jour où elle est réglée, elle
        // s'applique sans toucher à l'intégration Uber.
        $eteinte = $this->policy();
        $this->assertSame(742, $eteinte->customerFeeCents(742, 5000));

        $allumee = $this->policy(['free_above_cents' => 3000]);
        $this->assertSame(0, $allumee->customerFeeCents(742, 5000), 'panier au-dessus du seuil');
        $this->assertSame(742, $allumee->customerFeeCents(742, 2999), 'panier en dessous');
        $this->assertSame(0, $allumee->customerFeeCents(742, 3000), 'seuil atteint = offerte');
    }

    /** @test */
    public function la_participation_du_restaurant_est_prete_mais_eteinte(): void
    {
        $this->assertSame(742, $this->policy()->customerFeeCents(742, 2000));

        $avec = $this->policy(['restaurant_subsidy_cents' => 200]);
        $this->assertSame(542, $avec->customerFeeCents(742, 2000));
        // La participation ne peut jamais rendre le frais négatif.
        $this->assertSame(0, $this->policy(['restaurant_subsidy_cents' => 1000])->customerFeeCents(742, 2000));
    }

    /** @test */
    public function le_plafond_est_pret_mais_eteint(): void
    {
        $this->assertSame(1500, $this->policy()->customerFeeCents(1500, 2000));

        $plafonne = $this->policy(['cap_cents' => 900]);
        $this->assertSame(900, $plafonne->customerFeeCents(1500, 2000));
        $this->assertSame(500, $plafonne->customerFeeCents(500, 2000), 'sous le plafond, rien ne change');
    }

    /** @test */
    public function le_cout_reel_uber_reste_lisible_meme_quand_le_client_paie_moins(): void
    {
        // Sans cela, une future livraison offerte ferait disparaître la dépense des
        // comptes : le restaurant paierait Uber sans que rien ne le montre.
        $p = $this->policy(['free_above_cents' => 3000]);

        $this->assertSame(0, $p->customerFeeCents(742, 5000));
        $this->assertSame(742, $p->providerCostCents(742), 'ce qu\'Uber facture ne change jamais');
    }

    /** @test */
    public function la_regle_dit_ce_qu_elle_a_fait(): void
    {
        // Traçabilité : on doit pouvoir expliquer un montant a posteriori.
        $neutre = $this->policy()->explain(742, 2000);
        $this->assertSame('uber_exact', $neutre['rule']);
        $this->assertSame(742, $neutre['customer_cents']);
        $this->assertSame(742, $neutre['provider_cents']);

        $offerte = $this->policy(['free_above_cents' => 3000])->explain(742, 5000);
        $this->assertSame('free_above_threshold', $offerte['rule']);
        $this->assertSame(0, $offerte['customer_cents']);
        $this->assertSame(742, $offerte['provider_cents']);
    }
}

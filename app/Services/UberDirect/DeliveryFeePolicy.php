<?php

namespace App\Services\UberDirect;

/**
 * [UBER-DIRECT 2026-09-06] Ce que le CLIENT paie pour la livraison.
 *
 * Séparée à dessein du transport (`UberDirectClient`/`UberDirectService`) : Uber dit ce que
 * la course COÛTE, cette classe dit ce qu'on FACTURE. Les deux évoluent indépendamment.
 *
 * Décision propriétaire du 2026-09-06, verbatim : « delivery_fee_customer = montant Uber
 * Direct. Aucune remise. Aucune majoration. Aucun montant minimum permettant une livraison
 * gratuite. Aucune prise en charge du coût par Le Cayenne. » Et : « Ne code jamais 5,90 €
 * ou un autre tarif fixe. »
 *
 * En V1 cette classe est donc NEUTRE — elle rend exactement le montant d'Uber. Les trois
 * évolutions déjà annoncées (offerte au-delà de X, participation du restaurant, plafond)
 * sont écrites et testées, mais éteintes par configuration : le jour où le propriétaire en
 * veut une, il règle une variable d'environnement. **L'intégration Uber n'est pas touchée.**
 *
 * ⚠️ TOUT EST EN CENTIMES ENTIERS. Aucun flottant ne traverse cette classe : 742 = 7,42 €.
 * Uber renvoie nativement des centimes ; la conversion vers le décimal du reste de
 * l'application se fait une seule fois, à la frontière (cf. `UberDirectService`).
 */
final class DeliveryFeePolicy
{
    /** @param array{free_above_cents:?int, restaurant_subsidy_cents:int, cap_cents:?int} $reglages */
    public function __construct(private readonly array $reglages)
    {
    }

    public static function fromConfig(): self
    {
        $c = (array) config('uber_direct.pricing', []);

        return new self([
            'free_above_cents' => $c['free_above_cents'] ?? null,
            'restaurant_subsidy_cents' => (int) ($c['restaurant_subsidy_cents'] ?? 0),
            'cap_cents' => $c['cap_cents'] ?? null,
        ]);
    }

    /**
     * Ce que le client paie, en centimes.
     *
     * @param  int  $uberFeeCents     ce qu'Uber facture pour la course
     * @param  int  $orderSubtotalCents  le panier HORS livraison (sert aux seuils)
     */
    public function customerFeeCents(int $uberFeeCents, int $orderSubtotalCents): int
    {
        return $this->explain($uberFeeCents, $orderSubtotalCents)['customer_cents'];
    }

    /**
     * Ce que la course coûte réellement au restaurant, en centimes.
     *
     * Toujours le montant d'Uber, même quand le client paie moins : sans cela, une future
     * livraison offerte ferait disparaître la dépense des comptes.
     */
    public function providerCostCents(int $uberFeeCents): int
    {
        return max(0, $uberFeeCents);
    }

    /**
     * Le montant ET la raison — pour pouvoir expliquer une facture a posteriori.
     *
     * @return array{rule:string, customer_cents:int, provider_cents:int}
     */
    public function explain(int $uberFeeCents, int $orderSubtotalCents): array
    {
        // Défense : une réponse Uber malformée (montant négatif) ne doit jamais produire un
        // frais négatif, qui viendrait DÉDUIRE du total et faire payer le panier moins cher.
        $cout = max(0, $uberFeeCents);

        $seuil = $this->reglages['free_above_cents'] ?? null;
        if ($seuil !== null && $orderSubtotalCents >= (int) $seuil) {
            return ['rule' => 'free_above_threshold', 'customer_cents' => 0, 'provider_cents' => $cout];
        }

        $facture = $cout;
        $regle = 'uber_exact';

        $participation = (int) ($this->reglages['restaurant_subsidy_cents'] ?? 0);
        if ($participation > 0) {
            $facture = max(0, $facture - $participation);
            $regle = 'restaurant_subsidy';
        }

        $plafond = $this->reglages['cap_cents'] ?? null;
        if ($plafond !== null && $facture > (int) $plafond) {
            $facture = (int) $plafond;
            $regle = 'capped';
        }

        return ['rule' => $regle, 'customer_cents' => $facture, 'provider_cents' => $cout];
    }
}

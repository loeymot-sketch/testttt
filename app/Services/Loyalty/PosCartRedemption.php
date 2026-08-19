<?php

namespace App\Services\Loyalty;

use App\Enums\Status;
use App\Models\User;

/**
 * CE QUE VAUT UN RACHAT DE POINTS SUR UN PANIER DE CAISSE — une seule définition.
 *
 * ── POURQUOI CETTE CLASSE EXISTE ─────────────────────────────────────────────────────────────
 * « Utiliser ses points » en caisse ne passait JAMAIS, et ce n'était pas un bug de surface :
 * `PosRedemptionService` (le chemin historique) refuse toute commande déjà payée ou terminale,
 * or une vente de comptoir naît PAYÉE et LIVRÉE dans le même geste. Reproduit en HTTP le
 * 2026-08-19 : `POST /admin/pos-order/{id}/redeem-loyalty` → 409 ORDER_ALREADY_FINALIZED. La
 * fenêtre autorisée était donc VIDE pour une vente au comptoir — le refus du propriétaire
 * (« ça passe jamais ») était exact, et structurel.
 *
 * La remise doit exister AVANT que l'argent soit encaissé, sinon le caissier collecte le mauvais
 * montant. Elle doit donc être calculée deux fois — au devis scellé, puis à la création — et
 * rendre EXACTEMENT le même chiffre, sinon le sceau du devis rejette la vente (409 « total does
 * not match »). Deux calculs séparés qui doivent s'accorder sans se parler, c'est précisément le
 * motif du « jumeau oublié » qui a déjà coûté cher à ce projet (barème dupliqué en 4 endroits,
 * plancher appliqué par 3 surfaces sur 4). D'où une classe, appelée par les deux chemins.
 *
 * ── CE QU'ELLE NE FAIT PAS ───────────────────────────────────────────────────────────────────
 * Aucune écriture. Elle dit ce que le rachat VAUT ; le débit du solde et la ligne du grand-livre
 * appartiennent à la transaction de création de commande, qui seule peut les rendre atomiques
 * avec la vente. Séparer les deux évite qu'un simple calcul de devis débite un client.
 */
final class PosCartRedemption
{
    public function __construct(private readonly LoyaltyRules $regles)
    {
    }

    /**
     * @return array{discount: float, points: int, customer: ?User, reason: string}
     *         `reason` vaut 'ok' quand la remise s'applique ; sinon il NOMME l'obstacle, pour
     *         que le comptoir puisse dire « il manque 300 points » au lieu d'afficher un prix
     *         plein sans explication.
     */
    public function compute(
        ?string $loyaltyCode,
        int $pointsDemandes,
        float $sousTotal,
        float $remiseDejaPosee = 0.0
    ): array {
        $vide = ['discount' => 0.0, 'points' => 0, 'customer' => null, 'reason' => 'none'];

        $code = trim((string) $loyaltyCode);
        if ($code === '' || $pointsDemandes <= 0) {
            return $vide;
        }

        // [AUDIT FIDÉLITÉ 2026-08-01] `status = 1` seul rendait introuvables les comptes ACTIVE(5)
        // — c'est-à-dire la quasi-totalité — et la remise retombait à zéro EN SILENCE. On accepte
        // les deux valeurs, comme partout ailleurs dans le programme.
        $client = User::query()
            ->where('loyalty_code', $code)
            ->whereIn('status', [1, Status::ACTIVE])
            ->first();

        if (! $client) {
            return $vide + ['reason' => 'customer_not_found'];
        }

        $solde = (int) $client->loyalty_points;

        // Ce que ce solde permet vraiment : plancher effectif + multiple du taux (SSOT LoyaltyRules).
        $utilisables = $this->regles->usablePoints($solde);
        if ($utilisables <= 0) {
            return ['discount' => 0.0, 'points' => 0, 'customer' => $client, 'reason' => 'below_floor'];
        }

        $points = min($pointsDemandes, $utilisables);

        // Plafond de la commande : la remise CUMULÉE ne peut pas dépasser le sous-total, sinon on
        // débiterait des points pour une valeur non livrée (total clampé à 0). Le cumul, pas le
        // rachat seul — c'est la correction déjà apprise sur PosRedemptionService.
        $margeEuros = round(max(0.0, $sousTotal - max(0.0, $remiseDejaPosee)), 2);
        if ($margeEuros <= 0.0) {
            return ['discount' => 0.0, 'points' => 0, 'customer' => $client, 'reason' => 'no_room'];
        }

        $taux = $this->regles->rate();

        // On arrondit vers le BAS, en POINTS ENTIERS : jamais débiter plus que la valeur
        // réellement accordée, jamais laisser une remise au demi-centime.
        $pointsPlafonnes = (int) floor($margeEuros * $taux);
        $points = min($points, $pointsPlafonnes);

        // Retomber sous le plancher après plafonnement n'est pas un « petit rachat », c'est un
        // rachat interdit : on ne contourne pas le seuil par le haut.
        if ($points <= 0 || $points < $this->regles->effectiveFloor()) {
            return ['discount' => 0.0, 'points' => 0, 'customer' => $client, 'reason' => 'below_floor'];
        }

        // Le rachat porte sur un multiple exact du taux — la valeur rendue vaut exactement les
        // points débités (pas de centime perdu d'un côté ou de l'autre).
        $points = intdiv($points, $taux) * $taux;
        if ($points <= 0) {
            return ['discount' => 0.0, 'points' => 0, 'customer' => $client, 'reason' => 'below_floor'];
        }

        return [
            'discount' => round($points / $taux, 2),
            'points'   => $points,
            'customer' => $client,
            'reason'   => 'ok',
        ];
    }
}

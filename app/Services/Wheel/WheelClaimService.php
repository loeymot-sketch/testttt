<?php

namespace App\Services\Wheel;

use App\Models\Scopes\BranchScope;
use App\Models\StockOutflow;
use App\Models\WheelSpin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * LA DÉCHARGE — le coût réel de ce qu'on offre.
 *
 * Exigence du propriétaire, et elle est juste : « le truc gratuit qu'on donne, ça va être calculé
 * dans les charges ». Un produit offert n'est pas gratuit. Il sort du stock, il a coûté de la
 * matière et du travail. S'il n'apparaît nulle part, deux choses dérivent en silence :
 *   · la MARGE affichée devient fausse — on croit vendre à 70 % de marge alors qu'on a donné
 *     quinze menus dans le mois ;
 *   · l'INVENTAIRE dérive — le stock théorique s'écarte du réel, et à l'inventaire suivant l'écart
 *     est mis sur le compte du vol ou de la casse.
 *
 * ── POURQUOI UNE RÉCONCILIATION ET PAS UN CROCHET ────────────────────────────────────────────
 * On pourrait inscrire la charge au moment où le coupon est consommé, dans `CouponService`. On ne
 * le fait pas, et c'est délibéré : ce chemin est le cœur du calcul des remises, déjà verrouillé
 * par des tests de concurrence, et une erreur y coûte de l'argent sur CHAQUE commande. Y greffer
 * une écriture de stock, c'est risquer une panne d'encaissement pour une écriture comptable.
 *
 * La réconciliation lit l'après-coup : pour chaque lot gagné dont le coupon a RÉELLEMENT été
 * consommé, elle inscrit la charge si ce n'est pas déjà fait. Elle est donc :
 *   · IDEMPOTENTE — `wheel_spins.cost_outflow_id` marque ce qui est déjà inscrit ; la relancer
 *     dix fois n'écrit rien de plus ;
 *   · SANS RISQUE pour la prise de commande — elle tourne à côté, jamais dedans ;
 *   · RATTRAPANTE — si elle n'a pas tourné pendant trois jours, elle rattrape les trois jours.
 *
 * ── CE QU'ELLE N'INVENTE PAS ─────────────────────────────────────────────────────────────────
 * Seuls les lots de type `free_item` génèrent une charge. Une remise en pourcentage n'est PAS une
 * sortie de stock : elle réduit la recette, ce que la comptabilité voit déjà par le montant
 * encaissé. L'inscrire en plus compterait le cadeau deux fois.
 * Et le PRODUIT DE RÉFÉRENCE est configuré, pas deviné. `stock_outflows.item_id` est NOT NULL —
 * la table est née pour les repas et les pertes, où l'on nomme toujours ce qui sort. La roue, elle,
 * promet « une boisson » et l'équipe sert ce qu'elle veut. Choisir le produit tout seul (le premier
 * de la catégorie…) inscrirait dans les charges un produit que PERSONNE n'a choisi et ferait dériver
 * son inventaire. On demande donc à l'exploitant, par segment (`cost_item_id`) : ce n'est pas une
 * donnée inventée, c'est une décision de gestion. Tant qu'un segment n'est pas configuré, son cadeau
 * n'est PAS chiffré — et la réconciliation le SIGNALE à chaque passage plutôt que de laisser un trou
 * silencieux.
 */
class WheelClaimService
{
    /**
     * Inscrit la charge des lots consommés qui ne l'ont pas encore.
     *
     * @return array{examines: int, inscrits: int, ignores: int}
     */
    public function reconcile(?int $branchId = null): array
    {
        if (! (bool) config('wheel.record_cost_on_claim', true)) {
            return ['examines' => 0, 'inscrits' => 0, 'ignores' => 0];
        }

        $spins = WheelSpin::query()
            ->withoutGlobalScope(BranchScope::class)
            ->whereNotNull('coupon_id')
            ->whereNull('cost_outflow_id')
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('id')
            ->get();

        $inscrits = 0;
        $ignores = 0;
        $aConfigurer = [];

        foreach ($spins as $spin) {
            // Un pourcentage n'est pas une sortie de stock : la recette réduite le dit déjà.
            if ($spin->prize_type !== 'free_item') {
                $ignores++;
                continue;
            }

            $consommation = $this->redemption((int) $spin->coupon_id);
            if ($consommation === null) {
                // Lot gagné mais pas encore utilisé : il ne coûte encore rien. On n'inscrit RIEN —
                // provisionner un cadeau non consommé gonflerait les charges de lots que la moitié
                // des clients ne viendra jamais chercher.
                $ignores++;
                continue;
            }

            $itemId = $this->costItemId((string) $spin->prize_key);
            if ($itemId === null) {
                // Segment sans produit de référence : on ne devine pas, on le NOMME. Un trou
                // signalé se corrige ; un trou silencieux se découvre à l'inventaire.
                $aConfigurer[(string) $spin->prize_key] = true;
                $ignores++;
                continue;
            }

            $inscrits += $this->inscrire($spin, $consommation, $itemId) ? 1 : 0;
        }

        return [
            'examines' => $spins->count(),
            'inscrits' => $inscrits,
            'ignores' => $ignores,
            'a_configurer' => array_keys($aConfigurer),
        ];
    }

    /** @return object{order_id: int, created_at: string|null}|null */
    private function redemption(int $couponId)
    {
        return DB::table('order_coupons')
            ->where('coupon_id', $couponId)
            ->orderBy('id')
            ->select('order_id', 'created_at')
            ->first();
    }

    /** Produit de référence configuré pour ce segment, ou NUL s'il n'a pas été choisi. */
    private function costItemId(string $prizeKey): ?int
    {
        foreach ((array) config('wheel.segments', []) as $s) {
            if ((string) ($s['key'] ?? '') === $prizeKey) {
                $id = (int) ($s['cost_item_id'] ?? 0);

                return $id > 0 ? $id : null;
            }
        }

        return null;
    }

    private function inscrire(WheelSpin $spin, $consommation, int $itemId): bool
    {
        try {
            return (bool) DB::transaction(function () use ($spin, $consommation, $itemId) {
                // On re-verrouille et re-vérifie DANS la transaction : deux exécutions simultanées
                // de la réconciliation (cron + lancement manuel) inscriraient sinon deux charges
                // pour un seul cadeau.
                $frais = WheelSpin::query()
                    ->withoutGlobalScope(BranchScope::class)
                    ->whereKey($spin->id)
                    ->lockForUpdate()
                    ->first();

                if (! $frais || $frais->cost_outflow_id !== null) {
                    return false;
                }

                $sortie = StockOutflow::create([
                    'branch_id' => (int) $frais->branch_id,
                    // Produit de RÉFÉRENCE choisi par l'exploitant pour ce segment. Ce n'est pas
                    // forcément ce qui a été servi — c'est ce qui chiffre le cadeau.
                    'item_id'   => $itemId,
                    'item_name' => $frais->prize_label,
                    'quantity'  => 1,
                    'type'      => StockOutflow::TYPE_PROMO_GIFT,
                    'note'      => 'Roue — commande #' . $consommation->order_id
                        . ' — tour #' . $frais->id,
                    'user_id'   => null,
                    // Le stock n'est PAS décrémenté ici : l'article servi n'est pas identifié, et
                    // décrémenter au hasard fausserait l'inventaire au lieu de le corriger. La
                    // charge est enregistrée, la décrémentation reste au geste de l'équipe.
                    'stock_decremented' => false,
                    'created_at' => now(),
                ]);

                $frais->cost_outflow_id = $sortie->id;
                $frais->claimed_order_id = (int) $consommation->order_id;
                $frais->claimed_at = $consommation->created_at ?: now();
                $frais->save();

                return true;
            });
        } catch (\Throwable $e) {
            // Une charge non inscrite est un problème comptable, pas une panne de service : on
            // journalise et on continue le lot suivant. Le prochain passage rattrapera.
            Log::channel('daily')->error('wheel.claim.reconcile_failed', [
                'spin_id' => $spin->id, 'message' => $e->getMessage(),
            ]);

            return false;
        }
    }
}

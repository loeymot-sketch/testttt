<?php

namespace App\Services\Wheel;

use App\Enums\DiscountType;
use App\Models\Coupon;
use App\Models\Item;
use App\Models\Scopes\BranchScope;
use App\Models\StockOutflow;
use App\Models\WheelSpin;
use Illuminate\Support\Carbon;

/**
 * CE QUE LA ROUE A DONNÉ, ET CE QU'ELLE A COÛTÉ.
 *
 * ── LE MANQUE ────────────────────────────────────────────────────────────────────────────────
 * [2026-08-10 · « relis avec notre système de gestion et de contrôle »] Le jeu avait des PLAFONDS —
 * un par lot, un pour la journée — mais **aucun endroit où lire ce qui était réellement sorti**. Le
 * propriétaire réglait donc des limites à l'aveugle, et la seule restitution existante était une
 * commande en ligne de commande qu'il n'exécutera jamais.
 *
 * Un dispositif de contrôle sans lecture n'est pas un contrôle : c'est une intention. Ce service est
 * la lecture, et elle s'affiche sur l'accueil de la roue, là où il passe déjà.
 *
 * ── L'HONNÊTETÉ SUR LE CHIFFRE ───────────────────────────────────────────────────────────────
 * `items` ne porte QUE le prix de vente : il n'y a pas de prix d'achat dans cette base. On ne peut
 * donc pas calculer un coût de revient, et on ne prétend pas le faire. Ce qui est affiché est la
 * VALEUR AU PRIX DE VENTE de ce qui a été offert — c'est-à-dire le chiffre d'affaires abandonné, pas
 * la dépense. Le nommer autrement serait inventer une marge.
 *
 * Pour les remises en pourcentage, on ne connaît pas le montant réellement déduit sans rouvrir
 * chaque commande. On donne donc ce qui est certain : combien de codes ont été émis, combien ont été
 * CONSOMMÉS, et l'exposition maximale (la somme des plafonds). Un maximum vrai vaut mieux qu'une
 * moyenne inventée.
 */
class WheelReportService
{
    /**
     * @return array{
     *   periodes: array<string, array<string, mixed>>,
     *   plafond_jour: array{utilise: int, plafond: int},
     *   lots_dus: int,
     *   avertissements: array<int, string>
     * }
     */
    public function tableau(int $branchId): array
    {
        $periodes = [
            'aujourdhui' => ['libelle' => "Aujourd'hui", 'depuis' => Carbon::today()],
            'semaine' => ['libelle' => '7 derniers jours', 'depuis' => Carbon::today()->subDays(6)],
            'mois' => ['libelle' => '30 derniers jours', 'depuis' => Carbon::today()->subDays(29)],
        ];

        $out = [];
        foreach ($periodes as $cle => $p) {
            $out[$cle] = ['libelle' => $p['libelle']] + $this->mesures($branchId, $p['depuis']);
        }

        return [
            'periodes' => $out,
            'plafond_jour' => [
                'utilise' => $this->tours($branchId, Carbon::today())->count(),
                'plafond' => (int) config('wheel.daily_total_cap', 0),
            ],
            // Ce qui est DÛ mais pas encore remis : c'est de l'argent promis qui dort, et il faut
            // qu'un humain sache qu'il existe.
            'lots_dus' => WheelSpin::query()
                ->withoutGlobalScope(BranchScope::class)
                ->where('branch_id', $branchId)
                ->whereNull('delivered_at')
                ->whereIn('prize_type', ['free_item', 'points'])
                ->count(),
            'avertissements' => $this->avertissements($branchId),
        ];
    }

    /** @return \Illuminate\Database\Eloquent\Builder<WheelSpin> */
    private function tours(int $branchId, Carbon $depuis)
    {
        return WheelSpin::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)
            ->where('created_at', '>=', $depuis);
    }

    /**
     * TOUT EST MESURÉ SUR LE TOUR, ET RIEN D'AUTRE.
     *
     * [P0 + P1 2026-08-10 · audit ronde 2] Trois défauts vivaient ici, et tous venaient de la même
     * paresse : compter des lignes au lieu de partir des participations.
     *
     *   · `Coupon::withoutGlobalScopes()->where('code','like','ROUE-%')` ramassait TOUT — les coupons
     *     SUPPRIMÉS (le filtre de suppression douce fait partie des portées globales : 179 € sur
     *     237 € mesurés), ceux d'une AUTRE caisse (33 €), et jusqu'aux coupons simplement NOMMÉS
     *     « ROUE-… » créés à la main. Le tableau exagérait l'exposition d'un facteur 9,5. On règle des
     *     plafonds sur ce chiffre : le fausser est pire que ne rien afficher.
     *   · DEUX HORLOGES jamais réconciliées : les tours datés sur la participation, les cadeaux et la
     *     valeur datés sur la ligne de sortie de stock. Un lot gagné le 1er et retiré le 20 comptait
     *     donc dans « aujourd'hui » sans que son tour y figure — d'où un « 2 tours / 3 cadeaux remis »
     *     que personne ne peut expliquer. C'est le TOUR qui date un lot, de bout en bout.
     *   · Les POINTS n'étaient valorisés nulle part alors qu'ils font la majorité des lots quand les
     *     codes de remise sont éteints : le tableau affichait 0,00 € pour l'essentiel du coût.
     *
     * @return array<string, mixed>
     */
    private function mesures(int $branchId, Carbon $depuis): array
    {
        $tours = $this->tours($branchId, $depuis)
            ->get(['id', 'prize_type', 'prize_label', 'delivered_at', 'coupon_id',
                'points_awarded', 'cost_outflow_id', 'created_at']);

        $parType = [];
        foreach ($tours as $t) {
            $parType[(string) $t->prize_type] = ($parType[(string) $t->prize_type] ?? 0) + 1;
        }

        // ── LES PRODUITS OFFERTS, par leurs TOURS ─────────────────────────────────────────────
        $remis = $tours->where('prize_type', 'free_item')->whereNotNull('delivered_at');
        $idsSorties = $remis->pluck('cost_outflow_id')->filter()->unique()->all();

        $sorties = $idsSorties === [] ? collect() : StockOutflow::query()
            ->withoutGlobalScope(BranchScope::class)
            ->whereIn('id', $idsSorties)
            ->get(['id', 'item_id', 'quantity', 'stock_decremented']);

        $prix = $sorties->isEmpty() ? collect() : Item::query()
            // Ici les produits SUPPRIMÉS comptent, et on le dit : un cadeau remis hier garde son prix
            // même si l'article a quitté la carte depuis. Le pluriel donnait le même résultat par
            // ACCIDENT — l'écrire explicitement empêche qu'on « répare » ce comportement voulu.
            ->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->withTrashed()
            ->whereIn('id', $sorties->pluck('item_id')->unique()->all())
            ->pluck('price', 'id');

        $valeur = 0.0;
        $sansStock = 0;
        foreach ($sorties as $s) {
            $valeur += (float) ($prix[$s->item_id] ?? 0) * max(1, (int) $s->quantity);
            if (! (bool) $s->stock_decremented) {
                $sansStock++;
            }
        }

        // ── LES POINTS, valorisés au barème DE LA MAISON ──────────────────────────────────────
        $points = (int) $tours->where('prize_type', 'points')->whereNotNull('delivered_at')
            ->sum('points_awarded');

        // ── LES CODES DE REMISE, par leurs TOURS ──────────────────────────────────────────────
        // Pas de `withoutGlobalScopes()` : le filtre de suppression douce doit RESTER. Et on part des
        // `coupon_id` des tours de CETTE caisse — un coupon qui n'est rattaché à aucun tour n'est pas
        // un lot de la roue, quel que soit son nom.
        $idsCoupons = $tours->pluck('coupon_id')->filter()->unique()->all();
        $codes = $idsCoupons === [] ? collect() : Coupon::query()
            ->whereIn('id', $idsCoupons)
            ->get(['id', 'usage_count', 'maximum_discount', 'discount', 'discount_type']);

        $dehors = $codes->where('usage_count', 0);
        // Un pourcentage SANS plafond est le seul lot réellement illimité — et `config/wheel.php`
        // documente lui-même « 0 = illimité côté moteur de coupons ». L'additionner à 0 € rendait le
        // chiffre censé être « le pire cas » muet précisément là où il compte. On le compte à part.
        $sansPlafond = $dehors->filter(
            fn ($c) => (string) $c->discount_type === (string) DiscountType::PERCENTAGE
                && (float) $c->maximum_discount <= 0
        );

        return [
            'tours' => $tours->count(),
            'par_type' => $parType,
            'cadeaux_remis' => $remis->count(),
            'cadeaux_dus' => $tours->whereIn('prize_type', ['free_item', 'points'])
                ->whereNull('delivered_at')->count(),
            'valeur_offerte' => round($valeur, 2),
            'cadeaux_sans_stock' => $sansStock,
            'points_remis' => $points,
            'valeur_points' => round($points / max(1, $this->baremePoints()), 2),
            'codes_emis' => $codes->count(),
            'codes_utilises' => $codes->where('usage_count', '>', 0)->count(),
            'exposition_max' => round((float) $dehors->sum('maximum_discount'), 2),
            'codes_sans_plafond' => $sansPlafond->count(),
        ];
    }

    /**
     * Combien de points valent un euro de remise, selon le barème DE LA MAISON — pas une constante
     * inventée ici. Sans réglage lisible, on retombe sur 100 (la valeur observée en base) plutôt que
     * de diviser par zéro ou d'annoncer un coût nul.
     */
    private function baremePoints(): int
    {
        try {
            $v = (int) \Smartisan\Settings\Facades\Settings::group('loyalty_setup')
                ->get('loyalty_points_for_1_euro_discount');

            return $v > 0 ? $v : 100;
        } catch (\Throwable $e) {
            return 100;
        }
    }

    /** @return array<int, string> */
    private function avertissements(int $branchId): array
    {
        $out = [];

        // Un cadeau non chiffré est un trou dans les charges : la commande de réconciliation le dit
        // déjà, mais personne ne la lance. On le dit ici, où le propriétaire passe.
        $sans = StockOutflow::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)
            ->where('type', StockOutflow::TYPE_PROMO_GIFT)
            ->where('created_at', '>=', Carbon::today()->subDays(29))
            ->where('stock_decremented', false)
            ->count();
        if ($sans > 0) {
            // « À corriger à l'inventaire » laissait croire que l'avertissement s'éteindrait après
            // correction. Il ne peut PAS : `stock_outflows` porte un déclencheur qui interdit toute
            // modification, la colonne est immuable. C'est un JOURNAL, pas une tâche à cocher — et le
            // dire ainsi évite qu'on le prenne pour un signal cassé, donc qu'on cesse de le lire.
            $out[] = 'Sur 30 jours, ' . $sans . ' cadeau' . ($sans > 1 ? 'x a' : ' a')
                . ' été remis sans sortir du stock (produit composite, produit sans niveau de stock, '
                . 'ou rayon déjà à zéro). Ce relevé est un historique : il ne s\'effacera pas. '
                . 'Ce qu\'il faut faire, c\'est reprendre l\'écart au prochain inventaire.';
        }

        // Un pourcentage SANS plafond est le seul lot dont l'exposition est INCONNUE, pas nulle. Le
        // taire, c'est laisser un 0 € rassurer là où il ne faut pas.
        $sansPlafond = $this->mesures($branchId, Carbon::today()->subDays(29))['codes_sans_plafond'];
        if ($sansPlafond > 0) {
            $out[] = $sansPlafond . ' code' . ($sansPlafond > 1 ? 's' : '') . ' de remise en pourcentage '
                . 'SANS PLAFOND en euros : sur une grosse commande, la remise n\'a aucune limite. '
                . 'Son exposition n\'est pas comptée dans le chiffre ci-dessus — elle est inconnue. '
                . 'Pose un plafond dans config/wheel.php (max_discount).';
        }

        /*
         * L'interrupteur des codes de remise. C'est le réglage le plus coûteux du lot : éteint, il
         * retire de la roue tous les lots en pourcentage — et l'exploitant n'a aucune raison de
         * deviner qu'un réglage de la CAISSE décide de ce que sa roue peut offrir.
         */
        if (! app(WheelService::class)->remisesAcceptees()) {
            $poidsRemises = 0;
            $poidsTotal = 0;
            foreach (app(\App\Services\Wheel\WheelService::class)->segments() as $s) {
                $w = max(0, (int) ($s['weight'] ?? 0));
                $poidsTotal += $w;
                if (str_starts_with((string) ($s['type'] ?? ''), 'coupon_')) {
                    $poidsRemises += $w;
                }
            }
            if ($poidsRemises > 0) {
                $part = $poidsTotal > 0 ? (int) round(100 * $poidsRemises / $poidsTotal) : 0;
                $out[] = 'Les codes de remise sont ÉTEINTS dans la caisse : les lots en pourcentage '
                    . '(' . $part . ' % de la roue) ne sont pas proposés — un code émis serait refusé '
                    . 'au panier. Pour les rallumer : POS_COUPON_CODES_ENABLED=true.';
            }
        }

        // Un segment sans produit de référence ne sera JAMAIS chiffré.
        $orphelins = [];
        foreach (app(\App\Services\Wheel\WheelService::class)->segments() as $s) {
            if ((string) ($s['type'] ?? '') !== 'free_item') {
                continue;
            }
            if (app(WheelDeliveryService::class)->costItemId((string) ($s['key'] ?? '')) === null) {
                $orphelins[] = (string) ($s['label'] ?? $s['key'] ?? '?');
            }
        }
        if ($orphelins !== []) {
            $out[] = 'Lots sans produit de référence, donc jamais chiffrés dans les charges : '
                . implode(', ', $orphelins) . '.';
        }

        return $out;
    }
}

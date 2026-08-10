<?php

namespace App\Services\Wheel;

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

    /** @return array<string, mixed> */
    private function mesures(int $branchId, Carbon $depuis): array
    {
        $tours = $this->tours($branchId, $depuis)->get(['id', 'prize_type', 'prize_label', 'delivered_at', 'coupon_id']);

        $parType = [];
        foreach ($tours as $t) {
            $parType[(string) $t->prize_type] = ($parType[(string) $t->prize_type] ?? 0) + 1;
        }

        // Valeur des produits offerts, AU PRIX DE VENTE (voir le docbloc : pas un coût de revient).
        $sorties = StockOutflow::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)
            ->where('type', StockOutflow::TYPE_PROMO_GIFT)
            ->where('created_at', '>=', $depuis)
            ->get(['item_id', 'quantity', 'stock_decremented']);

        $prix = $sorties->isEmpty() ? collect() : Item::query()
            ->withoutGlobalScopes()
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

        // Les codes de remise émis sur la période, et ceux réellement consommés.
        $codes = Coupon::query()
            ->withoutGlobalScopes()
            ->where('code', 'like', 'ROUE-%')
            ->where('created_at', '>=', $depuis)
            ->get(['usage_count', 'maximum_discount', 'discount', 'discount_type']);

        return [
            'tours' => $tours->count(),
            'par_type' => $parType,
            'cadeaux_remis' => $sorties->count(),
            'cadeaux_dus' => $tours->whereIn('prize_type', ['free_item', 'points'])
                ->whereNull('delivered_at')->count(),
            'valeur_offerte' => round($valeur, 2),
            'cadeaux_sans_stock' => $sansStock,
            'codes_emis' => $codes->count(),
            'codes_utilises' => $codes->where('usage_count', '>', 0)->count(),
            // Exposition MAXIMALE des codes encore vivants : la somme des plafonds de ceux qui
            // n'ont pas encore été consommés. C'est le pire cas, et c'est le seul chiffre honnête.
            'exposition_max' => round((float) $codes->where('usage_count', 0)->sum('maximum_discount'), 2),
        ];
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
            $out[] = $sans . ' cadeau' . ($sans > 1 ? 'x' : '') . ' sans décrément de stock sur 30 jours '
                . '(produit composite, ou rayon déjà à zéro) — à corriger à l\'inventaire.';
        }

        /*
         * L'interrupteur des codes de remise. C'est le réglage le plus coûteux du lot : éteint, il
         * retire de la roue tous les lots en pourcentage — et l'exploitant n'a aucune raison de
         * deviner qu'un réglage de la CAISSE décide de ce que sa roue peut offrir.
         */
        if (! app(WheelService::class)->remisesAcceptees()) {
            $poidsRemises = 0;
            $poidsTotal = 0;
            foreach ((array) config('wheel.segments', []) as $s) {
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
        foreach ((array) config('wheel.segments', []) as $s) {
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

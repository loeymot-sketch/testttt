<?php

namespace App\Services\Wheel;

use App\Enums\DiscountType;
use App\Models\Coupon;
use App\Models\Item;
use App\Models\Scopes\BranchScope;
use App\Models\StockOutflow;
use App\Models\WheelSpin;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
    /**
     * LES DERNIERS GAGNANTS — la seule chose NEUVE qu'on ait le droit d'afficher sur la vitrine.
     *
     * [2026-08-13 · propriétaire : « tu affiches la roue avec les produits à gagner et en bas tu
     * affiches ENCORE les photos ainsi que leur nom, c'est catastrophique, faire lire deux fois la
     * même chose »] Il a raison. La roue porte déjà le nom et la photo de chaque lot ; l'acte qui
     * les réimprimait juste en dessous ne disait rien de plus. Il est supprimé. Ce qui prend sa
     * place doit apporter une information que la roue ne donne pas — et il n'y en a qu'une qui
     * compte pour quelqu'un qui hésite : **ça donne vraiment, et ça vient de donner**.
     *
     * ── CE QU'ON N'AFFICHE PAS ───────────────────────────────────────────────────────────────
     * Jamais le numéro de téléphone. Jamais un nom complet. Le prénom seul, et seulement s'il a été
     * donné : les comptes créés par la roue sont des invités clés par le téléphone, `customer_name`
     * est souvent vide. Sans prénom on écrit « quelqu'un » — c'est vrai, et ça marche aussi bien.
     *
     * ── POURQUOI ON REND UN HORODATAGE ET NON « IL Y A 4 MIN » ───────────────────────────────
     * Cette page reste allumée des heures sur le comptoir. Une phrase « il y a 4 min » calculée au
     * rendu deviendrait fausse à la minute suivante et resterait fausse toute la journée. On rend
     * donc l'instant, et la page recalcule l'écart en continu.
     *
     * @return array<int, array{lot: string, prenom: string, instant: int}>
     */
    public function derniersGagnants(int $branchId, int $limite = 6, int $heures = 48): array
    {
        $depuis = Carbon::now()->subHours(max(1, $heures));

        $tours = WheelSpin::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)
            ->where('created_at', '>=', $depuis)
            // Un tour sans lot nommé n'a rien à raconter.
            ->whereNotNull('prize_label')
            ->where('prize_label', '!=', '')
            ->orderByDesc('created_at')
            ->limit(max(1, $limite))
            ->get(['prize_label', 'customer_name', 'created_at']);

        return $tours->map(function ($t) {
            // Le PRÉNOM seul : on coupe au premier espace. « Marie Dupont » affiché en salle sur un
            // écran que tout le monde voit, c'est une donnée personnelle exposée sans raison.
            $nom = trim((string) ($t->customer_name ?? ''));
            $prenom = $nom !== '' ? trim(explode(' ', $nom)[0]) : '';

            // Un « prénom » de 1 caractère ou purement numérique n'en est pas un (saisies de
            // comptoir, numéros collés dans le champ). On préfère l'anonyme au ridicule.
            if (mb_strlen($prenom) < 2 || preg_match('/^\d+$/', $prenom)) {
                $prenom = '';
            }

            return [
                'lot' => (string) $t->prize_label,
                'prenom' => $prenom,
                'instant' => (int) $t->created_at->getTimestamp(),
            ];
        })->all();
    }

    /**
     * L'HISTORIQUE, LIGNE PAR LIGNE — ce qui manquait pour EXPLIQUER.
     *
     * [2026-08-13 · propriétaire : « toutes les fonctionnalités d'historique, de la gestion, de la
     * validation, de l'utilisation — par exemple quel code promo a été validé »]
     *
     * ── CE QUI EXISTAIT, ET CE QUI MANQUAIT ──────────────────────────────────────────────────
     * `tableau()` donne des AGRÉGATS : combien de tours, combien de cadeaux, quelle valeur. C'est
     * ce qu'il faut pour régler des plafonds. Ça ne répond à AUCUNE des questions qu'on se pose
     * réellement devant un client ou un livre de comptes : « ce code, il a été validé ? », « qui
     * l'a remis ? », « pourquoi celui-là n'a jamais rien reçu ? ».
     *
     * Un chiffre agrégé ne se conteste pas et ne s'explique pas. Une ligne, si.
     *
     * ── L'ÉTAT EST CALCULÉ ICI, UNE FOIS ─────────────────────────────────────────────────────
     * Quatre états, et ils sont exclusifs :
     *   · `remis`    — un humain a appuyé sur « remis », `delivered_at` en fait foi ;
     *   · `du`       — gagné, jamais remis, encore dans sa durée de validité ;
     *   · `expire`   — gagné, jamais remis, hors délai : le client n'y a plus droit et l'équipe
     *                  doit pouvoir le lui dire sans hésiter ;
     *   · `code`     — un lot en remise : rien à tendre, le code fait le travail sur le site.
     *
     * Les calculer dans le gabarit aurait dispersé la règle dans du HTML, où personne ne la relit.
     *
     * ── CE QU'ON N'AFFICHE PAS ───────────────────────────────────────────────────────────────
     * Le numéro complet ne sort pas d'ici : cet écran s'ouvre avec le code de la maison, sur une
     * tablette de comptoir que d'autres regardent. On rend les quatre derniers chiffres, qui
     * suffisent à confirmer une identité déjà annoncée par le client, et jamais à en constituer
     * une liste.
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * LES PARCOURS COMMENCÉS ET JAMAIS TERMINÉS, avec l'étape exacte où la personne s'est arrêtée.
     *
     * [PROPRIÉTAIRE 2026-08-13] « voir la liste des clients qui ont joué et qui n'ont pas complété,
     * et à quelle étape ».
     *
     * ── POURQUOI CETTE LISTE VAUT PLUS QUE CELLE DES GAGNANTS ────────────────────────────────
     * Les gagnants, on les voit déjà : ils viennent chercher leur lot. Ceux qui abandonnent ne
     * laissent aucune trace visible, et ce sont EUX qui disent si le parcours coince — un écran qui
     * perd tout le monde à l'abonnement ne se voit nulle part ailleurs que dans ce tableau.
     *
     * ── CE QU'ON PEUT DIRE, ET CE QU'ON NE PEUT PAS ──────────────────────────────────────────
     * `wheel_step_progress` suit un JETON, pas une personne : tant que le tour n'est pas réclamé,
     * on n'a ni nom ni téléphone — c'est voulu, on ne demande l'identité qu'à la fin. Cette liste
     * est donc anonyme par construction, et elle le RESTE : elle sert à mesurer où ça bloque, pas
     * à rappeler quelqu'un. Ne pas essayer d'y raccrocher une identité, il n'y en a pas.
     *
     * Un parcours est « terminé » quand un `wheel_spins` porte le même jeton — c'est-à-dire quand
     * la personne a donné ses coordonnées et reçu son lot.
     *
     * @return array<int, array<string, mixed>>
     */
    public function parcoursIncomplets(int $branchId, int $jours = 7, int $limite = 100): array
    {
        $depuis = Carbon::now()->subDays(max(1, $jours));

        $lignes = DB::table('wheel_step_progress as p')
            ->leftJoin('wheel_spins as s', 's.unlock_token_hash', '=', 'p.unlock_token_hash')
            ->where('p.branch_id', $branchId)
            ->where('p.created_at', '>=', $depuis)
            ->whereNull('s.id')
            ->orderByDesc('p.created_at')
            ->limit(max(1, $limite))
            ->get([
                'p.created_at', 'p.updated_at', 'p.review_opened_at',
                'p.follow_opened_at', 'p.spun_at', 'p.prize_label',
            ]);

        return $lignes->map(function ($p) {
            /*
             * L'ÉTAPE ATTEINTE se lit à l'envers — de la plus avancée à la plus timide. Lire dans
             * l'autre sens dirait « a ouvert l'avis » de quelqu'un qui a déjà fait tourner la roue.
             */
            if ($p->spun_at !== null) {
                $etape = 'a gagné, mais n\'a pas donné ses coordonnées';
                $rang  = 3;
            } elseif ($p->follow_opened_at !== null) {
                $etape = 'est parti s\'abonner et n\'est pas revenu';
                $rang  = 2;
            } elseif ($p->review_opened_at !== null) {
                $etape = 'est parti laisser un avis et n\'est pas revenu';
                $rang  = 1;
            } else {
                $etape = 'a scanné, puis s\'est arrêté tout de suite';
                $rang  = 0;
            }

            return [
                'quand'   => $p->created_at,
                'dernier' => $p->updated_at,
                'etape'   => $etape,
                'rang'    => $rang,
                'lot'     => $p->prize_label,
            ];
        })->all();
    }

    public function historique(int $branchId, int $jours = 30, int $limite = 200): array
    {
        $depuis = Carbon::now()->subDays(max(1, $jours));
        $validite = (int) config('wheel.prize_validity_days', 30);

        $tours = WheelSpin::query()
            ->withoutGlobalScope(BranchScope::class)
            ->with('coupon')
            ->where('branch_id', $branchId)
            ->where('created_at', '>=', $depuis)
            ->orderByDesc('created_at')
            ->limit(max(1, $limite))
            ->get();

        // Les noms des personnes qui ont remis, en UNE requête : sans ça l'écran en réclamait une
        // par ligne, et un historique de 200 lignes rendait 200 requêtes pendant un service.
        $auteurs = \App\Models\User::query()
            ->whereIn('id', $tours->pluck('delivered_by_user_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        return $tours->map(function ($t) use ($validite, $auteurs) {
            $estRemise = str_starts_with((string) $t->prize_type, 'coupon_');
            $limite = $t->created_at?->copy()->addDays($validite);

            if ($t->delivered_at !== null) {
                $etat = 'remis';
            } elseif ($estRemise) {
                $etat = 'code';
            } elseif ($limite !== null && $limite->isPast()) {
                $etat = 'expire';
            } else {
                $etat = 'du';
            }

            $tel = (string) $t->phone;

            return [
                'quand' => $t->created_at,
                'lot' => (string) $t->prize_label,
                'type' => (string) $t->prize_type,
                'etat' => $etat,
                // Quatre chiffres : de quoi confirmer une identité annoncée, jamais de quoi
                // constituer un fichier depuis un écran de comptoir.
                'tel_fin' => $tel !== '' ? substr($tel, -4) : '',
                'prenom' => trim(explode(' ', trim((string) $t->customer_name))[0] ?? ''),
                'code' => trim((string) ($t->coupon->code ?? '')),
                'remis_le' => $t->delivered_at,
                'remis_par' => $auteurs[$t->delivered_by_user_id] ?? null,
                // Le cadeau a-t-il bougé le stock ? C'est la question comptable, et elle a déjà eu
                // sa réponse fausse une fois (10/08 : « cadeau remis, stock inchangé »).
                'stock_bouge' => $t->cost_outflow_id !== null,
                'expire_le' => $limite,
            ];
        })->all();
    }

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

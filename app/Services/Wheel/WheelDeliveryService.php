<?php

namespace App\Services\Wheel;

use App\Enums\Ask;
use App\Models\Scopes\BranchScope;
use App\Models\StockOutflow;
use App\Models\User;
use App\Models\WheelSpin;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * LA REMISE DU LOT — le maillon qui manquait, et sans lequel tout le reste était du théâtre.
 *
 * Trois audits adversaires indépendants ont convergé : la roue TIRAIT correctement mais ne LIVRAIT
 * rien. Les points étaient écrits sur une ligne que personne ne lisait ; les produits offerts
 * passaient par un coupon à 0,00 € qui brûlait son usage unique, si bien que le client payait plein
 * tarif ET que la comptabilité enregistrait le coût d'un cadeau jamais donné.
 *
 * ── LA DÉCISION DE CONCEPTION ────────────────────────────────────────────────────────────────
 * Un produit offert ne peut PAS être un coupon. Un coupon retire de l'argent d'un total ; « une
 * boisson offerte » n'est pas une remise, c'est un objet qu'on tend. Et le moteur de remises est
 * de toute façon désactivé sur le chemin caisse en V1.
 *
 * La remise devient donc un GESTE TRACÉ, exactement comme la validation du tour : l'équipe saisit
 * le numéro, voit le lot, appuie sur « remis ». C'est cohérent avec le reste du jeu — dont tout le
 * modèle repose déjà sur un humain au comptoir — et c'est le seul mécanisme qui ne peut pas mentir.
 *
 * ── CE QUI EST LIVRÉ, ET COMMENT ─────────────────────────────────────────────────────────────
 *   · `free_item` → l'équipe tend le produit. On inscrit la CHARGE au moment de la remise, ce qui
 *     est plus juste que de l'inscrire à la consommation d'un coupon : ici on sait que le cadeau a
 *     réellement été donné, parce qu'un humain vient de le dire.
 *   · `points`   → crédit RÉEL sur le compte du client, quand un compte porte ce numéro. Sinon on
 *     conserve le lot : les points seront créditables le jour où la personne crée son compte. On ne
 *     lui promet donc jamais un solde qui ne bougera pas.
 *   · `coupon_*` → rien à remettre : le code fait le travail sur le site. Ces lots ne passent pas
 *     par ici.
 *
 * ── LA GARDE QUI COMPTE ──────────────────────────────────────────────────────────────────────
 * `delivered_at` est la seule marque qui vaille. Sans elle, un client réclamerait son lot à chaque
 * service et l'équipe n'aurait aucun moyen de savoir qu'il l'a déjà eu. La double remise est donc
 * refusée en base, sous verrou, dans la transaction — pas par un `if` que deux caisses simultanées
 * franchiraient toutes les deux.
 */
class WheelDeliveryService
{
    /**
     * Le stock a-t-il RÉELLEMENT bougé lors de la dernière remise ? Un produit composite (un menu)
     * n'a pas de stock direct : la charge est tracée, le stock non. L'équipe doit le savoir, sinon
     * elle croit que l'inventaire est à jour alors qu'il ne l'est pas.
     */
    private ?bool $stockDecremente = null;

    /**
     * Le lot en attente pour ce numéro, s'il y en a un. Rend NUL si aucun tour, ou si le lot a déjà
     * été remis, ou s'il s'agit d'un lot en pourcentage (rien à tendre : le code fait le travail).
     */
    public function pending(int $branchId, string $phone): ?WheelSpin
    {
        $tel = app(WheelService::class)->normalizePhone($phone);
        if (strlen($tel) < 9) {
            return null;
        }

        return WheelSpin::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)
            ->where('phone', $tel)
            ->whereNull('delivered_at')
            ->whereIn('prize_type', ['free_item', 'points'])
            ->orderByDesc('id')
            ->first();
    }

    /** Tous les tours de ce numéro, remis ou non — pour que l'équipe puisse expliquer. */
    public function history(int $branchId, string $phone)
    {
        $tel = app(WheelService::class)->normalizePhone($phone);
        if (strlen($tel) < 9) {
            return collect();
        }

        return WheelSpin::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)
            ->where('phone', $tel)
            ->orderByDesc('id')
            ->limit(5)
            ->get();
    }

    /**
     * Remet le lot. Idempotent et sûr sous concurrence : la relecture sous verrou dans la
     * transaction refuse la seconde remise, quelle que soit la simultanéité.
     *
     * @return array{ok: bool, message: string, points_credited: bool}
     */
    public function deliver(int $spinId, ?int $staffUserId, ?int $branchId = null, ?string $phone = null): array
    {
        return DB::transaction(function () use ($spinId, $staffUserId, $branchId, $phone) {
            $spin = WheelSpin::query()
                ->withoutGlobalScope(BranchScope::class)
                ->whereKey($spinId)
                ->lockForUpdate()
                ->first();

            if (! $spin) {
                return ['ok' => false, 'message' => 'Ce lot n\'existe pas.', 'points_credited' => false];
            }

            /*
             * [P2 2026-08-10 · audit E2E vague C] `spin_id` ARRIVE D'UN CHAMP CACHÉ.
             *
             * Rien ne vérifiait que le lot appartenait bien au comptoir qui le remet. En V1 LOCAL il
             * n'y a qu'une caisse, donc le risque est théorique aujourd'hui — mais c'est exactement le
             * genre de garde qu'on n'ajoute plus jamais après, et le jour où il y a deux comptoirs, une
             * caisse remet les lots de l'autre. La branche vient du contexte résolu par la porte,
             * jamais du corps de la requête.
             */
            /*
             * [P1 2026-08-10 · audit ronde 2] `spin_id` N'ÉTAIT RATTACHÉ À RIEN.
             *
             * Il arrive d'un champ caché du formulaire. Prouvé par HTTP : l'écran affiche le client A,
             * le lot de B est consommé et SON stock décrémenté. L'équipe croit avoir servi la personne
             * en face d'elle ; le vrai titulaire reviendra s'entendre dire « déjà remis ».
             *
             * Le numéro affiché est la seule autre donnée qui identifie le titulaire : on l'exige.
             */
            if ($phone !== null && $phone !== '') {
                $attendu = app(WheelService::class)->normalizePhone($phone);
                if ($attendu !== '' && $attendu !== (string) $spin->phone) {
                    return [
                        'ok' => false,
                        'message' => 'Ce lot n\'est pas celui du numéro affiché. Recherche le numéro à '
                            . 'nouveau, puis remets le lot depuis cet écran.',
                        'points_credited' => false,
                    ];
                }
            }

            if ($branchId !== null && (int) $spin->branch_id !== $branchId) {
                return [
                    'ok' => false,
                    'message' => 'Ce lot a été gagné dans un autre point de vente.',
                    'points_credited' => false,
                ];
            }

            /*
             * [P1 2026-08-10] LE DÉLAI ÉTAIT AFFICHÉ, PAS APPLIQUÉ.
             *
             * L'écran annonce au client « à utiliser avant le … », l'e-mail le répète — et un lot de
             * six mois se remettait encore en un appui. Une échéance qu'on écrit trois fois et qu'on
             * n'applique jamais n'est pas une échéance : c'est une décoration, et c'est la maison qui
             * paie la différence.
             *
             * Le refus NOMME la date, pour que l'équipe puisse expliquer plutôt que d'avoir l'air de
             * subir une panne. Et rien n'est marqué remis : le lot est simplement périmé.
             */
            $jours = (int) config('wheel.prize_validity_days', 30);
            if ($jours > 0 && $spin->created_at !== null) {
                $limite = $spin->created_at->copy()->addDays($jours)->endOfDay();
                if ($limite->isPast()) {
                    return [
                        'ok' => false,
                        'message' => 'Ce lot a expiré le ' . $limite->format('d/m/Y')
                            . ' (gagné le ' . $spin->created_at->format('d/m/Y') . ').',
                        'points_credited' => false,
                    ];
                }
            }

            if ($spin->delivered_at !== null) {
                // Le message NOMME la date : l'équipe peut ainsi expliquer au client, plutôt que de
                // se contenter d'un refus qui ressemble à une panne.
                return [
                    'ok' => false,
                    'message' => 'Ce lot a déjà été remis le ' . $spin->delivered_at->format('d/m/Y à H:i') . '.',
                    'points_credited' => false,
                ];
            }

            if (! in_array((string) $spin->prize_type, ['free_item', 'points'], true)) {
                return [
                    'ok' => false,
                    'message' => 'Ce lot est une remise : le client l\'utilise avec son code sur le site.',
                    'points_credited' => false,
                ];
            }

            $pointsCredites = false;

            if ((string) $spin->prize_type === 'points') {
                $pointsCredites = $this->creditPoints($spin);

                /*
                 * [P0 2026-08-10 — audit E2E vague C] LES POINTS ÉTAIENT DÉTRUITS.
                 *
                 * `delivered_at` était posé quel que soit le résultat. Quand aucun compte ne portait
                 * le numéro, l'écran affichait « points en attente : dis-lui de créer son compte
                 * avec CE numéro, les points y seront ajoutés » — et la marque de remise fermait
                 * définitivement la porte. Le client revenait avec son compte créé, l'équipe
                 * cherchait son numéro, et lisait « rien à remettre : ses lots sont déjà remis ».
                 *
                 * Un lot dont on promet la suite ne peut pas être marqué REMIS. Rien n'a été remis :
                 * on refuse, on CONSERVE le lot, et on dit quoi faire. C'est la seule version qui
                 * tient la promesse écrite juste au-dessus.
                 */
                if (! $pointsCredites) {
                    return [
                        'ok' => false,
                        'message' => 'Aucun compte à ce numéro : ses ' . (int) $spin->points_awarded
                            . ' points sont CONSERVÉS. Dis-lui de créer son compte avec CE numéro '
                            . '(ou de commander une fois sur le site), puis reviens ici : les points '
                            . 'seront ajoutés.',
                        'points_credited' => false,
                    ];
                }
            } else {
                $this->recordCost($spin, $staffUserId);
            }

            $spin->delivered_at = now();
            $spin->delivered_by_user_id = $staffUserId;
            $spin->save();

            Log::channel('daily')->info('wheel.prize_delivered', [
                'spin_id' => $spin->id, 'prize' => $spin->prize_key,
                'by' => $staffUserId, 'points_credited' => $pointsCredites,
            ]);

            return [
                'ok' => true,
                'message' => $this->messageSucces($spin, $pointsCredites),
                'points_credited' => $pointsCredites,
            ];
        });
    }

    /**
     * Crédit RÉEL des points. On emprunte le chemin canonique du programme de fidélité
     * (`users.loyalty_points`), celui qu'utilise déjà l'attribution à la livraison — pas une
     * seconde comptabilité parallèle qui finirait par divergerdu solde affiché au client.
     *
     * Aucun compte pour ce numéro ? On ne crédite rien et on le DIT. Inventer un compte à partir
     * d'un numéro créerait des comptes fantômes sans consentement ; promettre des points qui
     * n'arriveront jamais est un mensonge. La troisième voie est la bonne : garder le lot, et
     * l'expliquer.
     */
    private function creditPoints(WheelSpin $spin): bool
    {
        $points = (int) ($spin->points_awarded ?? 0);
        if ($points <= 0) {
            return false;
        }

        /*
         * [P1 2026-08-10 · audit ronde 2] TROIS DÉFAUTS EN QUATRE LIGNES.
         *
         *   · une SEULE écriture du numéro était cherchée, alors que le service qui crée le compte en
         *     cherche quatre, trente lignes plus loin. 62 comptes sur 348 portent une forme non
         *     normalisée : pour eux, le compte existait et le comptoir lisait « aucun compte à ce
         *     numéro, crée-le puis reviens » — une consigne impossible à exécuter ;
         *   · `withoutGlobalScopes()` retire AUSSI le filtre de suppression douce : les points
         *     partaient sur un compte SUPPRIMÉ, le lot était clos, et le client vivant n'avait rien ;
         *   · aucun filtre sur `is_guest` : un numéro rattaché à un compte de l'ÉQUIPE recevait les
         *     points d'un client. Un numéro n'est pas une preuve d'identité.
         *
         * On aligne donc la sélection sur celle du service qui CRÉE le compte : même définition du
         * numéro, mêmes exclusions. Deux façons de désigner la même personne, c'est un jour où l'une
         * des deux se trompe.
         */
        $roue = app(WheelService::class);

        // Plusieurs comptes peuvent porter des écritures différentes du même numéro : on prend le
        // premier qui est bien celui d'un CLIENT. Écarter sur `is_guest` seul priverait les clients
        // réellement inscrits (13 en base) de leurs points.
        $user = User::query()
            ->withoutGlobalScope(BranchScope::class)
            ->whereIn('phone', $roue->phoneVariants((string) $spin->phone))
            ->orderBy('id')
            ->get()
            ->first(fn ($u) => $roue->isCustomerAccount($u));

        if (! $user) {
            return false;
        }

        /*
         * [2026-08-13] LE GRAND-LIVRE PORTE MAINTENANT LE CADEAU. C'était le SEUL mouvement de solde
         * de toute l'application qui n'écrivait rien dans `loyalty_transactions` : mesuré, zéro
         * occurrence de la table ET du modèle dans ce fichier, alors que les six autres chemins
         * (gain sur commande, débit caisse, débit site/borne, ajout par l'équipe, remboursement,
         * reprise) en écrivent tous.
         *
         * POURQUOI CE TROU S'EST MIS À COÛTER. L'écran de fidélité du comptoir affiche désormais
         * l'HISTORIQUE des points, lu dans ce grand-livre. Un client qui gagnait 50 points à la roue
         * voyait son solde monter sans qu'aucune ligne l'explique — et le caissier à qui il demandait
         * « d'où viennent ces points ? » n'avait rien à montrer. C'est précisément le « solde sans
         * histoire » que cet écran a été construit pour supprimer.
         *
         * L'ÉCRITURE VIT DANS LA MÊME TRANSACTION QUE L'INCRÉMENT. Séparées, un incident entre les
         * deux laisse soit un solde sans sa ligne (le client a ses points, l'histoire est fausse),
         * soit une ligne sans son solde — et ça, c'est un grand-livre qui MENT. Une seule transaction :
         * les deux vivent ou aucune.
         *
         * `type = earn` parce que la colonne est un ENUM à cinq valeurs
         * (`earn, redeem, manual_add, manual_deduct, expire`) et qu'un cadeau est un GAIN ; inventer
         * une sixième valeur exigerait une migration sur une table à vocation comptable. La
         * provenance est donc portée par `source_surface = wheel` et par une description qui NOMME
         * la roue — sans quoi l'historique du comptoir afficherait « Gagné sur une commande » pour un
         * cadeau qui n'a aucune commande.
         *
         * `order_id` reste NUL : un cadeau de roue n'est rattaché à aucune vente.
         *
         * Sentinelle : tests/Feature/Wheel/WheelPointsDeliveryTest.php
         */
        DB::transaction(function () use ($user, $points, $spin) {
            User::withoutGlobalScope(BranchScope::class)->whereKey($user->id)
                ->increment('loyalty_points', $points);

            $soldeApres = (int) DB::table('users')->where('id', $user->id)->value('loyalty_points');

            DB::table('loyalty_transactions')->insert([
                'user_id'        => $user->id,
                'loyalty_code'   => $user->loyalty_code,
                'order_id'       => null,
                'type'           => 'earn',
                'points'         => $points,
                'balance_after'  => $soldeApres,
                'source_surface' => 'wheel',
                'description'    => 'Roue — ' . ($spin->prize_label ?: 'cadeau') . ' (tour #' . $spin->id . ')',
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        });

        $spin->points_credited_user_id = $user->id;

        return true;
    }

    /**
     * La charge du produit offert, inscrite AU MOMENT DE LA REMISE. C'est plus juste que de
     * l'inscrire à la consommation d'un coupon : ici, un humain vient de confirmer que le cadeau a
     * réellement été donné.
     */
    private function recordCost(WheelSpin $spin, ?int $staffUserId): void
    {
        if (! (bool) config('wheel.record_cost_on_claim', true) || $spin->cost_outflow_id !== null) {
            return;
        }

        $itemId = $this->costItemId((string) $spin->prize_key);
        if ($itemId === null) {
            // Pas de produit de référence configuré : on remet quand même le cadeau — refuser de
            // servir un client parce qu'un réglage manque serait absurde — mais la charge n'est pas
            // chiffrée, et la commande de réconciliation le signale.
            return;
        }

        /*
         * [P0 2026-08-10 — « relis avec notre système de gestion et de stock depuis la caisse »]
         * LE STOCK N'ÉTAIT PAS DÉCRÉMENTÉ. On écrivait `stock_decremented => false` en dur.
         *
         * Prouvé en base : cadeau remis, ligne de charge écrite, `stock_levels.on_hand` INCHANGÉ,
         * ZÉRO mouvement de stock. Autrement dit, chaque boisson offerte laissait le stock théorique
         * croire qu'elle était encore sur l'étagère. Sur une semaine, c'est la rupture (86), la borne,
         * le site et l'inventaire qui dérivent — précisément le système que la caisse pilote.
         *
         * Le chemin « repas / pertes » de la caisse, lui, appelle bien le service de stock. Il n'y a
         * aucune raison que le cadeau de la roue en soit dispensé : on emprunte EXACTEMENT le même
         * chemin, avec le même motif canonique et une clé d'idempotence dérivée du tour.
         *
         * Le `false` en dur venait d'un raisonnement valable AILLEURS : sur le chemin historique
         * (`WheelClaimService`), l'article servi n'est pas identifié, donc on ne peut rien
         * décrémenter. Ici il EST identifié, et un humain vient de confirmer la remise. La
         * justification ne se transportait pas — c'est ce genre de copie qui fait les trous.
         */
        $decremente = app(StockService::class)->recordManualOutflow(
            $itemId,
            (int) $spin->branch_id,
            1,
            // Motif canonique de l'énumération `stock_movements.reason` : la distinction métier
            // (repas / perte / cadeau) vit dans `stock_outflows.type`, pas ici.
            'manual_out',
            // Aucun compte quand la porte a été ouverte par le code de la maison. La véritable trace
            // d'attribution reste `stock_outflows.user_id`, laissée nulle plutôt que faussée.
            (int) ($staffUserId ?? 0),
            'wheel-gift-' . $spin->id,
        );

        $sortie = StockOutflow::create([
            'branch_id' => (int) $spin->branch_id,
            'item_id'   => $itemId,
            'item_name' => $spin->prize_label,
            'quantity'  => 1,
            'type'      => StockOutflow::TYPE_PROMO_GIFT,
            // [P1 2026-08-10] Une sortie de stock SANS AUTEUR est une sortie que personne n'assume —
            // et le chemin de la caisse, lui, en exige toujours un. Sur le chemin du code de la maison
            // il n'y a pas de compte : on écrit au moins COMMENT la porte a été ouverte, pour qu'un
            // inventaire puisse distinguer « untel a remis » de « quelqu'un avec le code a remis ».
            'note'      => 'Roue — remis au comptoir — tour #' . $spin->id
                . ($staffUserId === null ? ' — ouvert par le code de la maison' : ''),
            'user_id'   => $staffUserId,
            // La VALEUR RÉELLE, jamais une constante : `false` alors que le stock a bougé (ou
            // l'inverse) rend la ligne inexploitable pour l'inventaire.
            'stock_decremented' => $decremente,
            'created_at' => now(),
        ]);

        $spin->cost_outflow_id = $sortie->id;
        $this->stockDecremente = $decremente;
    }

    /**
     * Produit de RÉFÉRENCE pour chiffrer le cadeau.
     *
     * Deux niveaux, dans cet ordre :
     *   1. l'identifiant réglé par l'exploitant (`cost_item_id`) — c'est sa décision, elle primera
     *      toujours ;
     *   2. sinon, une recherche par NOM dans la carte (`cost_item_name`). Ce repli existe parce
     *      qu'un trou comptable ne doit pas dépendre d'une variable d'environnement que quelqu'un a
     *      pensé à poser. Un produit renommé fait échouer la recherche — et c'est mieux ainsi : on
     *      préfère un cadeau non chiffré SIGNALÉ à un cadeau chiffré sur le mauvais produit, qui
     *      ferait dériver l'inventaire de celui-là en silence.
     */
    public function costItemId(string $prizeKey): ?int
    {
        foreach (app(\App\Services\Wheel\WheelService::class)->segments() as $s) {
            if ((string) ($s['key'] ?? '') !== $prizeKey) {
                continue;
            }

            $id = (int) ($s['cost_item_id'] ?? 0);
            if ($id > 0) {
                return $id;
            }

            $nom = trim((string) ($s['cost_item_name'] ?? ''));
            if ($nom === '') {
                return null;
            }

            $trouve = \App\Models\Item::query()
                // Singulier : un produit RETIRÉ de la carte ne doit pas servir de référence de coût.
                ->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                ->where('name', $nom)
                ->orderBy('id')
                ->value('id');

            return $trouve ? (int) $trouve : null;
        }

        return null;
    }

    private function messageSucces(WheelSpin $spin, bool $pointsCredites): string
    {
        // Un lot en points n'arrive ici QUE si le crédit a réellement eu lieu : le cas « aucun
        // compte » est refusé plus haut, sans marquer la remise (sinon les points mouraient).
        if ((string) $spin->prize_type === 'points') {
            return $spin->points_awarded . ' points crédités sur son compte.';
        }

        $message = 'Remis : ' . $spin->prize_label . '. Bon service !';

        // Un produit composite (un menu) n'a pas de stock direct : la charge est tracée, le stock
        // non. Le taire laisserait croire que l'inventaire suit.
        if ($this->stockDecremente === false) {
            $message .= ' (stock non décrémenté — produit sans stock direct : à corriger à '
                . 'l\'inventaire si besoin.)';
        }

        /*
         * [AUDIT-5SYS 2026-08-12 P1 — « des lots cuisine sans aucun signal cuisine »] Depuis que la
         * roue distribue de vrais plats préparés (Cheese Burger, Cayenne, Terminator — config du
         * 2026-08-12), rien ne disait à l'équipe qu'un cadeau REMIS devait aussi être PRÉPARÉ.
         *
         * Créer une fausse commande interne (Order/OrderItem/carte KDS) pour porter ce signal a été
         * délibérément écarté : ça contredirait la décision de conception documentée en tête de ce
         * fichier (« un produit offert n'est pas une commande »), ET ça décrémenterait le stock une
         * SECONDE fois (recordCost() le fait déjà juste au-dessus, par le chemin canonique caisse).
         *
         * Le fix emprunte donc le SEUL canal qui existe déjà et qui atteint la bonne personne au bon
         * moment : le message affiché à l'écran comptoir au moment précis du "remis"
         * (WheelPrizeController::deliver → vue admin.wheel.lot). C'est exactement le même geste tracé
         * que le reste de ce fichier documente — un humain regarde l'écran, agit en conséquence.
         */
        if ($this->requiertPreparationCuisine($spin)) {
            $message .= ' ⚠️ À PRÉPARER EN CUISINE — préviens l\'équipe cuisine MAINTENANT.';
        }

        return $message;
    }

    /** Le lot remis est-il un plat préparé, marqué `kitchen_prep` dans sa configuration ? */
    private function requiertPreparationCuisine(WheelSpin $spin): bool
    {
        foreach (app(WheelService::class)->segments() as $s) {
            if ((string) ($s['key'] ?? '') === (string) $spin->prize_key) {
                return (bool) ($s['kitchen_prep'] ?? false);
            }
        }

        return false;
    }
}

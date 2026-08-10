<?php

namespace App\Services\Wheel;

use App\Models\Scopes\BranchScope;
use App\Models\StockOutflow;
use App\Models\User;
use App\Models\WheelSpin;
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
    public function deliver(int $spinId, ?int $staffUserId, ?int $branchId = null): array
    {
        return DB::transaction(function () use ($spinId, $staffUserId, $branchId) {
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

        $user = User::query()
            ->withoutGlobalScopes()
            ->where('phone', $spin->phone)
            ->orderBy('id')
            ->first();

        if (! $user) {
            return false;
        }

        User::withoutGlobalScopes()->whereKey($user->id)->increment('loyalty_points', $points);
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

        $sortie = StockOutflow::create([
            'branch_id' => (int) $spin->branch_id,
            'item_id'   => $itemId,
            'item_name' => $spin->prize_label,
            'quantity'  => 1,
            'type'      => StockOutflow::TYPE_PROMO_GIFT,
            'note'      => 'Roue — remis au comptoir — tour #' . $spin->id,
            'user_id'   => $staffUserId,
            'stock_decremented' => false,
            'created_at' => now(),
        ]);

        $spin->cost_outflow_id = $sortie->id;
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
    private function costItemId(string $prizeKey): ?int
    {
        foreach ((array) config('wheel.segments', []) as $s) {
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
                ->withoutGlobalScopes()
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

        return 'Remis : ' . $spin->prize_label . '. Bon service !';
    }
}

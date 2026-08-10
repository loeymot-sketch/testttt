<?php

namespace App\Services\Wheel;

use App\Enums\DiscountType;
use App\Enums\Status;
use App\Models\Coupon;
use App\Models\Scopes\BranchScope;
use App\Models\WheelSpin;
use App\Models\WheelStepProgress;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Roue Le Cayenne — le tirage, et les gardes qui l'empêchent d'être un robinet à cadeaux.
 *
 * ── LA RÈGLE QUI COMMANDE TOUT LE RESTE ──────────────────────────────────────────────────────
 * Le NAVIGATEUR NE DÉCIDE RIEN. Il demande un tour, le serveur tire, écrit le résultat en base,
 * puis le renvoie. L'animation ne fait qu'AFFICHER un verdict déjà scellé. Une roue dont le
 * navigateur choisit le segment se gagne avec les outils de développement en dix secondes — c'est
 * l'erreur classique de ce genre d'objet, et elle coûte un service entier le jour où quelqu'un la
 * trouve.
 *
 * Corollaires appliqués ici :
 *   · les POIDS ne sortent jamais du serveur (voir `publicSegments()`) ;
 *   · aucune valeur de lot ne vient de la requête — tout est lu dans `config/wheel.php` ;
 *   · le tirage utilise `random_int` (générateur cryptographique), pas `rand()`/`mt_rand()`, dont
 *     la suite est prédictible à partir de quelques résultats observés.
 *
 * ── UN SEUL TOUR PAR PERSONNE ────────────────────────────────────────────────────────────────
 * La garde vit dans une contrainte d'UNICITÉ en base, pas dans un `if`. Deux requêtes simultanées
 * passent un `if` toutes les deux ; elles ne passent pas une contrainte d'unicité. On tente
 * l'insertion et on rattrape la violation — c'est la seule façon correcte sous concurrence.
 *
 * ── LES PLAFONDS ─────────────────────────────────────────────────────────────────────────────
 * `max_uses_global` du moteur de coupons protège UN CODE, pas un BUDGET. Deux plafonds distincts
 * sont donc appliqués au tirage : par lot et par jour (`daily_cap`), et un plafond global
 * journalier. Un lot au plafond voit son poids tomber à zéro pour ce tirage : la roue continue de
 * tourner, elle cesse simplement de donner ce lot-là.
 */
class WheelService
{
    /**
     * Ce que le navigateur a le droit de connaître : de quoi DESSINER la roue, et rien de plus.
     * Ni poids, ni plafonds — les publier reviendrait à publier la probabilité de chaque case, et
     * surtout à laisser croire qu'ils sont négociables côté client.
     *
     * @return array<int, array{key: string, label: string}>
     */
    public function publicSegments(): array
    {
        return array_values(array_map(
            fn ($s) => ['key' => (string) $s['key'], 'label' => (string) $s['label']],
            (array) config('wheel.segments', [])
        ));
    }

    /** Chiffres seuls : « 06 12 34 56 78 », « +33612345678 » et « 0612345678 » sont UNE personne. */
    public function normalizePhone(string $phone): string
    {
        $d = preg_replace('/\D+/', '', $phone) ?? '';
        // Forme nationale française : +33 6 … → 06 …
        if (strlen($d) === 11 && str_starts_with($d, '33')) {
            $d = '0' . substr($d, 2);
        }

        return $d;
    }

    public function isOpenToPublic(): bool
    {
        return (bool) config('wheel.enabled', false);
    }

    /**
     * A-t-on déjà joué ? Question posée AVANT le tour pour afficher un message honnête plutôt que
     * de laisser le client remplir un formulaire pour rien.
     */
    public function alreadySpun(int $branchId, string $phone): ?WheelSpin
    {
        return WheelSpin::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)
            ->where('campaign_key', (string) config('wheel.campaign_key'))
            ->where('phone', $this->normalizePhone($phone))
            ->first();
    }

    /** Cette adresse a-t-elle déjà joué ? Seconde clé, indépendante du téléphone. */
    public function alreadySpunByEmail(int $branchId, string $email): ?WheelSpin
    {
        $mail = mb_strtolower(trim($email));
        if ($mail === '') {
            return null;
        }

        return WheelSpin::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)
            ->where('campaign_key', (string) config('wheel.campaign_key'))
            ->where('email', $mail)
            ->first();
    }

    /**
     * LE TIRAGE SEUL, SANS IDENTITÉ — première moitié du parcours.
     *
     * [2026-08-10] Le propriétaire a arbitré : le client TOURNE d'abord, et ne donne ses
     * coordonnées qu'ensuite pour débloquer son code. Demander deux champs AVANT le tour, c'est
     * demander un effort contre une promesse ; les demander APRÈS, c'est les demander contre un lot
     * déjà visible. L'écart d'acceptation est énorme.
     *
     * Conséquence : au moment du tirage on ne sait pas QUI joue. Le lot est donc mis EN ATTENTE sur
     * la progression (clé = empreinte du jeton de la tablette, déjà à usage unique) et ne devient une
     * participation — ligne, coupon, points — qu'à la RÉCLAMATION, quand l'identité est connue et son
     * unicité vérifiée en base.
     *
     * Un lot non réclamé n'existe donc pas : aucun coupon émis, aucune charge, rien à nettoyer.
     * Celui qui tourne et s'en va n'a rien coûté.
     *
     * @return array{key: string, label: string, type: string, value: float}
     *
     * @throws WheelException
     */
    public function drawPending(int $branchId, WheelStepProgress $progress): array
    {
        if ($this->dailyTotal($branchId) >= (int) config('wheel.daily_total_cap', 120)) {
            throw new WheelException('La roue a distribué tous ses lots pour aujourd\'hui. Reviens demain !', 429);
        }

        // Déjà tourné avec CE jeton : on rend le MÊME lot. Sans ça, recharger la page pendant
        // l'animation ferait re-tirer — donc « re-tourner jusqu'à gagner », la faille classique.
        if ($progress->prize_key !== null && $progress->spun_at !== null) {
            return [
                'key' => (string) $progress->prize_key,
                'label' => (string) $progress->prize_label,
                'type' => (string) $progress->prize_type,
                'value' => (float) $progress->prize_value,
            ];
        }

        $segment = $this->draw($branchId);

        $progress->forceFill([
            'prize_key' => $segment['key'],
            'prize_label' => $segment['label'],
            'prize_type' => $segment['type'],
            'prize_value' => (float) ($segment['value'] ?? 0),
            'spun_at' => now(),
        ])->save();

        return $segment;
    }

    /**
     * Le lot en attente est-il encore réclamable ? Un lot laissé en attente indéfiniment serait
     * réclamable des semaines plus tard, hors de tout plafond journalier — et le client aurait de
     * toute façon oublié.
     */
    public function pendingStillValid(WheelStepProgress $progress): bool
    {
        if ($progress->prize_key === null || $progress->spun_at === null) {
            return false;
        }

        $minutes = max(5, (int) config('wheel.claim_window_minutes', 30));

        return $progress->spun_at->greaterThan(now()->subMinutes($minutes));
    }

    /**
     * TIRAGE + PERSISTANCE EN UN SEUL APPEL — pour le chemin où l'identité est connue D'AVANCE
     * (validation directe au comptoir par l'équipe, ou tout appelant qui a déjà les coordonnées).
     *
     * Le parcours client, lui, passe par `drawPending()` puis `claimPending()` : le tour d'abord,
     * l'identité ensuite. Les deux chemins finissent dans le MÊME `persist()` — il n'existe qu'un
     * seul endroit où une participation naît et où l'unicité est tranchée. Deux endroits, ce serait
     * deux jeux de gardes à maintenir, et un jour l'un des deux oublierait quelque chose.
     *
     * @param  array{method: string, user_id?: int|null, order_id?: int|null, token_hash?: string|null}  $unlock
     *
     * @throws WheelException
     */
    public function spin(
        int $branchId,
        string $phone,
        ?string $customerName,
        array $unlock,
        ?string $deviceId = null,
        ?string $ipHash = null,
        ?string $email = null
    ): WheelSpin {
        if ($this->dailyTotal($branchId) >= (int) config('wheel.daily_total_cap', 120)) {
            throw new WheelException('La roue a distribué tous ses lots pour aujourd\'hui. Reviens demain !', 429);
        }

        return $this->persist(
            $branchId, $phone, $email, $customerName, $this->draw($branchId),
            $unlock, $deviceId, $ipHash
        );
    }

    /**
     * LE SEUL ENDROIT OÙ UNE PARTICIPATION NAÎT. Tout se passe dans une transaction : soit le tour
     * existe avec son lot attribué, soit rien ne s'est produit. Un tour enregistré sans lot, ou un
     * lot sans tour, laisserait un client avec une promesse que le système ne connaît pas.
     *
     * @param  array{key: string, label: string, type: string, value: float, max_discount?: float}  $segment
     * @param  array{method: string, user_id?: int|null, order_id?: int|null, token_hash?: string|null}  $unlock
     *
     * @throws WheelException
     */
    private function persist(
        int $branchId,
        string $phone,
        ?string $email,
        ?string $customerName,
        array $segment,
        array $unlock,
        ?string $deviceId,
        ?string $ipHash
    ): WheelSpin {
        $tel = $this->normalizePhone($phone);
        if (strlen($tel) < 9) {
            throw new WheelException('Numéro de téléphone invalide.', 422);
        }

        // [ÉTAPES 2026-08-09] L'ADRESSE, seconde clé d'identité voulue par le propriétaire : « un
        // e-mail, ça rentre pas deux fois ; le téléphone, ça rentre pas deux fois ». Deux clés
        // valent mieux qu'une — un numéro se change plus facilement qu'une adresse — et l'adresse
        // sert AUSSI à envoyer les conditions du lot. Franchir l'une ne suffit pas.
        $mail = $email !== null ? mb_strtolower(trim($email)) : null;
        if ($mail !== null && $mail !== '') {
            if (! filter_var($mail, FILTER_VALIDATE_EMAIL)) {
                throw new WheelException('Adresse e-mail invalide.', 422);
            }
            if ($this->alreadySpunByEmail($branchId, $mail)) {
                throw new WheelException('Cette adresse a déjà tourné la roue pour cette opération.', 409);
            }
        } else {
            $mail = null;
        }

        $methode = (string) ($unlock['method'] ?? '');
        if (! (bool) (config('wheel.unlock_methods')[$methode] ?? false)) {
            // Le mode déclaratif tombe ici, et c'est voulu : un bouton « j'ai mis mon avis » sur
            // lequel on clique soi-même ne vérifie rien et se rejoue à l'infini.
            throw new WheelException('Ce tour n\'a pas été validé.', 403);
        }

        if ($this->alreadySpun($branchId, $tel)) {
            throw new WheelException('Tu as déjà tourné la roue pour cette opération.', 409);
        }

        return DB::transaction(function () use ($branchId, $tel, $mail, $customerName, $segment, $unlock, $methode, $deviceId, $ipHash) {
            $spin = new WheelSpin();
            $spin->forceFill([
                'branch_id'           => $branchId,
                'campaign_key'        => (string) config('wheel.campaign_key'),
                'phone'               => $tel,
                'email'               => $mail,
                'customer_name'       => $customerName ? mb_substr(trim($customerName), 0, 120) : null,
                'prize_key'           => $segment['key'],
                'prize_label'         => $segment['label'],
                'prize_type'          => $segment['type'],
                'prize_value'         => (float) ($segment['value'] ?? 0),
                'unlock_method'       => $methode,
                'unlocked_by_user_id' => $unlock['user_id'] ?? null,
                'unlock_order_id'     => $unlock['order_id'] ?? null,
                'unlock_token_hash'   => $unlock['token_hash'] ?? null,
                'device_id'           => $deviceId ? mb_substr($deviceId, 0, 64) : null,
                'ip_hash'             => $ipHash,
            ]);

            try {
                $spin->save();
            } catch (QueryException $e) {
                // Course entre deux requêtes du même téléphone : la contrainte d'unicité a tranché.
                // C'est le comportement voulu — on préfère un refus net à deux lots attribués.
                if ($this->isUniqueViolation($e)) {
                    // Deux contraintes peuvent avoir tranché : le téléphone ou l'adresse. On ne dit
                    // pas LAQUELLE — le message resterait le même pour le client honnête, et
                    // distinguer les deux apprendrait à un fraudeur quelle clé changer.
                    throw new WheelException('Tu as déjà tourné la roue pour cette opération.', 409);
                }
                throw $e;
            }

            $this->award($spin, $segment);

            Log::channel('daily')->info('wheel.spin', [
                'spin_id' => $spin->id, 'branch' => $branchId,
                'prize' => $segment['key'], 'unlock' => $methode,
            ]);

            return $spin->refresh();
        });
    }

    /**
     * LA RÉCLAMATION — l'identité arrive, le lot devient réel.
     *
     * C'est ICI que tout se joue : la ligne de participation est créée, l'unicité du téléphone ET de
     * l'adresse est tranchée EN BASE, le coupon est émis. Avant cet instant, rien n'existe.
     *
     * @param  array{method: string, user_id?: int|null, order_id?: int|null, token_hash?: string|null}  $unlock
     *
     * @throws WheelException
     */
    public function claimPending(
        int $branchId,
        WheelStepProgress $progress,
        string $phone,
        string $email,
        ?string $customerName = null,
        array $unlock = [],
        ?string $deviceId = null,
        ?string $ipHash = null
    ): WheelSpin {
        if (! $this->pendingStillValid($progress)) {
            throw new WheelException(
                'Ce tour a expiré. Rescanne le QR au comptoir, ça prend dix secondes.',
                410
            );
        }

        $segment = [
            'key' => (string) $progress->prize_key,
            'label' => (string) $progress->prize_label,
            'type' => (string) $progress->prize_type,
            'value' => (float) $progress->prize_value,
            // Le plafond en euros est relu dans la CONFIGURATION au moment de l'attribution, jamais
            // stocké en attente : un plafond figé dans une ligne temporaire pourrait être exploité
            // en réclamant un vieux lot après un changement de réglage.
            'max_discount' => $this->maxDiscountFor((string) $progress->prize_key),
        ];

        return $this->persist(
            $branchId, $phone, $email, $customerName, $segment, $unlock, $deviceId, $ipHash
        );
    }

    private function maxDiscountFor(string $prizeKey): float
    {
        foreach ((array) config('wheel.segments', []) as $s) {
            if ((string) ($s['key'] ?? '') === $prizeKey) {
                return (float) ($s['max_discount'] ?? 0);
            }
        }

        return 0.0;
    }

    /**
     * Tirage pondéré, générateur CRYPTOGRAPHIQUE. Un lot au plafond du jour voit son poids tomber
     * à zéro : la roue continue de tourner, elle cesse simplement de donner CE lot-là.
     *
     * @return array{key: string, label: string, type: string, value: float}
     */
    private function draw(int $branchId): array
    {
        $segments = (array) config('wheel.segments', []);
        if (empty($segments)) {
            throw new WheelException('La roue n\'est pas configurée.', 503);
        }

        $eligibles = [];
        $total = 0;
        foreach ($segments as $s) {
            $poids = max(0, (int) ($s['weight'] ?? 0));
            $cap = (int) ($s['daily_cap'] ?? 0);
            if ($poids > 0 && $cap > 0 && $this->dailyCount($branchId, (string) $s['key']) >= $cap) {
                $poids = 0; // plafond atteint : plus tirable aujourd'hui
            }

            /*
             * [P1 2026-08-10] LA ROUE OFFRAIT DES PRODUITS EN RUPTURE.
             *
             * Prouvé en base : produit passé en rupture (86) depuis la caisse, et la roue a quand
             * même offert « Boisson offerte » — que le comptoir a pu remettre. Le client gagne, on
             * lui dit non : c'est la pire séquence possible, et elle vient du logiciel, pas de
             * l'équipe.
             *
             * On réemploie le mécanisme qui existe déjà juste au-dessus : un lot indisponible voit
             * son poids tomber à zéro. La roue continue de tourner, elle cesse simplement de
             * promettre CE lot-là — exactement comme pour un plafond journalier. Rien à expliquer au
             * client, rien à surveiller pour l'équipe : la rupture qu'elle pose à la caisse suffit.
             *
             * Ne concerne que les produits offerts : un pourcentage ou des points ne sortent d'aucun
             * stock.
             */
            if ($poids > 0 && (string) ($s['type'] ?? '') === 'free_item'
                && ! $this->produitServable($branchId, (string) $s['key'])) {
                $poids = 0;
            }

            /*
             * [P0 2026-08-10 · relecture « gestion et contrôle »] LA ROUE DONNAIT DES CODES QUE LE
             * LOGICIEL REFUSE PARTOUT.
             *
             * Les remises sont derrière deux interrupteurs de la caisse — `pos.coupon_codes_enabled`
             * et l'ancien `pos.manual_discount_enabled` — et TOUS DEUX valent faux par défaut. Dans
             * cet état, `FrontendOrderService` refuse la remise à la création de commande ET l'entrée
             * du code est masquée sur le site. Mesuré : 40 % du poids de la roue sur des lots en
             * remise, donc deux clients sur cinq repartaient avec un code inutilisable — pendant que
             * la page leur disait « saisis-le dans ton panier, c'est valable dès maintenant », et que
             * l'e-mail le répétait.
             *
             * On ne promet pas ce que la maison refuse. Le lot cesse d'être tiré, exactement comme un
             * lot en rupture ou au plafond du jour. Et le jour où l'exploitant rallume les codes, il
             * revient tout seul.
             *
             * On lit LE MÊME couple d'interrupteurs que la garde de commande
             * (`FrontendOrderService::assertDiscretionaryDiscountAllowed`) : un miroir qui dérive
             * finirait par promettre à nouveau ce que la commande refuse.
             */
            if ($poids > 0 && str_starts_with((string) ($s['type'] ?? ''), 'coupon_')
                && ! $this->remisesAcceptees()) {
                $poids = 0;
            }

            if ($poids > 0) {
                $eligibles[] = ['s' => $s, 'w' => $poids];
                $total += $poids;
            }
        }

        if ($total <= 0) {
            // Tous les lots sont épuisés. On ne « donne rien » en silence : on le dit.
            throw new WheelException('Tous les lots du jour sont partis. Reviens demain !', 429);
        }

        // `random_int` et pas `mt_rand` : la suite de `mt_rand` se prédit à partir de quelques
        // tirages observés, et sur un jeu à lots c'est une faille exploitable, pas une coquetterie.
        $tirage = random_int(1, $total);
        $curseur = 0;
        foreach ($eligibles as $e) {
            $curseur += $e['w'];
            if ($tirage <= $curseur) {
                return [
                    'key'   => (string) $e['s']['key'],
                    'label' => (string) $e['s']['label'],
                    'type'  => (string) $e['s']['type'],
                    'value' => (float) ($e['s']['value'] ?? 0),
                    'max_discount' => (float) ($e['s']['max_discount'] ?? 0),
                ];
            }
        }

        $dernier = end($eligibles)['s']; // inatteignable en théorie ; jamais de retour vide en pratique

        return [
            'key' => (string) $dernier['key'], 'label' => (string) $dernier['label'],
            'type' => (string) $dernier['type'], 'value' => (float) ($dernier['value'] ?? 0),
            'max_discount' => (float) ($dernier['max_discount'] ?? 0),
        ];
    }

    /**
     * Matérialise le lot. Une remise devient un COUPON du moteur existant — jamais un montant
     * baladé côté client : `PricingService` reste la source unique des prix.
     */
    private function award(WheelSpin $spin, array $segment): void
    {
        if ($segment['type'] === 'points') {
            $spin->points_awarded = (int) $segment['value'];
            $spin->save();

            return;
        }

        /*
         * [P0 2026-08-09 — trois audits convergents] Un `free_item` NE DOIT PAS créer de coupon.
         *
         * Il en créait un avec `discount = 0` : le client saisissait le code, le total ne bougeait
         * pas, et l'usage unique était BRÛLÉ. Il payait plein tarif, son lot était mort, et la
         * comptabilité enregistrait le coût d'un cadeau jamais donné.
         *
         * La cause est conceptuelle, pas technique : un coupon retire de l'argent d'un total ; « une
         * boisson offerte » n'est pas une remise, c'est un objet qu'on tend. Ces lots se remettent
         * donc AU COMPTOIR (voir WheelDeliveryService), ce qui est cohérent avec un jeu dont tout le
         * modèle repose déjà sur un humain au comptoir.
         */
        if (! in_array($segment['type'], ['coupon_percent', 'coupon_fixed'], true)) {
            return;
        }

        $jours = (int) config('wheel.prize_validity_days', 30);

        // [P1 COLLISION] `coupons.code` N'A PLUS d'index UNIQUE (retiré volontairement le
        // 2026-08-07) : la garantie repose désormais sur un générateur qui VÉRIFIE et REPREND sur
        // collision — c'est écrit dans le docbloc de cette migration. Frapper un code au hasard
        // sans contrôle contourne exactement ce dispositif, et `resolveCouponByCode` renvoie le
        // PLUS ANCIEN en cas de doublon : le gagnant tomberait sur un coupon déjà brûlé.
        $code = 'ROUE-' . strtoupper(Str::random(6));
        for ($essai = 0; $essai < 5 && Coupon::withoutGlobalScopes()->withTrashed()->where('code', $code)->exists(); $essai++) {
            $code = 'ROUE-' . strtoupper(Str::random(6));
        }

        $coupon = new Coupon();
        $coupon->forceFill([
            'name'            => 'Roue — ' . $segment['label'],
            'description'     => 'Lot gagné à la roue le ' . now()->format('d/m/Y')
                . ($this->requiresOrder() ? ' — valable sur une commande.' : ''),
            'code'            => $code,
            'discount'        => $segment['type'] === 'coupon_percent' ? (float) $segment['value'] : 0,
            'discount_type'   => $segment['type'] === 'coupon_percent' ? DiscountType::PERCENTAGE : DiscountType::FIXED,
            'start_date'      => now(),
            'end_date'        => Carbon::now()->addDays($jours)->endOfDay(),
            'status'          => Status::ACTIVE,
            // Nominatif à usage unique : c'est le lot de CETTE personne, pas un code qui circule.
            'max_uses_global' => 1,
            'limit_per_user'  => 1,
            // MINIMUM D'ACHAT — « ils peuvent récupérer ça que avec une commande », plus un
            // plancher. C'est ce qui rend le jeu rentable : une remise sur une commande de 10 €
            // reste largement bénéficiaire, et personne ne vient chercher un cadeau sans acheter.
            'minimum_order'   => (float) config('wheel.min_order_amount', 0),
            // PLAFOND EN EUROS — voir config/wheel.php. 0 = illimité côté moteur de coupons, ce
            // qui transformerait « -15 % » en cadeau à trois chiffres sur une grosse commande.
            'maximum_discount' => (float) ($segment['max_discount'] ?? 0),
            'usage_count'     => 0,
        ])->save();

        $spin->coupon_id = $coupon->id;
        $spin->save();
    }

    public function requiresOrder(): bool
    {
        return (bool) config('wheel.requires_order_to_claim', true);
    }

    /**
     * La maison accepte-t-elle les codes de remise en ce moment ?
     *
     * MÊME couple d'interrupteurs que la garde de commande. Le dupliquer ailleurs ou en oublier un
     * ferait dériver le miroir, et la roue recommencerait à promettre ce que la commande refuse.
     */
    public function remisesAcceptees(): bool
    {
        return config('pos.coupon_codes_enabled') === true
            || config('pos.manual_discount_enabled') === true;
    }

    /**
     * Le produit qu'engage ce lot est-il encore servable dans cette caisse ?
     *
     * Sans produit de référence configuré, on ne peut rien vérifier — et on ne bloque pas : mieux
     * vaut un lot non chiffré (la commande de réconciliation le signale) qu'une roue qui refuse de
     * tourner à cause d'un réglage manquant.
     *
     * Toute panne de lecture laisse passer, aussi : une roue qui s'arrête parce que la table de
     * disponibilité tousse serait un remède pire que le mal.
     */
    private function produitServable(int $branchId, string $prizeKey): bool
    {
        try {
            $itemId = app(WheelDeliveryService::class)->costItemId($prizeKey);
            if ($itemId === null) {
                return true;
            }

            return app(\App\Services\Menu\AvailabilityService::class)->isAvailable($itemId, $branchId);
        } catch (\Throwable $e) {
            return true;
        }
    }

    private function dailyCount(int $branchId, string $prizeKey): int
    {
        return WheelSpin::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)
            ->where('prize_key', $prizeKey)
            ->whereDate('created_at', now()->toDateString())
            ->count();
    }

    private function dailyTotal(int $branchId): int
    {
        return WheelSpin::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)
            ->whereDate('created_at', now()->toDateString())
            ->count();
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $code = (string) ($e->errorInfo[1] ?? '');

        return in_array($code, ['1062', '19', '23505'], true)
            || str_contains(strtolower($e->getMessage()), 'unique');
    }
}

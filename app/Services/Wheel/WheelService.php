<?php

namespace App\Services\Wheel;

use App\Enums\DiscountType;
use App\Enums\Status;
use App\Models\Coupon;
use App\Models\Scopes\BranchScope;
use App\Models\WheelSpin;
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

    /**
     * LE TIRAGE. Tout se passe dans une transaction : soit le tour existe avec son lot attribué,
     * soit rien ne s'est produit. Un tour enregistré sans lot, ou un lot sans tour, laisserait un
     * client avec une promesse que le système ne connaît pas.
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
        ?string $ipHash = null
    ): WheelSpin {
        $tel = $this->normalizePhone($phone);
        if (strlen($tel) < 9) {
            throw new WheelException('Numéro de téléphone invalide.', 422);
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

        if ($this->dailyTotal($branchId) >= (int) config('wheel.daily_total_cap', 120)) {
            throw new WheelException('La roue a distribué tous ses lots pour aujourd\'hui. Reviens demain !', 429);
        }

        return DB::transaction(function () use ($branchId, $tel, $customerName, $unlock, $methode, $deviceId, $ipHash) {
            $segment = $this->draw($branchId);

            $spin = new WheelSpin();
            $spin->forceFill([
                'branch_id'           => $branchId,
                'campaign_key'        => (string) config('wheel.campaign_key'),
                'phone'               => $tel,
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
            'minimum_order'   => 0,
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

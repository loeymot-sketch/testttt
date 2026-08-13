<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;

/**
 * Une participation à la roue. Porte le lot FIGÉ au tirage (libellé, type, valeur) en plus de la
 * référence au coupon : si la configuration des segments change demain, on doit toujours pouvoir
 * dire ce qui a été promis à ce client-là, ce jour-là. Même principe que le `rendered_payload` du
 * ticket promo.
 *
 * Portée par branche comme le reste du système (V1 n'a qu'une branche, mais la règle ne souffre
 * pas d'exception : c'est ainsi qu'une exception devient une fuite en V2).
 */
class WheelSpin extends Model
{
    protected $table = 'wheel_spins';

    protected $guarded = [];

    protected $casts = [
        'prize_value'    => 'float',
        'points_awarded' => 'integer',
        'claimed_at'     => 'datetime',
        // Sans ce cast, `delivered_at` reste une CHAÎNE et tout `->format()` casse — y compris le
        // message « ce lot a déjà été remis le … » que l'équipe montre au client.
        'delivered_at'   => 'datetime',
        'delivered_by_user_id' => 'integer',
        'points_credited_user_id' => 'integer',
    ];

    /** Le coupon matérialisant le lot, quand le lot est une remise (pas des points). */
    public function coupon()
    {
        // [2026-08-12] `withoutGlobalScope(BranchScope)` au SINGULIER, et pas `withoutGlobalScopes()` :
        // le pluriel retirait AUSSI le filtre de suppression douce, donc un coupon RÉVOQUÉ se
        // résolvait par cette relation — et tout code qui lit `$spin->coupon` pour décider quelque
        // chose agissait alors sur un coupon mort. Qui a besoin des supprimés ajoute `withTrashed()`.
        return $this->belongsTo(\App\Models\Coupon::class, 'coupon_id')
            ->withoutGlobalScope(\App\Models\Scopes\BranchScope::class);
    }

    /**
     * RETROUVER UN TOUR PAR LE CODE QUE LE CLIENT TIENT DANS SA MAIN.
     *
     * [2026-08-13 · propriétaire : « pour valider le code promo au cas où, ou bien dans la caisse »]
     *
     * ── LE TROU QUE ÇA REFERME ───────────────────────────────────────────────────────────────
     * L'écran de remise ne cherchait QUE par numéro de téléphone. Or ce que le client présente au
     * comptoir, c'est son CODE — « ROUE-FLZ5EN » — affiché sur sa page ou reçu par courriel. Il ne
     * se souvient pas forcément du numéro qu'il a tapé (on donne parfois celui de son conjoint), et
     * l'équipe n'avait aucun moyen de partir de ce qu'il MONTRE. Le seul objet que le jeu remet au
     * client était le seul avec lequel on ne pouvait rien faire.
     *
     * ── POURQUOI ICI, ET PAS DANS LE SERVICE DE REMISE ───────────────────────────────────────
     * Sa place naturelle serait à côté de `WheelDeliveryService::pending()`. Elle est ici parce que
     * ce n'est pas une règle de remise : c'est une REQUÊTE sur ce modèle, sans décision métier —
     * elle rend un tour, elle ne dit pas s'il est remettable. Le service garde les règles, le
     * modèle garde ses recherches.
     *
     * ── CE QUI EST TOLÉRÉ À LA SAISIE, ET CE QUI NE L'EST PAS ────────────────────────────────
     * Tolérés : la casse, les espaces, le préfixe oublié — quelqu'un qui lit son code à voix haute
     * au comptoir dit « FLZ5EN » aussi souvent que le tout. C'est de la saisie humaine, debout,
     * pendant un service.
     *
     * PAS toléré : la caisse. La recherche reste bornée à `branch_id`, exactement comme celle par
     * numéro. Ouvrir une seconde porte d'entrée sur le même écran est précisément le moment où
     * l'on oublie de lui remettre la même serrure.
     *
     * Jamais de `LIKE %…%` : sur des codes courts, il ferait d'une saisie partielle la clé de
     * plusieurs tours, et l'équipe remettrait le lot d'un autre client.
     */
    public static function parCode(int $branchId, string $code): ?self
    {
        $propre = strtoupper((string) preg_replace('/[^A-Za-z0-9\-]/', '', $code));
        if ($propre === '') {
            return null;
        }

        $candidats = array_values(array_unique([
            $propre,
            str_starts_with($propre, 'ROUE-') ? $propre : 'ROUE-'.$propre,
        ]));

        // On part du coupon VIVANT : le filtre de suppression douce reste en place, donc un code
        // révoqué ne retrouve rien — et c'est le comportement voulu au comptoir.
        $coupon = \App\Models\Coupon::query()
            ->withoutGlobalScope(BranchScope::class)
            ->whereIn('code', $candidats)
            ->first();

        if ($coupon === null) {
            return null;
        }

        return static::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)
            ->where('coupon_id', $coupon->id)
            ->orderByDesc('id')
            ->first();
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope());
    }
}

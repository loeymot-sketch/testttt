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

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope());
    }
}

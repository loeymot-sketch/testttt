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
    ];

    /** Le coupon matérialisant le lot, quand le lot est une remise (pas des points). */
    public function coupon()
    {
        return $this->belongsTo(\App\Models\Coupon::class, 'coupon_id')->withoutGlobalScopes();
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope());
    }
}

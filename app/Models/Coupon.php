<?php

namespace App\Models;

use App\Enums\Status;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Coupon extends Model implements HasMedia
{
    // [P3 heal 2026-07-07] SoftDeletes préserve l'historique NF525 : une commande
    // passée (order_coupons.coupon_id, sans FK) garde une référence résolvable via
    // withTrashed() au lieu de pointer dans le vide après suppression du coupon.
    use HasFactory, InteractsWithMedia, SoftDeletes;

    protected $table = "coupons";

    protected $fillable = [
        'name',
        'description',
        'code',
        'start_date',
        'end_date',
        'discount',
        'discount_type',
        'minimum_order',
        'maximum_discount',
        'limit_per_user',
        // [PROMO-DASH-2026-05-06] Advanced scoping fields
        'valid_days_of_week',
        'valid_hours_start',
        'valid_hours_end',
        'branch_scope',
        'surfaces',
        'max_uses_global',
        'usage_count',
        'status',
    ];

    protected $casts = [
        'id'                 => 'integer',
        'name'               => 'string',
        'description'        => 'string',
        'code'               => 'string',
        'start_date'         => 'datetime',
        'end_date'           => 'datetime',
        'discount'           => 'decimal:6',
        'discount_type'      => 'integer',
        'minimum_order'      => 'decimal:6',
        'maximum_discount'   => 'decimal:6',
        'limit_per_user'     => 'integer',
        // [PROMO-DASH-2026-05-06] Advanced scoping casts. valid_hours_*
        // restent string (HH:MM:SS) pour rester portables MySQL + SQLite.
        'valid_days_of_week' => 'array',
        'branch_scope'       => 'array',
        'surfaces'           => 'array',
        'max_uses_global'    => 'integer',
        'usage_count'        => 'integer',
        'status'             => 'integer',
    ];

    /**
     * Codes promo actifs (status=ACTIVE et fenêtre de validité courante).
     * Volontairement permissif sur les colonnes de date pour rester compatible
     * avec les anciens enregistrements à start/end nullables.
     */
    public function scopeActive(Builder $query): Builder
    {
        $now = Carbon::now();
        return $query
            ->where('status', Status::ACTIVE)
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('start_date')->orWhere('start_date', '<=', $now);
            })
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $now);
            });
    }

    /**
     * Détermine si le coupon est utilisable MAINTENANT pour la branche +
     * surface donnée, selon les nouvelles dimensions de scoping.
     *
     * NOTE: cette méthode complète {@see CouponService::validateCouponForOrder()}
     * — elle ne vérifie pas `minimum_order` ni `limit_per_user` (logique
     * order-flow), mais valide les invariants de planning (jour, heure,
     * branche, canal, status, dates, max_uses_global).
     */
    public function isUsableNow(?int $branchId = null, ?string $surface = null, ?Carbon $now = null): bool
    {
        $now = $now ?? Carbon::now();

        // status
        if ((int) $this->status !== Status::ACTIVE) {
            return false;
        }

        // Fenêtre de dates
        if ($this->start_date && $now->lt(Carbon::parse($this->start_date))) {
            return false;
        }
        if ($this->end_date && $now->gt(Carbon::parse($this->end_date))) {
            return false;
        }

        // Jour de la semaine
        $days = $this->valid_days_of_week;
        if (is_array($days) && !empty($days)) {
            $todayKey = strtolower(substr($now->englishDayOfWeek, 0, 3)); // mon/tue/wed/...
            $normalized = array_map(static fn ($d) => strtolower(substr((string) $d, 0, 3)), $days);
            if (!in_array($todayKey, $normalized, true)) {
                return false;
            }
        }

        // Plage horaire
        if (!empty($this->valid_hours_start) && !empty($this->valid_hours_end)) {
            $start = $this->normalizeTime((string) $this->valid_hours_start);
            $end = $this->normalizeTime((string) $this->valid_hours_end);
            $current = $now->format('H:i:s');
            if ($start <= $end) {
                if ($current < $start || $current > $end) {
                    return false;
                }
            } else {
                // Plage qui chevauche minuit (ex: 22:00 - 02:00)
                if ($current < $start && $current > $end) {
                    return false;
                }
            }
        }

        // Branche
        $branchScope = $this->branch_scope;
        if (is_array($branchScope) && !empty($branchScope)) {
            if ($branchId === null || !in_array((int) $branchId, array_map('intval', $branchScope), true)) {
                return false;
            }
        }

        // Surface (canal)
        $surfaces = $this->surfaces;
        if (is_array($surfaces) && !empty($surfaces)) {
            if ($surface === null || !in_array(strtolower((string) $surface), array_map('strtolower', $surfaces), true)) {
                return false;
            }
        }

        // Quota global
        if (!is_null($this->max_uses_global) && (int) $this->max_uses_global > 0) {
            if ((int) $this->usage_count >= (int) $this->max_uses_global) {
                return false;
            }
        }

        return true;
    }

    private function normalizeTime(string $time): string
    {
        // Accepte "12:00" et "12:00:00".
        if (preg_match('/^\d{2}:\d{2}$/', $time)) {
            return $time . ':00';
        }
        return $time;
    }

    public function getImageAttribute(): string
    {
        if (!empty($this->getFirstMediaUrl('coupon'))) {
            return asset($this->getFirstMediaUrl('coupon'));
        }
        return asset('images/default/offer.png');
    }
}

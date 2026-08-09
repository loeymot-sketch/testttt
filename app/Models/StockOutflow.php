<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;

/**
 * [OWNER REPAS-PERSONNEL/PERTES 2026-07-31] Sortie de stock hors-vente (repas personnel / perte),
 * append-only + branch-scopée (comme StockMovement). LA trace de tout ce qui part sans vente.
 */
class StockOutflow extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_STAFF_MEAL = 'staff_meal';

    public const TYPE_WASTE = 'waste';

    /**
     * [ROUE 2026-08-09] Cadeau gagné à la roue et RÉELLEMENT consommé sur une commande.
     *
     * Volontairement ABSENT de `TYPES` : cette constante-là sert de liste blanche à la saisie
     * MANUELLE au comptoir (`PosStockOutflowController`). Un cadeau de roue ne doit jamais être
     * saisi à la main — il n'existe que si un lot a été gagné puis consommé, et c'est la
     * réconciliation qui l'inscrit. L'ouvrir à la saisie créerait une porte pour sortir du stock
     * sans qu'aucun lot ne corresponde.
     */
    public const TYPE_PROMO_GIFT = 'promo_gift';

    /** Saisissables à la main au comptoir. */
    public const TYPES = [self::TYPE_STAFF_MEAL, self::TYPE_WASTE];

    /** TOUS les types qui peuvent exister en base — pour l'affichage et les totaux. */
    public const TYPES_ALL = [self::TYPE_STAFF_MEAL, self::TYPE_WASTE, self::TYPE_PROMO_GIFT];

    protected $fillable = [
        'branch_id',
        'item_id',
        'item_name',
        'quantity',
        'type',
        'note',
        'user_id',
        'stock_decremented',
        'created_at',
    ];

    protected $casts = [
        'id' => 'integer',
        'branch_id' => 'integer',
        'item_id' => 'integer',
        'quantity' => 'integer',
        'user_id' => 'integer',
        'stock_decremented' => 'boolean',
        'created_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::addGlobalScope(new BranchScope());
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \LogicException('stock_outflows is append-only.');
        });
        static::deleting(function (): void {
            throw new \LogicException('stock_outflows is append-only.');
        });
    }
}

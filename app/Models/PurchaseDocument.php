<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P3a] Document d'achat (facture ou
 * ticket photographié). Le BRUT est conservé (photo_path — amendement #8).
 *
 * Idempotence : `doc_hash` UNIQUE (ré-ingérer la même facture = rejeté). Le
 * cycle est draft → validated ; une fois `validated`, re-valider = NO-OP
 * (gate PurchaseService::validateDocument, protège le recalcul d'avg_cost).
 *
 * Domaine NEUF, ADDITIF, HORS NF525. Pas de BranchScope global : hard-scope
 * explicite par les appelants (exemption BranchScopeCoverageSentinelTest).
 */
class PurchaseDocument extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_VALIDATED = 'validated';

    public const SOURCE_FACTURE = 'facture';
    public const SOURCE_TICKET = 'ticket';

    protected $fillable = [
        'branch_id',
        'supplier_id',
        'doc_date',
        'total_ht',
        'total_ttc',
        'tva_rate',
        'photo_path',
        'source',
        'status',
        'doc_hash',
    ];

    protected $casts = [
        'id' => 'integer',
        'branch_id' => 'integer',
        'supplier_id' => 'integer',
        'doc_date' => 'date',
        'total_ht' => 'decimal:2',
        'total_ttc' => 'decimal:2',
        'tva_rate' => 'decimal:2',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lines()
    {
        return $this->hasMany(PurchaseLine::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}

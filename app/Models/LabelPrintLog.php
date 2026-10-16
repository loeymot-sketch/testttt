<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * [ETIQUETTES 2026-10-16] Journal d'impression des étiquettes (hygiène + commandes).
 *
 * Cycle de vie : `claimed` (réclamé, impression en cours) → `printed` | `failed` | `unconfirmed`.
 *   unconfirmed — envoyé à l'imprimante, mais elle n'a pas confirmé la fin ; on ne présume ni du succès
 *                 ni de l'échec, et surtout on ne réimprime pas tout seul.
 */
class LabelPrintLog extends Model
{
    public const KIND_DLC = 'dlc';
    public const KIND_ORDER = 'order';

    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_PRINTED = 'printed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_UNCONFIRMED = 'unconfirmed';

    /** Statuts qui comptent comme « les étiquettes de cette commande sont sorties ». */
    public const DONE_STATUSES = [self::STATUS_PRINTED, self::STATUS_UNCONFIRMED];

    /** Pourquoi un lot DLC sort de la surveillance (la ligne reste au journal). */
    public const DISCARD_DISCARDED = 'discarded';
    public const DISCARD_USED = 'used';
    public const DISCARD_CANCELLED = 'cancelled';
    public const DISCARD_REASONS = [self::DISCARD_DISCARDED, self::DISCARD_USED, self::DISCARD_CANCELLED];

    /**
     * Lots d'hygiène surveillés : à échoir dans ces heures, ou échus depuis moins de ces jours (puis journal seul — 30 jours
     * couvrent une fermeture annuelle : au retour, les lots restés en chambre froide sont encore signalés).
     */
    public const WATCH_AHEAD_HOURS = 72;
    public const WATCH_BEHIND_DAYS = 30;

    /**
     * Une réclamation jamais conclue (onglet fermé en pleine impression) est rendue à la file après ce
     * délai, pour qu'une commande ne reste pas « en cours » pour toujours.
     */
    public const CLAIM_TTL_SECONDS = 120;

    /**
     * Après un échec SANS aucune étiquette sortie (capot ouvert, plus de rouleau, Bluetooth coupé), l'impression
     * automatique peut retenter — pas avant ce délai, et au plus MAX_AUTO_ATTEMPTS fois : une panne durable ne doit
     * pas faire boucler la tablette.
     */
    public const RETRY_AFTER_SECONDS = 30;
    public const MAX_AUTO_ATTEMPTS = 3;

    protected $table = 'label_print_logs';

    protected $fillable = [
        'branch_id',
        'user_id',
        'kind',
        'dlc_product_id',
        'product_name',
        'action',
        'lot',
        'original_dlc',
        'capped',
        'storage',
        'cap_source',
        'parent_log_id',
        'declared_new',
        'supplier_lot',
        'offline',
        'anomaly',
        'client_ref',
        'shelf_life_value',
        'shelf_life_unit',
        'opened_at',
        'expires_at',
        'copies',
        'order_id',
        'labels_count',
        'partial',
        'printed_pages',
        'attempts',
        'status',
        'message',
        'claimed_at',
        'finished_at',
        'discarded_at',
        'discarded_by',
        'discard_reason',
        'claim_key',
    ];

    protected $casts = [
        'id' => 'integer',
        'branch_id' => 'integer',
        'user_id' => 'integer',
        'dlc_product_id' => 'integer',
        'shelf_life_value' => 'integer',
        'order_id' => 'integer',
        'copies' => 'integer',
        'labels_count' => 'integer',
        'partial' => 'boolean',
        'printed_pages' => 'integer',
        'attempts' => 'integer',
        'opened_at' => 'datetime',
        'expires_at' => 'datetime',
        'original_dlc' => 'date',
        'capped' => 'boolean',
        'parent_log_id' => 'integer',
        'declared_new' => 'boolean',
        'offline' => 'boolean',
        'anomaly' => 'boolean',
        'discarded_by' => 'integer',
        'claimed_at' => 'datetime',
        'finished_at' => 'datetime',
        'discarded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Qui a sorti le lot de la surveillance (jeté, utilisé, annulé). */
    public function discardedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discarded_by');
    }

    /** Délai pendant lequel un lot marqué « utilisé » peut encore servir d'origine (on étiquette souvent juste après). */
    public const PARENT_USED_GRACE_HOURS = 2;
}

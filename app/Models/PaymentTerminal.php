<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * [Wave F F-2 / Sprint 1C] Physical payment terminal (TPE) with its own fee
 * structure. Multi-tenant via BranchScope.
 *
 * Owner verbatim : "les taux sont par terminal de paiement aussi". Chaque
 * TPE physique a un taux négocié distinct avec son acquéreur — donc
 * fee_percent / fee_fixed sont sur PaymentTerminal, pas sur la gateway
 * logique.
 *
 * Relation OrderPayment.terminal_id (NULL legacy / ARCHIVED) → ce model.
 * Le calcul fees est runtime dans ZReportCashEnrichmentService::aggregateForWindow
 * (read-only enrichment, n'altère pas la signature HMAC fiscale).
 */
class PaymentTerminal extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE   = 1;
    public const STATUS_ARCHIVED = 5;

    public const GATEWAY_STRIPE    = 'stripe';
    public const GATEWAY_SENANGPAY = 'senangpay';
    public const GATEWAY_INGENICO  = 'ingenico';
    public const GATEWAY_VERIFONE  = 'verifone';
    public const GATEWAY_MANUAL    = 'manual';

    /**
     * [ONB-10 2026-08-27] TPE sans intégration matérielle — la caisse encaisse
     * la carte de façon déclarative, le paiement est saisi à la main.
     *
     * Cette valeur n'a pas été inventée ici : `SimulatedTpeTerminal20260708Seeder`
     * l'écrit en base depuis une décision du propriétaire du 2026-07-08, et c'est
     * la passerelle de l'unique TPE réel du Cayenne. Elle manquait simplement à
     * cette liste — donc le formulaire d'administration ne savait pas la
     * représenter : la passerelle s'affichait VIDE à la modification, sur un
     * champ obligatoire, et tout enregistrement partait en 422. Le seul moyen
     * pour le commerçant de renommer son terminal ou d'en corriger les frais
     * était de le déclarer « Ingenico » — c'est-à-dire de mentir sur son
     * matériel, y compris dans la ventilation par terminal du rapport Z.
     *
     * `gateway_type` reste purement descriptif : aucune branche de logique ne le
     * lit (vérifié sur `app/` et `resources/js/` — il est affiché, et recopié
     * dans le payload du rapport Z par ZReportCashEnrichmentService). L'ajouter
     * n'ouvre donc aucun contournement d'encaissement. Le garde-fou du matériel
     * simulé en production reste `POS_SIMULATION_HARDWARE`, contrôlé au boot
     * (AppServiceProvider).
     */
    public const GATEWAY_SIMULATION = 'simulation';

    public const GATEWAY_TYPES = [
        self::GATEWAY_STRIPE,
        self::GATEWAY_SENANGPAY,
        self::GATEWAY_INGENICO,
        self::GATEWAY_VERIFONE,
        self::GATEWAY_MANUAL,
        self::GATEWAY_SIMULATION,
    ];

    protected $table = 'payment_terminals';

    protected $fillable = [
        'branch_id',
        'name',
        'gateway_type',
        'fee_percent',
        'fee_fixed',
        'serial_number',
        'status',
    ];

    protected $casts = [
        'id'            => 'integer',
        'branch_id'     => 'integer',
        'fee_percent'   => 'decimal:3',
        'fee_fixed'     => 'decimal:2',
        'status'        => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::addGlobalScope(new BranchScope());
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function orderPayments(): HasMany
    {
        return $this->hasMany(OrderPayment::class, 'terminal_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return (int) $this->status === self::STATUS_ACTIVE;
    }

    public function isArchived(): bool
    {
        return (int) $this->status === self::STATUS_ARCHIVED;
    }
}

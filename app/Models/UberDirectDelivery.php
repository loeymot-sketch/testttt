<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * [UBER-DIRECT 2026-09-06 · owner] Une course de livraison confiée à un coursier Uber, pour
 * une commande du site.
 *
 * ⚠️ CE N'EST PAS UN ÉTAT DE COMMANDE. Consigne du propriétaire : « Le webhook Uber concerne
 * la LIVRAISON. Le KDS continue de gérer la PRÉPARATION. Ne mélange pas les deux machines
 * d'état. » La cuisine peut avoir bumpé depuis dix minutes pendant que le coursier roule
 * encore ; à l'inverse, un coursier peut attendre devant un plat qui n'est pas prêt. Ces
 * deux cycles vivent donc côte à côte, jamais l'un dans l'autre — et `OrderStateMachine`
 * (zone gelée) n'est pas touchée.
 *
 * Le cycle propre à la LIVRAISON suit les statuts qu'Uber publie :
 *   pending             — course créée, coursier pas encore assigné
 *   pickup              — le coursier va au restaurant
 *   pickup_complete     — il a le sac
 *   dropoff             — il roule vers le client
 *   delivered           — remis
 *   canceled / returned — annulé, ou revenu au restaurant
 *   shopping_completed  — (courses de type achat, hors de notre usage)
 *
 * `status` est une CHAÎNE LIBRE à dessein : un statut qu'Uber ajouterait demain doit être
 * ENREGISTRÉ, pas rejeté. Perdre l'information serait pire que ne pas la comprendre.
 */
class UberDirectDelivery extends Model
{
    // Statuts Uber officiels (doc du 2026-09-06). Repères de lecture, PAS une liste fermée.
    public const STATUS_PENDING = 'pending';
    public const STATUS_PICKUP = 'pickup';
    public const STATUS_PICKUP_COMPLETE = 'pickup_complete';
    public const STATUS_DROPOFF = 'dropoff';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_RETURNED = 'returned';

    /** Statuts après lesquels plus rien ne bougera. */
    public const TERMINAL_STATUSES = [
        self::STATUS_DELIVERED,
        self::STATUS_CANCELED,
        self::STATUS_RETURNED,
    ];

    protected $table = 'uber_direct_deliveries';

    protected $fillable = [
        'branch_id',
        'order_id',
        'quote_id',
        'quote_fee_cents',
        'customer_fee_cents',
        'currency',
        'quote_expires_at',
        'eta_minutes',
        'pricing_rule',
        'provider',
        'delivery_id',
        'tracking_url',
        'status',
        'status_updated_at',
        'dropoff_postal_code',
        'dropoff_city',
        'dropoff_phone',
        'dropoff_instructions',
        'failure_code',
        'failure_message',
        'create_attempts',
        'last_attempt_at',
        'quote_payload',
        'delivery_payload',
    ];

    protected $casts = [
        // Entiers : l'argent ne passe jamais par un flottant ici (742 = 7,42 €).
        'quote_fee_cents' => 'integer',
        'customer_fee_cents' => 'integer',
        'eta_minutes' => 'integer',
        'create_attempts' => 'integer',
        'quote_expires_at' => 'datetime',
        'status_updated_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'quote_payload' => 'array',
        'delivery_payload' => 'array',
    ];

    /**
     * Isolation de branche, comme toute donnée d'exploitation (CLAUDE.md §9). Une course
     * appartient au restaurant qui l'a commandée — jamais à un autre.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Le devis tient-il encore assez longtemps pour lancer un paiement ?
     *
     * On refuse d'utiliser un devis qui expire pendant que le client saisit sa carte : il
     * serait débité d'un montant qu'il n'a jamais accepté. La marge est réglable
     * (`uber_direct.quote_safety_margin_seconds`), jamais écrite en dur.
     */
    public function quoteStillUsable(?int $marginSeconds = null): bool
    {
        if ($this->quote_id === null || $this->quote_expires_at === null) {
            return false;
        }

        $margin = $marginSeconds ?? (int) config('uber_direct.quote_safety_margin_seconds', 120);

        return $this->quote_expires_at->getTimestamp() - now()->getTimestamp() > $margin;
    }

    /** La course est-elle arrivée à son terme ? */
    public function isTerminal(): bool
    {
        return in_array((string) $this->status, self::TERMINAL_STATUSES, true);
    }

    /**
     * Une course a-t-elle réellement été dépêchée ?
     *
     * Sert de garde anti-doublon en complément de la contrainte UNIQUE : on ne redemande
     * jamais un coursier pour une commande qui en a déjà un.
     */
    public function isDispatched(): bool
    {
        return $this->delivery_id !== null && $this->delivery_id !== '';
    }

    /**
     * Le paiement est passé mais aucun coursier n'a pu être dépêché.
     *
     * Consigne du propriétaire : « ne masque jamais cet état comme si la commande était
     * normale ». C'est ce que lit l'écran d'administration pour le signaler.
     */
    public function needsAttention(): bool
    {
        return $this->order_id !== null
            && ! $this->isDispatched()
            && $this->failure_code !== null;
    }
}

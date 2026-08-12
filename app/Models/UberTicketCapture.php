<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * [UBER-PHOTO 2026-08-10 · owner] Une photo (ou une série de photos) du ticket Uber prise sur la
 * tablette du restaurant, sa lecture, et la commande qui en est née.
 *
 * Le cycle de vie est volontairement court et lisible :
 *   pending    — les photos sont stockées, la lecture n'a pas encore rendu son verdict
 *   extracted  — le lecteur a rendu des lignes ; elles attendent l'œil humain
 *   failed     — le lecteur n'a rien pu tirer des photos (le personnel saisira à la main)
 *   confirmed  — un humain a validé : la commande existe et la cuisine l'a reçue
 *   discarded  — un humain a jeté la lecture (mauvais cadrage, doublon, erreur)
 *
 * `confirmed` est le SEUL état qui crée une commande. Une lecture automatique n'entre jamais
 * seule en cuisine : un modèle qui invente un produit ferait préparer un plat qui n'a pas été
 * vendu, et le restaurant le découvrirait au moment de la remise au livreur.
 */
class UberTicketCapture extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_EXTRACTED = 'extracted';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_DISCARDED = 'discarded';

    protected $table = 'uber_ticket_captures';

    protected $fillable = [
        'branch_id',
        'user_id',
        'photo_paths',
        'photo_hash',
        'status',
        'extracted',
        'confirmed_payload',
        'customer_name',
        'display_id',
        'items_count',
        'total',
        'order_id',
        'vision_driver',
        'error_message',
        'confirmed_at',
    ];

    protected $casts = [
        'photo_paths' => 'array',
        'extracted' => 'array',
        'confirmed_payload' => 'array',
        'items_count' => 'integer',
        'total' => 'float',
        'confirmed_at' => 'datetime',
    ];

    /**
     * Isolation de branche, comme toute donnée d'exploitation (CLAUDE.md §9). Une capture
     * appartient à la caisse qui l'a prise — jamais à une autre.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

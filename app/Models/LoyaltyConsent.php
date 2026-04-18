<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Kiosk Design V1 — Phase 1.2
 *
 * Journal RGPD des consentements loyalty. Stocke IP / User-Agent HASHÉS
 * (sha256 + salt `app.key`) — non réversibles, conformes CNIL.
 *
 * Ne jamais écrire d'IP/UA brut ici.
 *
 * @property int    $id
 * @property int    $user_id
 * @property bool   $consent_accepted
 * @property string $privacy_notice_version
 * @property string $ip_hash
 * @property string $user_agent_hash
 * @property \Carbon\Carbon $occurred_at
 */
class LoyaltyConsent extends Model
{
    use HasFactory;

    protected $table = 'loyalty_consents';

    protected $fillable = [
        'user_id',
        'consent_accepted',
        'privacy_notice_version',
        'ip_hash',
        'user_agent_hash',
        'occurred_at',
    ];

    protected $casts = [
        'id'                     => 'integer',
        'user_id'                => 'integer',
        'consent_accepted'       => 'boolean',
        'privacy_notice_version' => 'string',
        'ip_hash'                => 'string',
        'user_agent_hash'        => 'string',
        'occurred_at'            => 'datetime',
    ];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Hash déterministe pour une chaîne arbitraire (IP ou UA), salée avec
     * `app.key` — garantit anonymisation et non-réversibilité côté DB.
     *
     * Retour vide si l'entrée est vide (n'hashe pas une chaîne vide).
     */
    public static function hashIdentifier(?string $raw): string
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return str_repeat('0', 64);
        }
        $salt = (string) config('app.key', 'kiosk-loyalty-default-salt');
        return hash('sha256', $salt . '|' . $value);
    }
}

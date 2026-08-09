<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Progression des étapes d'un parcours de roue, HORODATÉE PAR LE SERVEUR.
 *
 * Pas de `BranchScope` ici, à dessein : cette table est consultée AVANT toute authentification (le
 * client n'est pas connecté, il vient de scanner un QR), et la portée par branche est assurée par la
 * clé — l'empreinte du jeton, qui porte déjà sa branche et que le contrôleur vérifie.
 */
class WheelStepProgress extends Model
{
    protected $table = 'wheel_step_progress';

    protected $guarded = [];

    protected $casts = [
        'branch_id' => 'integer',
        'review_opened_at' => 'datetime',
        'follow_opened_at' => 'datetime',
        'followers_before' => 'integer',
    ];
}

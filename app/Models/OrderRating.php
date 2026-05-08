<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * [WAVE-ALPHA-A3 / M-3] CSAT rating model.
 *
 * Note BranchScope : on n'attache PAS le scope ici parce que le client
 * (kiosk anonymous customer) écrit la note sans contexte de branche
 * authentifié. La branche est inférée depuis l'order parent côté
 * controller, et les agrégats analytiques côté admin filtrent
 * explicitement par branch_id.
 *
 * @property int    $id
 * @property int    $order_id
 * @property string $order_type
 * @property int    $branch_id
 * @property int    $rating
 * @property ?string $comment
 * @property string $source_surface
 */
class OrderRating extends Model
{
    use HasFactory;

    protected $table = 'order_ratings';

    protected $fillable = [
        'order_id',
        'order_type',
        'branch_id',
        'rating',
        'comment',
        'source_surface',
    ];

    protected $casts = [
        'id'        => 'integer',
        'order_id'  => 'integer',
        'branch_id' => 'integer',
        'rating'    => 'integer',
    ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeletionLog extends Model
{
    public $timestamps = false;

    protected $table = 'deletion_log';

    protected $fillable = [
        'model_type',
        'model_id',
        'actor_id',
        'actor_type',
        'reason',
        'deleted_at',
    ];

    protected $casts = [
        'deleted_at' => 'datetime',
    ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * [PRE-PROD HARDENING / SYN-2 / P0-7] Pusher event acknowledgment.
 *
 * Insert-only table — once a client confirms receipt of an envelope it
 * is never updated. Hence `$timestamps = false` (the migration does not
 * declare `updated_at`), and `created_at` is filled by the controller
 * using `now()` rather than the framework default.
 *
 * The model deliberately stays thin: this row's only job is to be
 * counted by `PusherDeliveryRatioMonitor`. No relationships are exposed
 * to keep the read path index-only.
 */
class PusherEventAck extends Model
{
    protected $table = 'pusher_event_acks';

    public $timestamps = false;

    protected $fillable = [
        'domain_event_id',
        'correlation_id',
        'branch_id',
        'channel',
        'event_name',
        'received_at',
        'client_user_id',
        'client_ip',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'received_at'   => 'datetime',
        'created_at'    => 'datetime',
        'domain_event_id' => 'integer',
        'branch_id'     => 'integer',
        'client_user_id' => 'integer',
    ];
}

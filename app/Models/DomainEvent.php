<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DomainEvent extends Model
{
    protected $table = 'domain_events';

    protected $fillable = [
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'branch_id',
        'payload',
        'channel',
        'broadcast_as',
        'correlation_id',
        'idempotency_key',
        'occurred_at',
        'dispatched_at',
        'broadcast_at',
        'attempts',
        'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'broadcast_at' => 'datetime',
    ];

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('dispatched_at');
    }

    public function scopeStale(Builder $query, int $minutes = 2): Builder
    {
        return $query->pending()
            ->where('created_at', '<', now()->subMinutes($minutes));
    }

    public function scopeFailed(Builder $query, int $maxAttempts = 4): Builder
    {
        return $query->pending()
            ->where('attempts', '>=', $maxAttempts);
    }
}

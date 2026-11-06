<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;

/** [CAYENNE HYGIÈNE 2026-10-18] Journal des alertes envoyées, en ajout seul : jamais modifié ni supprimé. */
class HygieneAlertDispatch extends Model
{
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_TEST = 'test';

    protected $table = 'hygiene_alert_dispatches';

    protected $fillable = ['branch_id', 'day', 'reminder_key', 'domain', 'level', 'text', 'channel', 'recipient', 'status', 'error', 'sent_at'];

    protected $casts = ['id' => 'integer', 'branch_id' => 'integer', 'day' => 'date:Y-m-d', 'sent_at' => 'datetime'];

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }
}

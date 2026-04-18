<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * [POS-9.4.3 / POS-GA-F-04] INSERT-only fiscal audit log.
 *
 * The DB enforces immutability via triggers (see the migration). The
 * model adds a defence-in-depth layer: any attempt to update or delete
 * a persisted row through Eloquent is rejected before the query is
 * even sent to the DB.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'branch_id',
        'user_id',
        'action',
        'resource',
        'resource_id',
        'payload',
        'prev_hash',
        'current_hash',
        'ip',
        'user_agent',
        'session_id',
    ];

    protected $casts = [
        'branch_id'   => 'integer',
        'user_id'     => 'integer',
        'resource_id' => 'integer',
        'payload'     => 'array',
        'created_at'  => 'datetime',
    ];

    protected static function booted(): void
    {
        // Belt-and-braces: match the DB trigger at the model layer.
        static::updating(function (): void {
            throw new RuntimeException(
                'AuditLog is INSERT-only (NF525 / POS-9.4.3). '
                . 'Never call ->save() / ->update() on a persisted row.'
            );
        });

        static::deleting(function (): void {
            throw new RuntimeException(
                'AuditLog is INSERT-only (NF525 / POS-9.4.3). '
                . 'Never call ->delete() on an audit row.'
            );
        });
    }
}

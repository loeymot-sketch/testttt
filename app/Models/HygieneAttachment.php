<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;

/** [CAYENNE HYGIÈNE 2026-10-17] Photo jointe à une ligne de journal (BL, étiquette, bandelette…). Servie derrière authentification. */
class HygieneAttachment extends Model
{
    protected $table = 'hygiene_attachments';

    protected $fillable = ['branch_id', 'kind', 'path', 'mime', 'bytes', 'width', 'height', 'sha256', 'uploaded_by'];

    protected $casts = ['id' => 'integer', 'branch_id' => 'integer', 'bytes' => 'integer', 'width' => 'integer', 'height' => 'integer', 'uploaded_by' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }
}

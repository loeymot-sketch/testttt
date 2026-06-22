<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Printer extends Model
{
    use HasFactory;

    protected $table = 'printers';

    protected $fillable = [
        'branch_id',
        'name',
        'type',
        'host',
        'port',
        'station',
        'width_chars',
        'status',
        'options',
    ];

    protected $casts = [
        'id' => 'integer',
        'branch_id' => 'integer',
        'port' => 'integer',
        'width_chars' => 'integer',
        'status' => 'integer',
        'options' => 'array',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::addGlobalScope(new BranchScope());
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}

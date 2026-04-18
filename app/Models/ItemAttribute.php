<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItemAttribute extends Model
{
    use HasFactory;

    protected $table = "item_attributes";
    protected $fillable = ['name', 'role', 'status'];
    protected $casts = [
        'id'     => 'integer',
        'name'   => 'string',
        'role'   => 'string',
        'status' => 'integer',
    ];

    public function scopeWithRole($query, $role)
    {
        return $query->where('role', $role);
    }
}

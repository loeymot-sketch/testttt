<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItemAttribute extends Model
{
    use HasFactory;

    protected $table = "item_attributes";
    protected $fillable = ['name', 'status', 'min_select', 'max_select', 'allow_repeat'];
    protected $casts = [
        'id'           => 'integer',
        'name'         => 'string',
        'status'       => 'integer',
        'min_select'   => 'integer',
        'max_select'   => 'integer',
        'allow_repeat' => 'boolean',
    ];
}

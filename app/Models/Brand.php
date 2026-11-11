<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'category',
        'is_partner',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_partner' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}

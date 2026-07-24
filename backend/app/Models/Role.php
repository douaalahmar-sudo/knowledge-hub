<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = ['name', 'permissions_json'];

    protected $casts = [
        'permissions_json' => 'array',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
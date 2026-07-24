<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Procedure extends Model
{
    use HasFactory, BelongsToTenant; // <-- Added trait here

    protected $fillable = [
        'reference',
        'name',
        'module',
        'status',
        'tenant_id'
    ];
}
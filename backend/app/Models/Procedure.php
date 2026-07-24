<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Procedure extends Model
{
    use HasFactory, BelongsToTenant; 

   protected $fillable = [
    'title',
    'content',
    'version',
    'status',
    'process_owner_id',
    'tenant_id',
];
    ];
}
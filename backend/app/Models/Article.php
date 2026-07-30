<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Article extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    // UUID primary key (see the create_articles_table migration).
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'filiale_id',
        'author_id',
        'title',
        'slug',
        'summary',
        'content',
        'category',
        'tags',
        'status',
        'published_at',
        'cover_image_url',
        'attachments',
        'reading_time_minutes',
    ];

    protected $casts = [
        'tags'         => 'array',
        'attachments'  => 'array',
        'published_at' => 'datetime',
    ];

    /**
     * Resolve route-model bindings by slug (the frontend reads/edits by slug).
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Author of the article.
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Owning filiale. Reads are already constrained by the RLS policy on this
     * table; the relation exists for writes and for eager loading.
     */
    public function filiale(): BelongsTo
    {
        return $this->belongsTo(Filiale::class);
    }
}

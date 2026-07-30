<?php

namespace App\Models;

use App\Enums\ArticleCriticite;
use App\Enums\ArticleStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Article extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'filiale_id',
        'title',
        'slug',
        'content_summary',
        'tags_metier',
        'criticite',
        'status',
        'format_pdf_drive_id',
        'format_infographie_drive_id',
        'format_video_drive_id',
        'version',
        'is_active_version',
        'parent_article_id',
        'author_id',
        'validated_by_metier_id',
        'validated_by_qualite_id',
        'data_owner_id',
        'published_at',
    ];

    protected $casts = [
        'tags_metier' => 'array',
        'criticite' => ArticleCriticite::class,
        'status' => ArticleStatus::class,
        'is_active_version' => 'boolean',
        'published_at' => 'datetime',
    ];

    /**
     * Owning filiale. Reads are already constrained by the RLS policy on this
     * table; the relation exists for writes and for eager loading.
     */
    public function filiale(): BelongsTo
    {
        return $this->belongsTo(Filiale::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * The user accountable for this article's data (RGPD/records-management
     * sense), distinct from whoever wrote it.
     */
    public function dataOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'data_owner_id');
    }

    public function validatedByMetier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by_metier_id');
    }

    public function validatedByQualite(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by_qualite_id');
    }

    /**
     * The version this one was published as a revision of. Self-referencing
     * FK, not a pivot table — each article points at exactly one predecessor.
     */
    public function parentArticle(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'parent_article_id');
    }

    /**
     * Every article that names this one as its predecessor — the rest of this
     * article's version history.
     */
    public function childVersions(): HasMany
    {
        return $this->hasMany(Article::class, 'parent_article_id');
    }
}

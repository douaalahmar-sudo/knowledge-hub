<?php

namespace App\Models;

use App\Enums\AlertStatus;
use App\Enums\ArticleCriticite;
use App\Enums\ArticleStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
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

    // ---------------------------------------------------------------- alerts

    /** Every discrepancy reported against this article (§7.2). */
    public function alerts(): HasMany
    {
        return $this->hasMany(ArticleAlert::class);
    }

    /**
     * Only the alerts a Process Owner has taken but not yet closed — the exact
     * set §7.3 Niveau 2's "révision opérationnelle" banner keys on. Exposed as
     * a relationship rather than a scope so callers can `withCount()` it and
     * resolve a whole list in one query.
     */
    public function alertsEnCours(): HasMany
    {
        return $this->alerts()->where('status', AlertStatus::EnCours->value);
    }

    /**
     * §7.3 Niveau 2: whether to show "Procédure en cours de révision
     * opérationnelle" on this article.
     *
     * Reads the eager-loaded `alerts_en_cours_count` when it is there, which is
     * why ArticleController always calls withCount('alertsEnCours') — a list of
     * N articles then costs one extra query, not N. The exists() fallback only
     * exists so a model built outside those endpoints still reports the truth
     * instead of silently claiming `false`; it is a correctness net, not the
     * intended path.
     */
    protected function isUnderRevision(): Attribute
    {
        return Attribute::get(function (): bool {
            if (array_key_exists('alerts_en_cours_count', $this->attributes)) {
                return (int) $this->attributes['alerts_en_cours_count'] > 0;
            }

            return $this->alertsEnCours()->exists();
        });
    }

    /**
     * Serialized on every article response so the frontend banner needs no
     * second request. Snake_case in JSON: `is_under_revision`.
     */
    protected $appends = ['is_under_revision'];
}

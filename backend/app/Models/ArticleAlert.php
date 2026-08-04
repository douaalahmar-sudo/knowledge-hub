<?php

namespace App\Models;

use App\Enums\AlertCriticite;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A collaborator's report of a discrepancy in an article (§7.2/§7.3).
 *
 * Named ArticleAlert rather than anything Kaizen-shaped on purpose: the legacy
 * App\Models\KaizenReport still exists against `kaizen_reports`, and this
 * module is deliberately built alongside it, not on it.
 */
class ArticleAlert extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * `status`, `taken_by`, `acknowledged_at` and `closed_at` are absent by
     * design: every one of them is set by a workflow transition in
     * ArticleAlertController, never by client input. Same reasoning as
     * StoreArticleRequest leaving `status` out of the article payload.
     */
    protected $fillable = [
        'filiale_id',
        'article_id',
        'reported_by',
        'type',
        'criticite',
        'description',
    ];

    protected $casts = [
        'type' => AlertType::class,
        'criticite' => AlertCriticite::class,
        'status' => AlertStatus::class,
        'acknowledged_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /**
     * Owning filiale. Reads are already constrained by the RLS policy on this
     * table; the relation exists for writes and for eager loading.
     */
    public function filiale(): BelongsTo
    {
        return $this->belongsTo(Filiale::class);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * The collaborator who raised the alert (§7.2: "tout collaborateur").
     *
     * SERIALIZATION NOTE — this relation and its foreign key collide in JSON.
     * Eloquent serializes a loaded relation under the snake_case of its method
     * name, which for `reportedBy` is `reported_by` — exactly the FK column's
     * name. So in any response where the relation is loaded, `reported_by` is
     * the nested user object, not the bigint; the id is still there as
     * `reported_by.id`. Article's `author()` has no such clash only because
     * its column is `author_id`.
     *
     * That is safe here because ArticleAlertController loads these relations on
     * every response it returns (see withRelations()), so the shape never
     * varies between endpoints. It would stop being safe the moment some other
     * caller returned a bare ArticleAlert — then the same key would be an int.
     * Renaming these methods (`reporter()`/`handler()`) would remove the
     * ambiguity at the cost of the names asked for in the spec.
     */
    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /**
     * The Process Owner who acknowledged it, moving it to en_cours. Null while
     * the alert is still ouverte.
     *
     * Same `taken_by` key collision as reportedBy() above.
     */
    public function takenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taken_by');
    }
}

<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Procedure extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'reference_code',
        'name',
        'module',
        'category',
        'department',
        'description',
        'status',
        'pdf_path',
        'video_path',
        'infographic_path',
        'version',
        'is_active',
        'created_by',
        'current_version_id',
        'tenant_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Serialised on every response so the Angular tab viewer can bind straight
     * to absolute asset URLs without knowing the storage layout.
     */
    protected $appends = ['triptych_urls'];

    /**
     * Public URLs for the three triptych assets, serialised alongside the
     * stored paths so clients never have to reconstruct /storage/… by hand.
     *
     * @return array<string, string|null>
     */
    public function getTriptychUrlsAttribute(): array
    {
        return [
            'pdf' => $this->pdf_path ? Storage::disk('public')->url($this->pdf_path) : null,
            'video' => $this->video_path ? Storage::disk('public')->url($this->video_path) : null,
            'infographic' => $this->infographic_path ? Storage::disk('public')->url($this->infographic_path) : null,
        ];
    }

    /**
     * The user who created the procedure.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The owning tenant/store.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Full version history. ProcedureController::show() already eager-loads
     * this relation, so it has to exist for GET /procedures/{id} to resolve.
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ProcedureVersion::class);
    }

    /**
     * The currently active version of the procedure.
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(ProcedureVersion::class, 'current_version_id');
    }
}

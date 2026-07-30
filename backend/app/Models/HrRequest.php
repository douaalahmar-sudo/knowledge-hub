<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class HrRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'filiale_id',
        'user_id',
        'type',
        'title',
        'description',
        'start_date',
        'end_date',
        'attachments',
        'status',
        'admin_note',
        'pdf_path',
    ];

    protected $casts = [
        'attachments' => 'array',
        'start_date'  => 'date:Y-m-d',
        'end_date'    => 'date:Y-m-d',
    ];

    // Expose computed fields the Angular frontend expects.
    protected $appends = ['pdf_url', 'user_name'];

    /**
     * The employee who submitted the request.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The owning filiale.
     */
    public function filiale(): BelongsTo
    {
        return $this->belongsTo(Filiale::class);
    }

    /**
     * Public download URL for the HR-generated PDF (null when not yet uploaded).
     */
    public function getPdfUrlAttribute(): ?string
    {
        return $this->pdf_path ? Storage::disk('public')->url($this->pdf_path) : null;
    }

    /**
     * Convenience name of the requesting employee for admin queue listings.
     */
    public function getUserNameAttribute(): ?string
    {
        return $this->user?->name;
    }
}

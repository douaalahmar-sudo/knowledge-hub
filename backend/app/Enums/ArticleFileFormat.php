<?php

namespace App\Enums;

use Illuminate\Validation\Rules\File;

/**
 * The three Drive-backed file slots on an article (see the format_*_drive_id
 * columns). Centralised here so ArticleController and UploadArticleFileRequest
 * share one definition of "what pdf/infographie/video mean" instead of two.
 */
enum ArticleFileFormat: string
{
    case Pdf = 'pdf';
    case Infographie = 'infographie';
    case Video = 'video';

    public function column(): string
    {
        return match ($this) {
            self::Pdf => 'format_pdf_drive_id',
            self::Infographie => 'format_infographie_drive_id',
            self::Video => 'format_video_drive_id',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pdf => 'PDF',
            self::Infographie => 'Infographie',
            self::Video => 'Vidéo',
        };
    }

    /**
     * Same size caps and MIME/extension whitelists as the equivalent
     * procedure-triptych slots in TriptychUploadController, for consistency
     * across the two features.
     *
     * Video duration (60-90s per spec) is deliberately NOT enforced here —
     * that needs a media-inspection library (e.g. reading container metadata),
     * which this project doesn't have yet. Only file size is capped; a
     * 10-minute video under the size cap will be accepted.
     */
    public function validationRule(): File
    {
        return match ($this) {
            self::Pdf => File::types(['pdf'])->extensions(['pdf'])->max(20 * 1024),
            self::Infographie => File::types(['png', 'jpg', 'jpeg', 'webp'])->extensions(['png', 'jpg', 'jpeg', 'webp'])->max(10 * 1024),
            self::Video => File::types(['mp4', 'webm'])->extensions(['mp4', 'webm'])->max(100 * 1024),
        };
    }

    /**
     * Only used if Drive's own stored metadata can't be read — the real
     * Content-Type normally comes from GoogleDriveService::getMimeType(),
     * since "infographie" alone accepts several actual image types and
     * guessing wrong here would mislabel the response.
     */
    public function fallbackMimeType(): string
    {
        return match ($this) {
            self::Pdf => 'application/pdf',
            self::Infographie => 'image/jpeg',
            self::Video => 'video/mp4',
        };
    }
}

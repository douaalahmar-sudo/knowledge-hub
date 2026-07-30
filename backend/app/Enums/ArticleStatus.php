<?php

namespace App\Enums;

/**
 * Workflow states for `articles.status` (see the articles migration).
 *
 * Draft -> pending_metier -> pending_qualite -> published -> archived is a
 * strict, one-directional chain: canTransitionTo() rejects anything else,
 * including skipping straight from draft to published.
 */
enum ArticleStatus: string
{
    case Draft = 'draft';
    case PendingMetier = 'pending_metier';
    case PendingQualite = 'pending_qualite';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::PendingMetier => 'En attente — validation métier',
            self::PendingQualite => 'En attente — validation qualité',
            self::Published => 'Publié',
            self::Archived => 'Archivé',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft => $next === self::PendingMetier,
            self::PendingMetier => $next === self::PendingQualite,
            self::PendingQualite => $next === self::Published,
            self::Published => $next === self::Archived,
            self::Archived => false,
        };
    }
}

<?php

namespace App\Enums;

/**
 * Values for `articles.criticite` (see the articles migration).
 */
enum ArticleCriticite: string
{
    case GoldenRule = 'golden_rule';
    case Note = 'note';

    public function label(): string
    {
        return match ($this) {
            self::GoldenRule => 'Règle d\'or',
            self::Note => 'Note',
        };
    }
}

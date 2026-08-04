<?php

namespace App\Enums;

/**
 * Values for `article_alerts.type` (cahier des charges §7.2) — what kind of
 * discrepancy the collaborator is reporting about an article.
 *
 * Deliberately unrelated to the legacy `kaizen_reports.type` column and its
 * UI: this is the new alert module, built alongside that code rather than on
 * top of it, exactly as the article workflow was built alongside the original
 * knowledge-base articles. Same reasoning as ArticleStatus vs the old
 * article status strings.
 */
enum AlertType: string
{
    case Obsolescence = 'obsolescence';
    case Erreur = 'erreur';
    case Amelioration = 'amelioration';

    public function label(): string
    {
        return match ($this) {
            self::Obsolescence => 'Obsolescence',
            self::Erreur => 'Erreur métier',
            self::Amelioration => 'Amélioration',
        };
    }
}

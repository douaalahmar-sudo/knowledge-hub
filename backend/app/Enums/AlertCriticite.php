<?php

namespace App\Enums;

/**
 * Values for `article_alerts.criticite` (cahier des charges §7.2) — how badly
 * the reported discrepancy affects the article.
 *
 * Same shape as ArticleCriticite, but a distinct three-level scale and a
 * separate enum on purpose: an article's own criticité (règle d'or vs note)
 * says how important the document is, while this says how urgent one report
 * about it is. Merging them would conflate the two.
 */
enum AlertCriticite: string
{
    case Faible = 'faible';
    case Moyenne = 'moyenne';
    case Critique = 'critique';

    public function label(): string
    {
        return match ($this) {
            self::Faible => 'Faible',
            self::Moyenne => 'Moyenne',
            self::Critique => 'Critique',
        };
    }
}

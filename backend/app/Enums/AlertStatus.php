<?php

namespace App\Enums;

/**
 * Lifecycle states for `article_alerts.status` (cahier des charges §7.3).
 *
 * ouverte -> en_cours -> cloturee is a strict, one-directional chain, mirroring
 * ArticleStatus::canTransitionTo(): a report is filed (ouverte), the Process
 * Owner takes it (en_cours, which is what raises the "révision opérationnelle"
 * banner in §7.3 Niveau 2), and is finally closed (cloturee).
 *
 * Nothing may skip a step — closing an alert nobody acknowledged would leave
 * `taken_by`/`acknowledged_at` null and lose the audit trail of who handled
 * it — and `cloturee` is terminal: reopening is a new report, not a
 * transition, so the original account of the discrepancy stays intact.
 */
enum AlertStatus: string
{
    case Ouverte = 'ouverte';
    case EnCours = 'en_cours';
    case Cloturee = 'cloturee';

    public function label(): string
    {
        return match ($this) {
            self::Ouverte => 'Ouverte',
            self::EnCours => 'En cours de traitement',
            self::Cloturee => 'Clôturée',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Ouverte => $next === self::EnCours,
            self::EnCours => $next === self::Cloturee,
            self::Cloturee => false,
        };
    }
}

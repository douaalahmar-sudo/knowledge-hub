<?php

namespace App\Enums;

/**
 * The vocabulary of `audit_logs.action` — cahier des charges §10.4.
 *
 * The column is a plain string, not an enum type, so a new action never needs a
 * migration; this enum is the list of the ones the application writes today,
 * and exists so call sites cannot drift ('article.viewed' vs 'article.view')
 * and so the read endpoint can validate a filter against something real.
 *
 * Naming is `resource.past_tense_event`. Transitions are recorded one action
 * per transition rather than a single 'article.status_changed' carrying the
 * pair in metadata: "who published this" is the question the audit trail is
 * asked, and answering it should not require filtering on a JSON field. The
 * old/new statuses are still in the metadata for every one of them.
 */
enum AuditAction: string
{
    // ---------------------------------------------------------- consultation
    // §10.4: "toutes les actions de consultation sont consignées".

    /** GET /v1/articles/{article} — the article record itself was read. */
    case ArticleViewed = 'article.viewed';

    /**
     * GET /v1/articles/{article}/files/{format} — the document was streamed to
     * a reader. This is the event §10.3's watermark exists alongside: the row
     * records who, from where and when, for the same consultation the overlay
     * stamps on screen.
     */
    case ArticleFileViewed = 'article.file_viewed';

    /**
     * A consultation that was refused — a lecteur asking for a draft, a
     * superseded version, or a format that was never uploaded. Logged as
     * deliberately as a successful one: a refused read is the more interesting
     * half of a security log, and it is what a future §10.5 anomaly detector
     * would look at first.
     */
    case ArticleAccessDenied = 'article.access_denied';

    // -------------------------------------------------------------- workflow

    case ArticleSubmitted = 'article.submitted';

    case ArticleValidatedMetier = 'article.validated_metier';

    case ArticleValidatedQualite = 'article.validated_qualite';

    case ArticleRejected = 'article.rejected';

    /**
     * The "Zéro Doublon" retirement: publishing a new version archives the one
     * it supersedes. §4.2 requires archived versions stay "traçables dans les
     * logs d'audit", and this row — one per superseded article — is that trace.
     */
    case ArticleArchived = 'article.archived';

    // --------------------------------------------------------------- printing

    /**
     * §11.1: a print was authorized for one document, by name, for a few
     * minutes. Printing is disabled Hub-wide by default (§11), so this is the
     * event that opens the exception — and the record that gives "COPIE TRACÉE"
     * something to trace.
     */
    case ArticlePrintAuthorized = 'article.print_authorized';

    /**
     * The authorized print was actually sent to the browser's print dialogue.
     * Separate from the authorization: a grant that is issued and never used is
     * a different fact from a document that left the building on paper, and
     * only the second one puts a copy in the world.
     */
    case ArticlePrinted = 'article.printed';

    // -------------------------------------------------------------- security

    /**
     * §10.4's automated alert fired — an abnormal volume of consultations in a
     * reduced interval. The alert row in `security_alerts` is the durable
     * record; this entry puts it on the same timeline as the consultations that
     * caused it, so the trail reads in one pass.
     */
    case SecurityAlertRaised = 'security_alert.raised';

    // ----------------------------------------------------------- the log itself

    /**
     * GET /v1/audit-logs. Reading a security log is itself a privileged action;
     * a trail that cannot say who consulted it has a blind spot exactly where
     * it matters most.
     */
    case AuditLogViewed = 'audit_log.viewed';

    public function label(): string
    {
        return match ($this) {
            self::ArticleViewed => 'Consultation d\'un article',
            self::ArticleFileViewed => 'Consultation d\'un document',
            self::ArticleAccessDenied => 'Consultation refusée',
            self::ArticleSubmitted => 'Soumission pour validation',
            self::ArticleValidatedMetier => 'Validation métier',
            self::ArticleValidatedQualite => 'Validation qualité',
            self::ArticleRejected => 'Rejet',
            self::ArticleArchived => 'Archivage d\'une version',
            self::ArticlePrintAuthorized => 'Autorisation d\'impression',
            self::ArticlePrinted => 'Impression effectuée',
            self::SecurityAlertRaised => 'Alerte de sécurité levée',
            self::AuditLogViewed => 'Consultation du journal d\'audit',
        };
    }
}

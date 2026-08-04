<?php

namespace App\Enums;

/**
 * Values for `security_alerts.alert_type` (cahier des charges §10.4).
 *
 * One case today. It is an enum rather than a bare string for the same reason
 * AuditAction is: the read endpoint validates a filter against it, and a typo
 * in an alert type is the kind of mistake that surfaces as "no alerts" — the
 * most dangerous wrong answer this module can give.
 *
 * Distinct from App\Enums\AlertType, which is the §7.2 *business* signalement
 * (a collaborator reporting that a document is wrong). These are security
 * events raised by the system about a person's behaviour, they go to the DSI
 * rather than to a Process Owner, and conflating the two vocabularies would put
 * "the procedure is out of date" and "this account may be exfiltrating data" in
 * the same queue.
 */
enum SecurityAlertType: string
{
    /**
     * §10.4's "aspiration de données": an abnormal volume of documents opened
     * in a reduced interval. See App\Services\SecurityAnomalyDetector.
     */
    case ExcessiveDocumentAccess = 'excessive_document_access';

    public function label(): string
    {
        return match ($this) {
            self::ExcessiveDocumentAccess => 'Volume anormal de consultations (aspiration de données)',
        };
    }
}

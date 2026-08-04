<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Data-exfiltration detection (cahier des charges §10.4)
    |--------------------------------------------------------------------------
    |
    | "Le système doit lever une alerte de sécurité automatisée auprès de la DSI
    | en cas de détection d'aspiration de données, caractérisée par l'ouverture
    | d'un volume anormal de documents dans un intervalle réduit (ex: plus de 30
    | procédures en moins de 2 minutes)."
    |
    | The numbers in the spec are an example ("ex:"), not a requirement, and the
    | right values depend on how the Hub is actually used — a warehouse audit
    | week looks nothing like a normal Tuesday. They are therefore configuration
    | and not constants, so tuning them is an .env change and a restart rather
    | than a deployment.
    |
    */

    'anomaly' => [

        /*
         * Consultations within the window that must be EXCEEDED before an alert
         * is raised. The spec says "plus de 30", so 30 is the last acceptable
         * count and the 31st consultation is what trips it. Set deliberately
         * strictly-greater rather than >= so the configured number reads the
         * same way the clause does.
         */
        'threshold' => (int) env('SECURITY_ANOMALY_THRESHOLD', 30),

        /*
         * Length of the rolling window, in seconds. Rolling, not calendar: the
         * window is always "the last N seconds from now", so 31 documents
         * opened across a minute boundary trips it exactly as 31 opened inside
         * one minute would. A calendar bucket would let an aspiration hide by
         * straddling the boundary.
         */
        'window_seconds' => (int) env('SECURITY_ANOMALY_WINDOW_SECONDS', 120),

        /*
         * Kill switch. Detection runs inline on every document consultation
         * (see App\Services\SecurityAnomalyDetector for why it is not a
         * scheduled job), so there has to be a way to turn it off without a
         * code change if it ever misbehaves under load.
         */
        'enabled' => filter_var(env('SECURITY_ANOMALY_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    ],

    /*
    |--------------------------------------------------------------------------
    | Authorized printing (cahier des charges §11.1)
    |--------------------------------------------------------------------------
    |
    | §11 disables printing across the whole Hub; §11.1 describes what an
    | *authorized* print must carry. A grant is the exception to that default,
    | so it is deliberately short-lived and single-use: the authorization is an
    | act ("print this document, now"), not a standing permission.
    |
    */

    'print' => [

        /*
         * How long a grant stays valid. Long enough to reach the print dialogue
         * and pick a printer; far too short to be worth keeping. It is not a
         * security boundary on its own — the paper outlives it, which is why
         * §11.1's banner and the 24-hour notice exist — but it keeps an unused
         * grant from sitting in a tab for the rest of the day.
         */
        'grant_ttl_seconds' => (int) env('PRINT_GRANT_TTL_SECONDS', 300),

    ],

];

<?php

namespace App\Providers;

use App\Models\User;
use App\Models\KaizenSignal;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // 1. Admin Gate
        Gate::define('admin-access', function (User $user) {
            return $user->role?->name === 'admin';
        });

        // 2. Manage Procedures (Admin or Process Owner)
        Gate::define('manage-procedures', function (User $user) {
            return in_array($user->role?->name, ['admin', 'process_owner']);
        });

        // 3. Submit Kaizen Signals (Admin, Process Owner, or Operator)
        Gate::define('submit-kaizen', function (User $user) {
            return in_array($user->role?->name, ['admin', 'process_owner', 'operator']);
        });

        // 4. Resolve Kaizen Signals (Admin or assigned Process Owner)
        Gate::define('resolve-kaizen', function (User $user, KaizenSignal $kaizen) {
            if ($user->role?->name === 'admin') {
                return true;
            }
            return $user->role?->name === 'process_owner' && $kaizen->assigned_to === $user->id;
        });

        // 5. Create Articles — built on the new access_role enum
        // (App\Enums\UserRole), not the role_id gates above. Enforced in
        // ArticleController::store() and, alongside the author/draft check,
        // in UpdateArticleRequest::authorize().
        //
        // Response::deny() rather than a plain `false`: without it, a denial
        // here falls back to Laravel's generic "This action is unauthorized.",
        // which is in English and gets surfaced as-is by the Angular client's
        // error-message mapping (ArticleApiService) — the one 403 reason on
        // this endpoint that wouldn't otherwise carry an accurate French
        // message the way update()/reject()/uploadFile()'s do.
        Gate::define('create-articles', function (User $user) {
            return $user->hasRole(['redacteur', 'admin'])
                ?: Response::deny('Seul un rédacteur ou un administrateur peut créer un article.');
        });

        // 6/7. Article workflow validation stages — enforced in
        // ArticleController::validateMetier()/validateQualite(), and reused by
        // reject() to decide which role may reject at the article's current
        // stage (see RejectArticleRequest). Same Response::deny() reasoning as
        // create-articles above.
        Gate::define('validate-metier', function (User $user) {
            return $user->hasRole(['responsable_departement', 'admin'])
                ?: Response::deny('Seul un responsable de département ou un administrateur peut valider cette étape.');
        });

        Gate::define('validate-qualite', function (User $user) {
            return $user->hasRole(['qualite', 'admin'])
                ?: Response::deny('Seul un membre qualité ou un administrateur peut valider cette étape.');
        });

        // 8. Article alerts (cahier des charges §7.2/§7.3).
        //
        // Reporting is deliberately NOT gated: §7.2 says "tout collaborateur"
        // may signal a discrepancy, so the only requirement is authentication.
        // There is no `report-article-alerts` Gate on purpose — adding one
        // would be the place to narrow that later.
        //
        // Processing is the Process Owner's job — the "Gardien du Temple" of
        // §6.1, which is `data_owner` in App\Enums\UserRole. Same access_role
        // basis and same Response::deny() reasoning as the three Gates above,
        // so the Angular client surfaces an accurate French message instead of
        // Laravel's generic English default.
        Gate::define('process-article-alerts', function (User $user) {
            return $user->hasRole(['data_owner', 'admin'])
                ?: Response::deny('Seul un propriétaire des données ou un administrateur peut traiter un signalement.');
        });

        // 9. Audit log (cahier des charges §10.4).
        //
        // The journal is a security log, not user-facing data: an ordinary
        // reader must not be able to see who consulted what. admin and
        // data_owner only — the latter because §6.1 makes the "Gardien du
        // Temple" accountable for their filiale's documents, which is not
        // exercisable without seeing how they are being consulted.
        //
        // This Gate guards the endpoint. It is NOT the only thing standing
        // between a lecteur and the table: the RLS policy on `audit_logs`
        // grants SELECT to these same two roles and to nobody else, so the
        // rows are unreadable even to code that forgets to ask this question.
        // The two must be kept in step — see the migration.
        // 11. Authorized printing (§11.1).
        //
        // §11 disables printing across the whole Hub; this Gate is the only
        // thing that lifts it, and only for the act of authorizing ONE print —
        // see PrintAuthorizationController. data_owner is included because §6.1
        // makes the "Gardien du Temple" accountable for their filiale's
        // documents, and deciding that one may go to paper is that person's
        // call; routing every print through IT is the rule that gets worked
        // around instead of followed.
        Gate::define('authorize-print', function (User $user) {
            return $user->hasRole(['admin', 'data_owner'])
                ?: Response::deny('Seul un administrateur ou un propriétaire des données peut autoriser une impression.');
        });

        // 10. Security alerts (§10.4's "alerte … auprès de la DSI").
        //
        // Narrower than view-audit-logs on purpose: admin only. A data_owner is
        // accountable for their filiale's documents, which is why they can read
        // the consultation trail; an alert naming a colleague as a possible
        // exfiltration risk is a personnel matter and belongs to the DSI. The
        // RLS policy on `security_alerts` grants SELECT to `admin` alone, so
        // the two stay in step — see that migration.
        Gate::define('view-security-alerts', function (User $user) {
            return $user->hasRole(['admin'])
                ?: Response::deny('Seul un administrateur peut consulter les alertes de sécurité.');
        });

        Gate::define('view-audit-logs', function (User $user) {
            return $user->hasRole(['admin', 'data_owner'])
                ?: Response::deny('Seul un administrateur ou un propriétaire des données peut consulter le journal d\'audit.');
        });
    }
}
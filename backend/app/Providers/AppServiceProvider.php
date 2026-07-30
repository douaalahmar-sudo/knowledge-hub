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
    }
}
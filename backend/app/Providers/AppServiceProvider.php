<?php

namespace App\Providers;

use App\Models\User;
use App\Models\KaizenSignal;
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
    }
}
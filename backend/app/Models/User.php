<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'matricule',
        'store_id',
        'role_id',
        'filiale_id',
        'access_role',
    ];

    /**
     * Never serialised into a JSON response.
     *
     * Laravel's stock User model ships with this; it had been dropped here, so
     * every endpoint returning a User — AuthController's login/register/me/SSO
     * payloads and PersonnelController's directory listings — was handing the
     * bcrypt hash and the remember token to the client.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'access_role' => UserRole::class,
    ];

    /**
     * Relationship: User belongs to a Role
     */
    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Relationship: User belongs to a Filiale.
     *
     * The filiale also drives PostgreSQL Row-Level Security — see
     * App\Http\Middleware\SetTenantContext.
     */
    public function filiale()
    {
        return $this->belongsTo(Filiale::class);
    }

    /**
     * Check the user's `access_role` (App\Enums\UserRole), not the legacy
     * `role_id` relationship. Accepts either a single role or a list, as
     * either enum cases or their raw string values.
     *
     *     $user->hasRole('redacteur')
     *     $user->hasRole(UserRole::Admin)
     *     $user->hasRole(['redacteur', 'admin'])
     */
    public function hasRole(string|UserRole|array $roles): bool
    {
        if (! $this->access_role) {
            return false;
        }

        $wanted = array_map(
            fn (string|UserRole $role) => $role instanceof UserRole ? $role->value : $role,
            is_array($roles) ? $roles : [$roles]
        );

        return in_array($this->access_role->value, $wanted, true);
    }
}
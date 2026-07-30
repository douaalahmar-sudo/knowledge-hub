<?php

namespace App\Enums;

/**
 * Values for the `access_role` column (see the migration adding it).
 *
 * Deliberately separate from App\Models\Role / `role_id` — this enum backs
 * the new lightweight RBAC path used by User::hasRole() and, later, Article
 * policies; the existing `roles` table keeps driving CheckRole and the Gates
 * in AppServiceProvider. Both can be true of the same user at once.
 */
enum UserRole: string
{
    case Redacteur = 'redacteur';
    case ResponsableDepartement = 'responsable_departement';
    case Qualite = 'qualite';
    case DataOwner = 'data_owner';
    case Admin = 'admin';
    case Lecteur = 'lecteur';

    public function label(): string
    {
        return match ($this) {
            self::Redacteur => 'Rédacteur',
            self::ResponsableDepartement => 'Responsable de Département',
            self::Qualite => 'Qualité',
            self::DataOwner => 'Propriétaire des Données',
            self::Admin => 'Administrateur',
            self::Lecteur => 'Lecteur',
        };
    }
}

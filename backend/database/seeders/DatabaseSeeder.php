<?php

namespace Database\Seeders;

use App\Models\KaizenReport;
use App\Models\Procedure;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed roles, one demo login per role, and sample business data.
     *
     * Role names are snake_case to match the Gate definitions in AppServiceProvider
     * (admin / process_owner / operator ...). All demo users share the same store
     * so every role can see the seeded procedures/kaizen data.
     */
    public function run(): void
    {
        // 1. Roles (keys align with authorization gates + the RBAC matrix).
        $roles = [
            'admin'         => 'Administrateur IT / Sécurité — accès complet',
            'process_owner' => 'Propriétaire de processus — valide et publie',
            'expert_metier' => 'Expert métier — rédige les procédures & articles',
            'validator'     => 'Qualité / Conformité — approuve les documents',
            'manager'       => 'Manager magasin / rayon — supervise les équipes',
            'operator'      => 'Personnel terrain — consulte & signale (Kaizen)',
            'hr_admin'      => 'Administrateur RH — traite les demandes RH',
            'hr_user'       => 'Employé libre-service RH — soumet des demandes',
        ];
        $roleModels = [];
        foreach ($roles as $name => $description) {
            $roleModels[$name] = Role::updateOrCreate(['name' => $name], ['description' => $description]);
        }

        // 2. Tenants
        $hqTenant = Tenant::firstOrCreate(['name' => 'FLESK HQ & Services Centraux']);
        $store101 = Tenant::firstOrCreate(['name' => 'FLESK Store #101 - Tunis']);

        // 3. One demo login per role (password: password123), all in Store #101.
        $demoUsers = [
            ['email' => 'admin@flesk.com',     'name' => 'Sami Ben Ali',    'matricule' => 'KH-ADM-001', 'role' => 'admin'],
            ['email' => 'owner@flesk.com',     'name' => 'Amina Mansour',   'matricule' => 'KH-OWN-001', 'role' => 'process_owner'],
            ['email' => 'expert@flesk.com',    'name' => 'Nizar Haddad',    'matricule' => 'KH-EXP-001', 'role' => 'expert_metier'],
            ['email' => 'validator@flesk.com', 'name' => 'Leila Trabelsi',  'matricule' => 'KH-VAL-001', 'role' => 'validator'],
            ['email' => 'manager@flesk.com',   'name' => 'Karim Bouazizi',  'matricule' => 'KH-MGR-101', 'role' => 'manager'],
            ['email' => 'operator@flesk.com',  'name' => 'Karim Zouari',    'matricule' => 'KH-OPR-101', 'role' => 'operator'],
            ['email' => 'hr.admin@flesk.com',  'name' => 'Fatma Gharbi',    'matricule' => 'KH-HRA-001', 'role' => 'hr_admin'],
            ['email' => 'hr.user@flesk.com',   'name' => 'Youssef Nasri',   'matricule' => 'KH-HRU-001', 'role' => 'hr_user'],
        ];

        $users = [];
        foreach ($demoUsers as $u) {
            $users[$u['role']] = User::updateOrCreate(
                ['email' => $u['email']],
                [
                    'name'      => $u['name'],
                    'matricule' => $u['matricule'],
                    'password'  => bcrypt('password123'),
                    'tenant_id' => $store101->id,
                    'role_id'   => $roleModels[$u['role']]->id,
                ]
            );
        }

        // 4. Sample procedures (owned by the process owner, in Store #101).
        $procCaisse = Procedure::firstOrCreate(
            ['reference' => 'PR-2026-010'],
            [
                'tenant_id'  => $store101->id,
                'name'       => 'Procédure Ouverture & Fermeture de Caisse',
                'module'     => 'Operations',
                'status'     => 'Validé',
                'created_by' => $users['process_owner']->id,
            ]
        );

        Procedure::firstOrCreate(
            ['reference' => 'PR-2026-011'],
            [
                'tenant_id'  => $store101->id,
                'name'       => 'Consignes de Sécurité - Réception Marchandises',
                'module'     => 'Logistique',
                'status'     => 'En attente',
                'created_by' => $users['process_owner']->id,
            ]
        );

        // 5. Sample Kaizen report.
        KaizenReport::firstOrCreate(
            ['description' => 'Ecart de 15 min constaté lors du transfert d’ouverture de caisse.'],
            [
                'tenant_id'    => $store101->id,
                'procedure_id' => $procCaisse->id,
                'user_id'      => $users['operator']->id,
                'criticality'  => 'Moyenne',
                'status'       => 'Ouvert',
            ]
        );
    }
}

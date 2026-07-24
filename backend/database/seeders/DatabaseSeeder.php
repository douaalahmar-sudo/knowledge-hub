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
     * Seed the application's database with realistic initial data.
     */
    public function run(): void
    {
        // 1. Create Roles
        $adminRole    = Role::firstOrCreate(['name' => 'Admin']);
        $ownerRole    = Role::firstOrCreate(['name' => 'Process Owner']);
        $operatorRole = Role::firstOrCreate(['name' => 'Opérateur']);

        // 2. Create Tenants
        $hqTenant = Tenant::firstOrCreate(
            ['name' => 'FLESK HQ & Services Centraux']
        );

        $store101 = Tenant::firstOrCreate(
            ['name' => 'FLESK Store #101 - Tunis']
        );

        // 3. Create Key Users with Matricule
        $adminUser = User::firstOrCreate(
            ['email' => 'admin@flesk.com'],
            [
                'name'      => 'Sami Ben Ali',
                'matricule' => 'KH-ADM-001',
                'password'  => bcrypt('password123'),
                'tenant_id' => $hqTenant->id,
                'role_id'   => $adminRole->id,
            ]
        );

        $processOwner = User::firstOrCreate(
            ['email' => 'owner@flesk.com'],
            [
                'name'      => 'Amina Mansour',
                'matricule' => 'KH-OWN-001',
                'password'  => bcrypt('password123'),
                'tenant_id' => $hqTenant->id,
                'role_id'   => $ownerRole->id,
            ]
        );

        $operator = User::firstOrCreate(
            ['email' => 'operator@store101.flesk.com'],
            [
                'name'      => 'Karim Zouari',
                'matricule' => 'KH-OPR-101',
                'password'  => bcrypt('password123'),
                'tenant_id' => $store101->id,
                'role_id'   => $operatorRole->id,
            ]
        );

        // 4. Create Sample Procedures
        $procCaisse = Procedure::firstOrCreate(
            ['reference' => 'PR-2026-010'],
            [
                'tenant_id'  => $store101->id,
                'name'       => 'Procédure Ouverture & Fermeture de Caisse',
                'module'     => 'Operations',
                'status'     => 'Validé',
                'created_by' => $processOwner->id,
            ]
        );

        $procSecurite = Procedure::firstOrCreate(
            ['reference' => 'PR-2026-011'],
            [
                'tenant_id'  => $store101->id,
                'name'       => 'Consignes de Sécurité - Réception Marchandises',
                'module'     => 'Logistique',
                'status'     => 'En attente',
                'created_by' => $processOwner->id,
            ]
        );

        // 5. Create Kaizen Operational Signalments
        KaizenReport::firstOrCreate(
            ['description' => 'Ecart de 15 min constaté lors du transfert d’ouverture de caisse.'],
            [
                'tenant_id'   => $store101->id,
                'procedure_id'=> $procCaisse->id,
                'user_id'     => $operator->id,
                'criticality' => 'Moyenne',
                'status'      => 'Ouvert',
            ]
        );
    }
}
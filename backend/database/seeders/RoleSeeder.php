<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    /**
     * Seed the application's core roles.
     */
    public function run(): void
    {
        $roles = [
            [
                'name' => 'admin',
                'description' => 'Full filiale control and system administration',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'process_owner',
                'description' => 'Manages procedures, resolves Kaizen signals, and publishes SOPs',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'operator',
                'description' => 'Executes SOPs in the field and submits Kaizen signals/gaps',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'auditor',
                'description' => 'Read-only access to procedures, Kaizen history, and compliance logs',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('roles')->insertOrIgnore($roles);
    }
}
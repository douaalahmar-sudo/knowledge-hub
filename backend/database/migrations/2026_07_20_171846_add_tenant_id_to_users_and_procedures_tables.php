<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The tenant_id columns are already added by an earlier migration.
        // Keep this migration as a no-op to avoid duplicate-column failures on fresh installs.
    }

    public function down(): void
    {
        // No-op for the same reason as up().
    }
};
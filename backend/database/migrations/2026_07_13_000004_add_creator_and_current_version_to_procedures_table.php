<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The procedures table already defines these columns in the base migration.
        // Keep this migration as a no-op so fresh installs do not try to add duplicates.
    }

    public function down(): void
    {
        // No-op for the same reason as up().
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fields the triptych creation form (Task #16) collects alongside the three
     * assets. `module` already existed and stays as the coarse grouping; these
     * add the finer Category / Department / Description taxonomy.
     */
    public function up(): void
    {
        Schema::table('procedures', function (Blueprint $table) {
            $table->string('category')->nullable()->after('module');
            $table->string('department')->nullable()->after('category');
            $table->text('description')->nullable()->after('department');
        });
    }

    public function down(): void
    {
        Schema::table('procedures', function (Blueprint $table) {
            $table->dropColumn(['category', 'department', 'description']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The base migration already ships a globally unique `reference` column.
        // Rename it rather than adding a second reference column, so there is
        // exactly one identifier enforcing the "Zéro doublon" rule.
        // Postgres carries the existing UNIQUE constraint across the rename.
        if (Schema::hasColumn('procedures', 'reference') && ! Schema::hasColumn('procedures', 'reference_code')) {
            Schema::table('procedures', function (Blueprint $table) {
                $table->renameColumn('reference', 'reference_code');
            });
        } elseif (! Schema::hasColumn('procedures', 'reference_code')) {
            Schema::table('procedures', function (Blueprint $table) {
                $table->string('reference_code', 50)->unique();
            });
        }

        Schema::table('procedures', function (Blueprint $table) {
            // Triptych assets: paths relative to the `public` disk root
            // (storage/app/public), e.g. "triptych/pdf/ab12….pdf".
            $table->string('pdf_path')->nullable()->after('module');
            $table->string('video_path')->nullable()->after('pdf_path');
            $table->string('infographic_path')->nullable()->after('video_path');

            // Version tracking. Kept as a string so semantic labels like
            // "1.0" / "2.1" survive a round trip — procedure_versions still
            // holds the integer sequence and the full history.
            $table->string('version', 20)->default('1.0')->after('infographic_path');
            $table->boolean('is_active')->default(true)->after('version');
        });
    }

    public function down(): void
    {
        Schema::table('procedures', function (Blueprint $table) {
            $table->dropColumn([
                'pdf_path',
                'video_path',
                'infographic_path',
                'version',
                'is_active',
            ]);
        });

        if (Schema::hasColumn('procedures', 'reference_code') && ! Schema::hasColumn('procedures', 'reference')) {
            Schema::table('procedures', function (Blueprint $table) {
                $table->renameColumn('reference_code', 'reference');
            });
        }
    }
};

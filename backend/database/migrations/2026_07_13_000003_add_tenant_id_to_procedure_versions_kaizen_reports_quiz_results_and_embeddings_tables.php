<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('procedure_versions')) {
            Schema::table('procedure_versions', function (Blueprint $table) {
                if (!Schema::hasColumn('procedure_versions', 'tenant_id')) {
                    $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
                }
            });
        }

        if (Schema::hasTable('kaizen_reports')) {
            Schema::table('kaizen_reports', function (Blueprint $table) {
                if (!Schema::hasColumn('kaizen_reports', 'tenant_id')) {
                    $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
                }
            });
        }

        if (Schema::hasTable('quiz_results')) {
            Schema::table('quiz_results', function (Blueprint $table) {
                if (!Schema::hasColumn('quiz_results', 'tenant_id')) {
                    $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
                }
            });
        }

        if (Schema::hasTable('embeddings')) {
            Schema::table('embeddings', function (Blueprint $table) {
                if (!Schema::hasColumn('embeddings', 'tenant_id')) {
                    $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
                }
            });
        }

        // Cross-database compatible backfilling
        if (Schema::hasTable('procedure_versions')) {
            DB::statement('
                UPDATE procedure_versions 
                SET tenant_id = (
                    SELECT tenant_id 
                    FROM procedures 
                    WHERE procedures.id = procedure_versions.procedure_id
                ) 
                WHERE tenant_id IS NULL;
            ');
        }

        if (Schema::hasTable('kaizen_reports')) {
            DB::statement('
                UPDATE kaizen_reports 
                SET tenant_id = (
                    SELECT tenant_id 
                    FROM procedures 
                    WHERE procedures.id = kaizen_reports.procedure_id
                ) 
                WHERE tenant_id IS NULL AND procedure_id IS NOT NULL;
            ');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('procedure_versions')) {
            Schema::table('procedure_versions', function (Blueprint $table) {
                $table->dropForeign(['tenant_id']);
                $table->dropColumn('tenant_id');
            });
        }

        if (Schema::hasTable('kaizen_reports')) {
            Schema::table('kaizen_reports', function (Blueprint $table) {
                $table->dropForeign(['tenant_id']);
                $table->dropColumn('tenant_id');
            });
        }

        if (Schema::hasTable('quiz_results')) {
            Schema::table('quiz_results', function (Blueprint $table) {
                $table->dropForeign(['tenant_id']);
                $table->dropColumn('tenant_id');
            });
        }

        if (Schema::hasTable('embeddings')) {
            Schema::table('embeddings', function (Blueprint $table) {
                $table->dropForeign(['tenant_id']);
                $table->dropColumn('tenant_id');
            });
        }
    }
};
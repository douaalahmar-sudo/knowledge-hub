<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'filiale_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            // Nullable: a user can exist before being assigned to a filiale
            // (SSO provisioning, platform-level admins). RLS fails closed for
            // those users, so a missing filiale means "sees nothing", never
            // "sees everything".
            $table->foreignUuid('filiale_id')
                ->nullable()
                ->constrained('filiales')
                ->nullOnDelete();

            $table->index('filiale_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['filiale_id']);
            $table->dropConstrainedForeignId('filiale_id');
        });
    }
};

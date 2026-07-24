<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procedures', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('name');
            $table->string('module');
            $table->string('status')->default('draft'); 
            $table->foreignId('created_by')->constrained('users');
            $table->unsignedBigInteger('current_version_id')->nullable(); 
            $table->timestamps();
        });

        Schema::create('procedure_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procedure_id')->constrained('procedures')->cascadeOnDelete();
            $table->integer('version_number');
            $table->string('pdf_url');
            $table->string('infographic_url');
            $table->string('video_url');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::table('procedures', function (Blueprint $table) {
            $table->foreign('current_version_id')->references('id')->on('procedure_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('procedures', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });
        Schema::dropIfExists('procedure_versions');
        Schema::dropIfExists('procedures');
    }
};
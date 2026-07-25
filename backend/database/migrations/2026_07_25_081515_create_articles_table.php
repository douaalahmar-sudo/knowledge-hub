<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('summary')->nullable();
            $table->longText('content'); // Supports Rich Text HTML
            
            // Classification
            $table->enum('category', [
                'news_announcements',
                'onboarding_guides',
                'policies_guidelines',
                'hr_documentation'
            ]);
            $table->json('tags')->nullable();
            
            // Publishing Status Workflow
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->timestamp('published_at')->nullable();
            
            // Scoping & Ownership
            $table->string('tenant_id')->index();
            $table->foreignId('author_id')->constrained('users')->onDelete('cascade');
            
            // Attachments & Cover Metadata
            $table->string('cover_image_url')->nullable();
            $table->json('attachments')->nullable();
            $table->unsignedInteger('reading_time_minutes')->default(1);
            
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
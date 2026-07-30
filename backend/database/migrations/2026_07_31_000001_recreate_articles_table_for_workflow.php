<?php

use App\Support\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the original HR-articles schema (category, tags, inline `content`,
 * cover_image_url, attachments) with the Drive-backed workflow version: files
 * live in Google Drive (format_*_drive_id), `content_summary` is only a
 * lexical-search excerpt, and status moves through a validation chain instead
 * of a flat draft/published/archived.
 *
 * This drops the table rather than altering it — the two schemas share almost
 * no columns, and the 5 rows DatabaseSeeder had put there don't map onto the
 * new one. ArticleController and the Angular article components still expect
 * the old columns as of this migration; they need a follow-up rewrite before
 * anything using them works again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('articles');

        Schema::create('articles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('filiale_id')->constrained('filiales');

            $table->string('title');
            $table->string('slug')->unique();
            $table->text('content_summary')->nullable();
            $table->jsonb('tags_metier')->default('[]');

            $table->enum('criticite', ['golden_rule', 'note'])->default('note');
            $table->enum('status', [
                'draft',
                'pending_metier',
                'pending_qualite',
                'published',
                'archived',
            ])->default('draft');

            $table->string('format_pdf_drive_id')->nullable();
            $table->string('format_infographie_drive_id')->nullable();
            $table->string('format_video_drive_id')->nullable();

            $table->integer('version')->default(1);
            $table->boolean('is_active_version')->default(true);

            // Self-referencing FK: added in a second Schema::table() call below,
            // since articles.id doesn't exist yet inside this same Blueprint.
            $table->uuid('parent_article_id')->nullable();

            // users.id is bigint (see conversation) — foreignId matches that,
            // not the foreignUuid a uuid-keyed users table would need.
            $table->foreignId('author_id')->constrained('users');
            $table->foreignId('validated_by_metier_id')->nullable()->constrained('users');
            $table->foreignId('validated_by_qualite_id')->nullable()->constrained('users');
            $table->foreignId('data_owner_id')->constrained('users');

            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::table('articles', function (Blueprint $table) {
            $table->foreign('parent_article_id')
                ->references('id')->on('articles')
                ->nullOnDelete();
        });

        // Same fail-closed filiale_isolation policy as every other business
        // table (procedures, hr_requests, ...) — see RowLevelSecurity and the
        // migration that first turned this on for `articles`.
        RowLevelSecurity::enable('articles');
    }

    public function down(): void
    {
        // No attempt to restore the old HR-articles schema: this is a one-way
        // replacement, and down() only exists so the migration can be undone
        // during development, not to recover the previous shape.
        RowLevelSecurity::disable('articles');
        Schema::dropIfExists('articles');
    }
};

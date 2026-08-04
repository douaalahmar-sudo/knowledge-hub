<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Filiale;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * The journal d'audit — cahier des charges §10.4 ("toutes les actions de
 * consultation sont consignées") and the §4.2 requirement that archived
 * versions stay "traçables dans les logs d'audit".
 *
 * What is NOT asserted here, deliberately: filiale isolation, the
 * append-only guarantee, and the role restriction *at the table level*. This
 * suite runs on sqlite (see phpunit.xml), which has no row-level security, so
 * every one of those would pass for the wrong reason. They are proven against
 * a real PostgreSQL connection as the restricted role in
 * AuditLogRowLevelSecurityTest. What this file proves is that the events are
 * captured at all, with the right actor, resource, action and address, and
 * that the endpoint's own Gate holds.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    /** An address to assert on, distinct from the test client's default. */
    private const CLIENT_IP = '196.203.44.12';

    private Filiale $filiale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filiale = Filiale::create(['name' => 'Filiale de test']);

        // request()->ip() — the same call AuthController::me() serves the
        // §10.3 watermark from, which is the point of routing both through it.
        $this->withServerVariables(['REMOTE_ADDR' => self::CLIENT_IP]);
    }

    // ------------------------------------------------------------- fixtures

    /** `matricule` is NOT NULL and unique, and UserFactory does not define one. */
    private function user(?string $accessRole = null): User
    {
        return User::factory()->create([
            'filiale_id' => $this->filiale->id,
            'access_role' => $accessRole,
            'matricule' => 'AUD-'.Str::random(10),
        ]);
    }

    private function article(string $status = 'published', ?User $author = null, array $overrides = []): Article
    {
        $author ??= $this->user('redacteur');

        return Article::create(array_merge([
            'filiale_id' => $this->filiale->id,
            'title' => 'Article de test '.uniqid(),
            'slug' => 'article-de-test-'.uniqid(),
            'criticite' => 'note',
            'status' => $status,
            'is_active_version' => true,
            'author_id' => $author->id,
            'data_owner_id' => $author->id,
        ], $overrides));
    }

    /** The newest entry for $action, or null. */
    private function entry(AuditAction $action): ?AuditLog
    {
        return AuditLog::where('action', $action->value)->latest('created_at')->first();
    }

    /** Stands in for Drive so retrieveFile() can run without a network call. */
    private function fakeDrive(string $contents = '%PDF-1.4', string $mime = 'application/pdf'): void
    {
        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('streamFile')->andReturn($contents);
        $drive->shouldReceive('getMimeType')->andReturn($mime);

        $this->instance(GoogleDriveService::class, $drive);
    }

    // -------------------------------------------------- §10.4 consultation

    public function test_viewing_an_article_is_journalled_with_actor_resource_and_ip(): void
    {
        $reader = $this->user('lecteur');
        $article = $this->article();

        $this->actingAs($reader, 'sanctum')
            ->getJson("/api/v1/articles/{$article->id}")
            ->assertStatus(200);

        $entry = $this->entry(AuditAction::ArticleViewed);

        $this->assertNotNull($entry, 'A consultation wrote no audit entry.');
        $this->assertSame($reader->id, $entry->user_id);
        $this->assertSame($article->id, $entry->auditable_id);
        $this->assertSame(Article::class, $entry->auditable_type);
        $this->assertSame(self::CLIENT_IP, $entry->ip_address);
        $this->assertSame($this->filiale->id, $entry->filiale_id);
        $this->assertSame('published', $entry->metadata['status']);
        $this->assertNotNull($entry->created_at);
    }

    /**
     * The document itself reaching a reader — the event §10.3's watermark is
     * the on-screen half of.
     */
    public function test_retrieving_a_document_file_is_journalled_with_its_format(): void
    {
        $this->fakeDrive();

        $reader = $this->user('lecteur');
        $article = $this->article('published', null, ['format_pdf_drive_id' => 'drive-file-123']);

        $this->actingAs($reader, 'sanctum')
            ->get("/api/v1/articles/{$article->id}/files/pdf")
            ->assertStatus(200);

        $entry = $this->entry(AuditAction::ArticleFileViewed);

        $this->assertNotNull($entry, 'A document consultation wrote no audit entry.');
        $this->assertSame($reader->id, $entry->user_id);
        $this->assertSame($article->id, $entry->auditable_id);
        $this->assertSame(self::CLIENT_IP, $entry->ip_address);
        $this->assertSame('pdf', $entry->metadata['format']);
        $this->assertSame('drive-file-123', $entry->metadata['drive_file_id']);
    }

    /** A refused consultation is the more interesting half of a security log. */
    public function test_a_refused_consultation_is_journalled_as_a_denial(): void
    {
        $reader = $this->user('lecteur');
        $draft = $this->article('draft');

        $this->actingAs($reader, 'sanctum')
            ->getJson("/api/v1/articles/{$draft->id}")
            ->assertStatus(404);

        $entry = $this->entry(AuditAction::ArticleAccessDenied);

        $this->assertNotNull($entry, 'A refused consultation left no trace.');
        $this->assertSame($reader->id, $entry->user_id);
        $this->assertSame($draft->id, $entry->auditable_id);
        $this->assertSame('articles.show', $entry->metadata['endpoint']);
        $this->assertSame('lecteur_non_published_active', $entry->metadata['reason']);
    }

    public function test_a_refused_file_retrieval_records_the_format_it_was_refused(): void
    {
        $reader = $this->user('lecteur');
        $draft = $this->article('draft', null, ['format_video_drive_id' => 'drive-video-9']);

        $this->actingAs($reader, 'sanctum')
            ->get("/api/v1/articles/{$draft->id}/files/video")
            ->assertStatus(404);

        $entry = $this->entry(AuditAction::ArticleAccessDenied);

        $this->assertNotNull($entry);
        $this->assertSame('articles.files.retrieve', $entry->metadata['endpoint']);
        $this->assertSame('video', $entry->metadata['format']);
    }

    /**
     * A list is a page of titles, not a consultation of a document. Journalling
     * it would bury the events that matter under navigation noise — asserted so
     * the omission stays a decision rather than becoming an oversight.
     */
    public function test_listing_articles_is_not_journalled(): void
    {
        $this->article();

        $this->actingAs($this->user('lecteur'), 'sanctum')
            ->getJson('/api/v1/articles')
            ->assertStatus(200);

        $this->assertSame(0, AuditLog::count(), 'Listing articles wrote audit entries.');
    }

    // ----------------------------------------------------- §10.4 workflow

    public function test_submitting_for_validation_is_journalled_with_both_statuses(): void
    {
        $author = $this->user('redacteur');
        $article = $this->article('draft', $author);

        $this->actingAs($author, 'sanctum')
            ->postJson("/api/v1/articles/{$article->id}/submit")
            ->assertStatus(200);

        $entry = $this->entry(AuditAction::ArticleSubmitted);

        $this->assertNotNull($entry);
        $this->assertSame($author->id, $entry->user_id);
        $this->assertSame($article->id, $entry->auditable_id);
        $this->assertSame(self::CLIENT_IP, $entry->ip_address);
        $this->assertSame('draft', $entry->metadata['old_status']);
        $this->assertSame('pending_metier', $entry->metadata['new_status']);
    }

    public function test_metier_validation_is_journalled_against_its_validator(): void
    {
        $validator = $this->user('responsable_departement');
        $article = $this->article('pending_metier');

        $this->actingAs($validator, 'sanctum')
            ->postJson("/api/v1/articles/{$article->id}/validate-metier")
            ->assertStatus(200);

        $entry = $this->entry(AuditAction::ArticleValidatedMetier);

        $this->assertNotNull($entry);
        $this->assertSame($validator->id, $entry->user_id);
        $this->assertSame('pending_metier', $entry->metadata['old_status']);
        $this->assertSame('pending_qualite', $entry->metadata['new_status']);
    }

    public function test_qualite_validation_is_journalled_against_its_validator(): void
    {
        $validator = $this->user('qualite');
        $article = $this->article('pending_qualite');

        $this->actingAs($validator, 'sanctum')
            ->postJson("/api/v1/articles/{$article->id}/validate-qualite")
            ->assertStatus(200);

        $entry = $this->entry(AuditAction::ArticleValidatedQualite);

        $this->assertNotNull($entry);
        $this->assertSame($validator->id, $entry->user_id);
        $this->assertSame($article->id, $entry->auditable_id);
        $this->assertSame('pending_qualite', $entry->metadata['old_status']);
        $this->assertSame('published', $entry->metadata['new_status']);
    }

    public function test_rejection_is_journalled_with_its_reason(): void
    {
        $validator = $this->user('responsable_departement');
        $article = $this->article('pending_metier');

        $this->actingAs($validator, 'sanctum')
            ->postJson("/api/v1/articles/{$article->id}/reject", [
                'reason' => 'Le mode opératoire ne correspond pas au terrain.',
            ])
            ->assertStatus(200);

        $entry = $this->entry(AuditAction::ArticleRejected);

        $this->assertNotNull($entry);
        $this->assertSame($validator->id, $entry->user_id);
        $this->assertSame('pending_metier', $entry->metadata['old_status']);
        $this->assertSame('draft', $entry->metadata['new_status']);
        // The article table still has nowhere to keep this; the trail does.
        $this->assertSame('Le mode opératoire ne correspond pas au terrain.', $entry->metadata['reason']);
    }

    /**
     * §4.2: an archived version must stay "traçables dans les logs d'audit".
     * The entry is filed against the *superseded* version, not the one that
     * replaced it — the trail is read as "what happened to this document".
     */
    public function test_archiving_a_superseded_version_is_journalled_against_that_version(): void
    {
        $author = $this->user('redacteur');
        $previous = $this->article('published', $author);
        $replacement = $this->article('pending_qualite', $author, [
            'parent_article_id' => $previous->id,
            'version' => 2,
            'is_active_version' => false,
        ]);

        $this->actingAs($this->user('qualite'), 'sanctum')
            ->postJson("/api/v1/articles/{$replacement->id}/validate-qualite")
            ->assertStatus(200);

        $entry = $this->entry(AuditAction::ArticleArchived);

        $this->assertNotNull($entry, 'An archived version left no §4.2 trace.');
        $this->assertSame($previous->id, $entry->auditable_id);
        $this->assertSame('published', $entry->metadata['old_status']);
        $this->assertSame('archived', $entry->metadata['new_status']);
        $this->assertSame($replacement->id, $entry->metadata['superseded_by']);

        // And the archiving actually happened, so the entry is not describing
        // something the application failed to do.
        $this->assertSame('archived', $previous->fresh()->status->value);
    }

    // ------------------------------------------------- §10.4 read endpoint

    /**
     * The core §10.4 access requirement: a security log is not user-facing
     * data. Every role that is not admin or data_owner is refused, including
     * the ones trusted to validate documents.
     */
    public function test_ordinary_users_cannot_read_the_audit_log(): void
    {
        $this->article();

        // Every access_role the system has except the two privileged ones.
        // `access_role` is NOT NULL, so there is no "no role" case to cover.
        foreach (['lecteur', 'redacteur', 'responsable_departement', 'qualite'] as $role) {
            $response = $this->actingAs($this->user($role), 'sanctum')
                ->getJson('/api/v1/audit-logs');

            $response->assertStatus(403)
                ->assertJsonPath(
                    'message',
                    'Seul un administrateur ou un propriétaire des données peut consulter le journal d\'audit.'
                );

            // Not merely an empty page dressed as a refusal.
            $this->assertArrayNotHasKey('data', $response->json());
        }
    }

    public function test_an_admin_can_read_the_audit_log(): void
    {
        $reader = $this->user('lecteur');
        $article = $this->article();

        $this->actingAs($reader, 'sanctum')->getJson("/api/v1/articles/{$article->id}");

        $response = $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/v1/audit-logs');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.action', AuditAction::ArticleViewed->value)
            ->assertJsonPath('data.0.user_id', $reader->id)
            ->assertJsonPath('data.0.ip_address', self::CLIENT_IP)
            // The actor is resolved, so the log is readable without a second
            // request per row.
            ->assertJsonPath('data.0.user.name', $reader->name);
    }

    /** §6.1's "Gardien du Temple" is accountable for their filiale's documents. */
    public function test_a_data_owner_can_read_the_audit_log(): void
    {
        $this->actingAs($this->user('data_owner'), 'sanctum')
            ->getJson('/api/v1/audit-logs')
            ->assertStatus(200);
    }

    public function test_the_log_is_filterable_by_user_and_by_action(): void
    {
        $readerA = $this->user('lecteur');
        $readerB = $this->user('lecteur');
        $article = $this->article();

        $this->actingAs($readerA, 'sanctum')->getJson("/api/v1/articles/{$article->id}");
        $this->actingAs($readerB, 'sanctum')->getJson("/api/v1/articles/{$article->id}");

        $admin = $this->user('admin');

        $byUser = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/audit-logs?user_id='.$readerA->id);

        $byUser->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($readerA->id, $byUser->json('data.0.user_id'));

        $byAction = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/audit-logs?action='.AuditAction::ArticleViewed->value);

        $byAction->assertStatus(200)->assertJsonCount(2, 'data');
    }

    public function test_the_log_is_filterable_by_date_range_and_filiale(): void
    {
        $article = $this->article();
        $this->actingAs($this->user('lecteur'), 'sanctum')->getJson("/api/v1/articles/{$article->id}");

        $admin = $this->user('admin');

        // urlencode: an ISO-8601 string ends in "+00:00", and a raw `+` in a
        // query string decodes to a space, which then fails `date` validation.
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/audit-logs?from='.urlencode(now()->subDay()->toIso8601String())
                .'&to='.urlencode(now()->addDay()->toIso8601String()))
            ->assertStatus(200)
            ->assertJsonPath('data.0.action', AuditAction::ArticleViewed->value);

        // A window that closes before the event.
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/audit-logs?to='.urlencode(now()->subDay()->toIso8601String()))
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/audit-logs?filiale_id='.$this->filiale->id)
            ->assertStatus(200)
            ->assertJsonPath('data.0.filiale_id', $this->filiale->id);
    }

    /**
     * An unknown action is rejected rather than silently returning nothing: an
     * empty page reads exactly like "this user did nothing", which is the most
     * dangerous wrong answer a security log can give.
     */
    public function test_an_unknown_action_filter_is_rejected_rather_than_returning_nothing(): void
    {
        $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/v1/audit-logs?action=article.vieuwed')
            ->assertStatus(422)
            ->assertJsonValidationErrors('action');
    }

    public function test_a_reversed_date_range_is_rejected(): void
    {
        $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/v1/audit-logs?from='.urlencode(now()->toIso8601String())
                .'&to='.urlencode(now()->subWeek()->toIso8601String()))
            ->assertStatus(422)
            ->assertJsonValidationErrors('to');
    }

    /** A log that cannot say who read it has a blind spot where it matters most. */
    public function test_reading_the_log_is_itself_journalled(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/audit-logs?action='.AuditAction::ArticleViewed->value)
            ->assertStatus(200);

        $entry = $this->entry(AuditAction::AuditLogViewed);

        $this->assertNotNull($entry, 'Consulting the audit log left no trace.');
        $this->assertSame($admin->id, $entry->user_id);
        $this->assertNull($entry->auditable_id, 'The log-read entry points at a resource it has none of.');
        $this->assertSame(AuditAction::ArticleViewed->value, $entry->metadata['filters']['action']);
    }

    // ------------------------------------------------------- failure mode

    /**
     * The deliberate trade documented in AuditLogger: a consultation that
     * cannot be journalled is still a consultation the user is entitled to.
     * Dropping the table is a blunt way to force a write failure, and it is
     * the honest one — it exercises the real catch, not a mocked one.
     */
    public function test_a_logging_failure_does_not_break_the_audited_action(): void
    {
        Log::spy();

        $article = $this->article();
        Schema::drop('audit_logs');

        $this->actingAs($this->user('lecteur'), 'sanctum')
            ->getJson("/api/v1/articles/{$article->id}")
            ->assertStatus(200);

        // Swallowed, but never silently: the gap has to be detectable.
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => str_contains($message, 'audit log entry'))
            ->atLeast()->once();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}

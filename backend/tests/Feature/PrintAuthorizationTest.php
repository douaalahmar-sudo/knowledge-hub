<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Filiale;
use App\Models\PrintAuthorization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * §11.1's authorized-print exception to §11's Hub-wide print ban.
 *
 * The banner and the 24-hour notice themselves are frontend concerns and are
 * asserted in the Angular suite. What is asserted here is the access-control
 * surface underneath them: who may open the exception, for how long, over which
 * documents, and what the trail records.
 */
class PrintAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Filiale $filiale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filiale = Filiale::create(['name' => 'Filiale de test']);
        $this->withServerVariables(['REMOTE_ADDR' => '196.203.44.12']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------- fixtures

    private function user(string $accessRole): User
    {
        return User::factory()->create([
            'filiale_id' => $this->filiale->id,
            'access_role' => $accessRole,
            'matricule' => 'PRN-'.Str::random(8),
        ]);
    }

    private function article(string $status = 'published', bool $active = true): Article
    {
        $author = $this->user('redacteur');

        return Article::create([
            'filiale_id' => $this->filiale->id,
            'title' => 'Article de test '.uniqid(),
            'slug' => 'article-de-test-'.uniqid(),
            'criticite' => 'note',
            'status' => $status,
            'is_active_version' => $active,
            'author_id' => $author->id,
            'data_owner_id' => $author->id,
        ]);
    }

    private function authorize(User $user, Article $article)
    {
        return $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/articles/{$article->id}/print-authorizations");
    }

    // ------------------------------------------------------ who may authorize

    public function test_a_data_owner_can_authorize_a_print(): void
    {
        $owner = $this->user('data_owner');
        $article = $this->article();

        $response = $this->authorize($owner, $article);

        $response->assertStatus(201)
            ->assertJsonPath('article_id', $article->id)
            // Resolved server-side: the client must never be the source of the
            // identity stamped on the paper.
            ->assertJsonPath('matricule', $owner->matricule)
            ->assertJsonPath('holder_name', $owner->name);

        $grant = PrintAuthorization::first();

        $this->assertSame($owner->id, $grant->user_id);
        $this->assertSame($owner->id, $grant->granted_by);
        $this->assertNull($grant->used_at);
        $this->assertTrue($grant->isUsable());
    }

    public function test_an_admin_can_authorize_a_print(): void
    {
        $this->authorize($this->user('admin'), $this->article())->assertStatus(201);
    }

    /**
     * The core of §11: printing is off by default. Everyone else is refused,
     * including the roles trusted to write and validate the document.
     */
    public function test_nobody_else_can_authorize_a_print(): void
    {
        $article = $this->article();

        foreach (['lecteur', 'redacteur', 'responsable_departement', 'qualite'] as $role) {
            $response = $this->authorize($this->user($role), $article);

            $response->assertStatus(403)
                ->assertJsonPath(
                    'message',
                    'Seul un administrateur ou un propriétaire des données peut autoriser une impression.'
                );
        }

        $this->assertSame(0, PrintAuthorization::count());
    }

    /**
     * A printed draft would carry a banner calling it company property beside a
     * notice pointing at "la version officielle faisant foi" — contradicting
     * itself on the same sheet.
     */
    public function test_only_the_published_current_version_can_be_printed(): void
    {
        $owner = $this->user('data_owner');

        $this->authorize($owner, $this->article('draft'))->assertStatus(422);
        $this->authorize($owner, $this->article('pending_qualite'))->assertStatus(422);
        $this->authorize($owner, $this->article('published', false))->assertStatus(422);

        $this->assertSame(0, PrintAuthorization::count());
    }

    // ------------------------------------------------------------ the grant

    public function test_the_grant_expires(): void
    {
        config(['security.print.grant_ttl_seconds' => 300]);

        Carbon::setTestNow(Carbon::parse('2026-08-04 12:00:00'));

        $owner = $this->user('data_owner');
        $grantId = $this->authorize($owner, $this->article())->json('id');

        // Four minutes later it still works.
        Carbon::setTestNow(Carbon::parse('2026-08-04 12:04:00'));
        $this->assertTrue(PrintAuthorization::find($grantId)->isUsable());

        // Six minutes later it does not, and consuming it says so.
        Carbon::setTestNow(Carbon::parse('2026-08-04 12:06:00'));

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/print-authorizations/{$grantId}/consume")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cette autorisation d\'impression a expiré.');
    }

    public function test_a_grant_is_single_use(): void
    {
        $owner = $this->user('data_owner');
        $grantId = $this->authorize($owner, $this->article())->json('id');

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/print-authorizations/{$grantId}/consume")
            ->assertStatus(200);

        // A second print is a second copy and needs its own authorization —
        // replaying is deliberately not idempotent.
        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/print-authorizations/{$grantId}/consume")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cette autorisation d\'impression a déjà été utilisée.');

        $this->assertNotNull(PrintAuthorization::find($grantId)->used_at);
    }

    /** Holding someone else's grant id must not let you print under their trace number. */
    public function test_a_grant_cannot_be_used_by_anybody_else(): void
    {
        $owner = $this->user('data_owner');
        $grantId = $this->authorize($owner, $this->article())->json('id');

        foreach (['lecteur', 'admin'] as $role) {
            $this->actingAs($this->user($role), 'sanctum')
                ->postJson("/api/v1/print-authorizations/{$grantId}/consume")
                ->assertStatus(403);
        }

        $this->assertNull(PrintAuthorization::find($grantId)->used_at);
    }

    // ------------------------------------------------------------- the trail

    /** §10.4: exactly the kind of event the trail exists for. */
    public function test_authorizing_a_print_is_journalled_with_the_trace_number(): void
    {
        $owner = $this->user('data_owner');
        $article = $this->article();

        $grantId = $this->authorize($owner, $article)->json('id');

        $entry = AuditLog::where('action', AuditAction::ArticlePrintAuthorized->value)->first();

        $this->assertNotNull($entry, 'An authorized print left no audit entry.');
        $this->assertSame($owner->id, $entry->user_id);
        $this->assertSame($article->id, $entry->auditable_id);
        $this->assertSame('196.203.44.12', $entry->ip_address);
        $this->assertSame($grantId, $entry->metadata['print_authorization_id']);
        // The number that goes on the paper, recorded so a recovered sheet can
        // be matched back even if the matricule is later reassigned.
        $this->assertSame($owner->matricule, $entry->metadata['matricule']);
    }

    /**
     * Authorizing and printing are separate events: a grant issued and never
     * used is a different fact from a document that left on paper.
     */
    public function test_the_print_itself_is_journalled_separately(): void
    {
        $owner = $this->user('data_owner');
        $article = $this->article();

        $grantId = $this->authorize($owner, $article)->json('id');

        $this->assertSame(0, AuditLog::where('action', AuditAction::ArticlePrinted->value)->count());

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/print-authorizations/{$grantId}/consume")
            ->assertStatus(200);

        $entry = AuditLog::where('action', AuditAction::ArticlePrinted->value)->first();

        $this->assertNotNull($entry, 'The print itself never reached the trail.');
        $this->assertSame($article->id, $entry->auditable_id);
        $this->assertSame($grantId, $entry->metadata['print_authorization_id']);
    }

    /** A refused authorization is visible to an admin reading the trail. */
    public function test_an_admin_can_see_print_events_in_the_audit_log(): void
    {
        $owner = $this->user('data_owner');
        $this->authorize($owner, $this->article());

        $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/v1/audit-logs?action='.AuditAction::ArticlePrintAuthorized->value)
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $owner->id);
    }
}

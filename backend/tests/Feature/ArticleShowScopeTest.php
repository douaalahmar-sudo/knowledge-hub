<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GoogleDriveService;
use App\Support\Database\FilialeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Per-role visibility of GET /api/v1/articles/{article} —
 * ArticleController::isVisibleTo(), which reuses scopeToRole().
 *
 * Run against a real PostgreSQL connection opened as the restricted `kh_app`
 * role, not the sqlite in-memory database the rest of the feature suite uses
 * (see phpunit.xml). That matters here specifically: `isVisibleTo()` asks the
 * database whether the row is in the caller's slice, so the query runs with the
 * RLS policies active and under the same role the deployed app connects as. On
 * sqlite the policies do not exist, and a passing test would only prove the
 * Eloquent half of the rule.
 *
 * The list counterpart is ArticleIndexScopeTest, which does run on sqlite —
 * there the assertion is purely about the query scope, with no interaction
 * between the app filter and RLS to get wrong.
 *
 * Skips rather than fails when the connection is unavailable, matching
 * ArticleAlertRowLevelSecurityTest: not every checkout has the local Postgres
 * and the `kh_app` role provisioned.
 */
class ArticleShowScopeTest extends TestCase
{
    private const CONNECTION = 'pgsql_rls_test';

    private string $filiale;

    /** The author every fixture article belongs to, unless a test says otherwise. */
    private int $authorId;

    /** @var array<string, string> status => article id */
    private array $articles = [];

    protected function setUp(): void
    {
        parent::setUp();

        try {
            DB::connection(self::CONNECTION)->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped(
                'PostgreSQL RLS connection unavailable ('.$e->getMessage().'). '
                .'Run database/sql/create_rls_app_role.sql and set the RLS_TEST_DB_* env vars.'
            );
        }

        // The HTTP requests below go through the app's *default* connection, so
        // point that at the kh_app PostgreSQL connection for the duration of
        // the test. Without this the request would run on sqlite and the point
        // of the suite would be lost.
        config(['database.default' => self::CONNECTION]);

        // Everything is seeded and asserted inside one transaction that is
        // rolled back in tearDown — kh_app has no DDL rights, so RefreshDatabase
        // is not an option, and this leaves the development database untouched.
        DB::connection(self::CONNECTION)->beginTransaction();

        $this->seedCorpus();
    }

    protected function tearDown(): void
    {
        $connection = DB::connection(self::CONNECTION);

        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        // set_config(..., is_local => false) survives a rollback; clear it so the
        // pooled connection does not carry a filiale into the next test.
        FilialeContext::forget(self::CONNECTION);

        parent::tearDown();
    }

    // ------------------------------------------------------------- fixtures

    private function seedCorpus(): void
    {
        $this->filiale = (string) Str::uuid();

        // `filiales` is not RLS-protected: it is the lookup table the policies
        // resolve against, and a session must be able to see its own.
        DB::connection(self::CONNECTION)->table('filiales')->insert([
            'id' => $this->filiale,
            'name' => 'Filiale show-scope '.Str::random(6),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Rows are written while acting as the filiale — the only way the
        // WITH CHECK clause allows it.
        FilialeContext::set($this->filiale, self::CONNECTION);

        $this->authorId = $this->insertUser('redacteur');

        foreach (['draft', 'pending_metier', 'pending_qualite', 'published', 'archived'] as $status) {
            $this->articles[$status] = $this->insertArticle($status, $this->authorId);
        }

        // The superseded version: published, but no longer current.
        $this->articles['published_superseded'] = $this->insertArticle(
            'published',
            $this->authorId,
            isActiveVersion: false
        );
    }

    private function insertUser(string $accessRole): int
    {
        $suffix = Str::random(8);

        return DB::connection(self::CONNECTION)->table('users')->insertGetId([
            'name' => 'Show scope '.$suffix,
            'email' => "show.scope.{$suffix}@example.test",
            'matricule' => 'SHW-'.$suffix,
            'password' => bcrypt('password123'),
            'filiale_id' => $this->filiale,
            'access_role' => $accessRole,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertArticle(string $status, int $authorId, bool $isActiveVersion = true): string
    {
        $id = (string) Str::uuid();

        DB::connection(self::CONNECTION)->table('articles')->insert([
            'id' => $id,
            'filiale_id' => $this->filiale,
            'title' => 'Article '.$status.' '.Str::random(6),
            'slug' => 'article-'.$status.'-'.Str::random(10),
            'criticite' => 'note',
            'status' => $status,
            'author_id' => $authorId,
            'data_owner_id' => $authorId,
            'is_active_version' => $isActiveVersion,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    // -------------------------------------------------------------- helpers

    /** The status code GET /articles/{id} returns for a user with this role. */
    private function statusFor(string $accessRole, string $article, ?int $userId = null): int
    {
        $userId ??= $this->insertUser($accessRole);

        $user = User::on(self::CONNECTION)->find($userId);

        return $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/articles/{$this->articles[$article]}")
            ->getStatusCode();
    }

    /**
     * Asserts the whole status row for one role in one go, so a change in the
     * rules shows up as a readable diff rather than one failing assertion.
     *
     * @param  array<string, int>  $expected  article key => HTTP status
     */
    private function assertVisibility(string $accessRole, array $expected, ?int $userId = null): void
    {
        $actual = [];

        foreach (array_keys($expected) as $article) {
            $actual[$article] = $this->statusFor($accessRole, $article, $userId);
        }

        $this->assertSame($expected, $actual, "Wrong visibility for {$accessRole}.");
    }

    // ---------------------------------------------------------------- tests

    public function test_lecteur_can_only_open_the_published_current_article(): void
    {
        $this->assertVisibility('lecteur', [
            'draft' => 404,
            'pending_metier' => 404,
            'pending_qualite' => 404,
            'published' => 200,
            'published_superseded' => 404,
            'archived' => 404,
        ]);
    }

    /**
     * The queue link has to keep working: a responsable_departement opens the
     * article they are being asked to validate straight from the list.
     */
    public function test_responsable_departement_can_open_the_metier_stage_but_not_the_others(): void
    {
        $this->assertVisibility('responsable_departement', [
            'draft' => 404,
            'pending_metier' => 200,
            'pending_qualite' => 404,
            'published' => 200,
            'published_superseded' => 404,
            'archived' => 404,
        ]);
    }

    public function test_qualite_can_open_the_qualite_stage_but_not_the_others(): void
    {
        $this->assertVisibility('qualite', [
            'draft' => 404,
            'pending_metier' => 404,
            'pending_qualite' => 200,
            'published' => 200,
            'published_superseded' => 404,
            'archived' => 404,
        ]);
    }

    /** The author sees their own work wherever it currently sits. */
    public function test_the_authoring_redacteur_can_open_their_own_article_at_any_stage(): void
    {
        $this->assertVisibility('redacteur', [
            'draft' => 200,
            'pending_metier' => 200,
            'pending_qualite' => 200,
            'published' => 200,
            'published_superseded' => 200,
            'archived' => 200,
        ], userId: $this->authorId);
    }

    /**
     * The hole this change closes: another author's unpublished work was
     * openable by pasting its UUID, even though it never appeared in the list.
     */
    public function test_a_redacteur_cannot_open_another_authors_unpublished_article(): void
    {
        $otherRedacteur = $this->insertUser('redacteur');

        $this->assertVisibility('redacteur', [
            'draft' => 404,
            'pending_metier' => 404,
            'pending_qualite' => 404,
            'published' => 200,
            'published_superseded' => 404,
            'archived' => 404,
        ], userId: $otherRedacteur);
    }

    public function test_admin_can_open_everything(): void
    {
        $this->assertVisibility('admin', [
            'draft' => 200,
            'pending_metier' => 200,
            'pending_qualite' => 200,
            'published' => 200,
            'published_superseded' => 200,
            'archived' => 200,
        ]);
    }

    /** §6.1: the Gardien du Temple answers for the whole corpus. */
    public function test_data_owner_can_open_everything(): void
    {
        $this->assertVisibility('data_owner', [
            'draft' => 200,
            'pending_metier' => 200,
            'pending_qualite' => 200,
            'published' => 200,
            'published_superseded' => 200,
            'archived' => 200,
        ]);
    }

    // ------------------------------------------------------- shape of the refusal

    /**
     * 404 and nothing else. A 403 would confirm the id names a real article in
     * this filiale, which is precisely what a caller probing for other people's
     * drafts is trying to learn.
     *
     * Asserted with `app.debug` off because that is the deployed configuration
     * and the only one where the comparison means anything: with debug on,
     * Laravel renders a missing route-model binding as "No query results for
     * model [App\Models\Article] <uuid>" while a refused one carries an empty
     * message — a difference that exists only in the debug renderer, not in
     * what production returns.
     */
    public function test_an_out_of_scope_article_is_indistinguishable_from_a_nonexistent_one(): void
    {
        config(['app.debug' => false]);

        $reader = User::on(self::CONNECTION)->find($this->insertUser('lecteur'));

        $refusedId = $this->articles['draft'];
        $missingId = (string) Str::uuid();

        $refused = $this->actingAs($reader, 'sanctum')->getJson("/api/v1/articles/{$refusedId}");
        $missing = $this->actingAs($reader, 'sanctum')->getJson("/api/v1/articles/{$missingId}");

        $refused->assertStatus(404);
        $missing->assertStatus(404);

        // Each response echoes back the id that was asked for, so compare with
        // that one substitution normalized away: what must not differ is
        // everything else. An id the caller supplied tells them nothing they
        // did not already know.
        $this->assertSame(
            str_replace($missingId, '<id>', (string) $missing->json('message')),
            str_replace($refusedId, '<id>', (string) $refused->json('message')),
            'A refused article is distinguishable from a nonexistent one.'
        );

        // Belt and braces, and independent of the debug setting: whatever the
        // body says, it must not describe the article that was refused.
        $refused->assertDontSee('Article draft', escape: false);
    }

    /**
     * The document itself, not just its metadata: tightening show() alone would
     * leave the PDF streamable from a bare article id.
     *
     * Drive is mocked because GoogleDriveService is a *method* parameter on
     * retrieveFile(), so the container constructs it before the controller body
     * — and therefore before the visibility check — runs. Without the mock this
     * test dies in that constructor on a network call and proves nothing either
     * way. (The same resolution order is why AuditLogTest fakes Drive for its
     * refusal test.)
     */
    public function test_the_file_endpoint_refuses_an_out_of_scope_article_too(): void
    {
        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('streamFile')->andReturn('%PDF-1.4');
        $drive->shouldReceive('getMimeType')->andReturn('application/pdf');
        $this->instance(GoogleDriveService::class, $drive);

        $otherRedacteur = User::on(self::CONNECTION)->find($this->insertUser('redacteur'));

        $this->actingAs($otherRedacteur, 'sanctum')
            ->get("/api/v1/articles/{$this->articles['draft']}/files/pdf")
            ->assertStatus(404);
    }
}

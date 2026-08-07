<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Filiale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per-role visibility of GET /api/v1/articles —
 * ArticleController::scopeToRole().
 *
 * These assertions are about what leaves the server, not about what the
 * Angular list chooses to paint. Before scopeToRole() existed, index()
 * narrowed the query for lecteurs only, so every other role received every
 * draft in their filiale and the UI was the only thing hiding them; a reader
 * with the network tab open saw colleagues' unpublished work. Each case below
 * asserts on the response body for that reason.
 *
 * Filiale isolation is NOT asserted here: this suite runs on sqlite (see
 * phpunit.xml), which has no row-level security, so a cross-filiale assertion
 * would pass for the wrong reason. That is proven separately against a real
 * PostgreSQL connection — see FilialeRowLevelSecurityTest.
 */
class ArticleIndexScopeTest extends TestCase
{
    use RefreshDatabase;

    private Filiale $filiale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filiale = Filiale::create(['name' => 'Filiale de test']);
    }

    // ------------------------------------------------------------- fixtures

    /**
     * `matricule` is NOT NULL and unique on `users`, and UserFactory does not
     * define one — every caller has to supply it (same as ArticleAlertWorkflowTest).
     */
    private function user(?string $accessRole = null): User
    {
        return User::factory()->create([
            'filiale_id' => $this->filiale->id,
            'access_role' => $accessRole,
            'matricule' => 'IDX-'.Str::random(10),
        ]);
    }

    private function article(
        string $title,
        string $status,
        User $author,
        bool $isActiveVersion = true
    ): Article {
        return Article::create([
            'filiale_id' => $this->filiale->id,
            'title' => $title,
            'slug' => Str::slug($title).'-'.uniqid(),
            'criticite' => 'note',
            'status' => $status,
            'author_id' => $author->id,
            'data_owner_id' => $author->id,
            'is_active_version' => $isActiveVersion,
        ]);
    }

    /**
     * The corpus every test looks at: one article per interesting state, each
     * owned by someone other than the caller unless a test says otherwise.
     *
     * @return array{author: User, titles: array<string, string>}
     */
    private function seedCorpus(): array
    {
        $author = $this->user('redacteur');

        $this->article('Publie courant', 'published', $author);
        $this->article('Publie archive', 'published', $author, isActiveVersion: false);
        $this->article('Brouillon', 'draft', $author);
        $this->article('Attente metier', 'pending_metier', $author);
        $this->article('Attente qualite', 'pending_qualite', $author);
        $this->article('Archive', 'archived', $author);

        return ['author' => $author, 'titles' => []];
    }

    /** @return string[] the titles the API actually returned, sorted */
    private function titlesVisibleTo(User $user): array
    {
        $response = $this->actingAs($user)->getJson('/api/v1/articles');
        $response->assertOk();

        $titles = array_column($response->json(), 'title');
        sort($titles);

        return $titles;
    }

    // ---------------------------------------------------------------- tests

    public function test_lecteur_sees_only_published_current_articles(): void
    {
        $this->seedCorpus();

        $this->assertSame(
            ['Publie courant'],
            $this->titlesVisibleTo($this->user('lecteur'))
        );
    }

    /**
     * The superseded version is `published` but no longer current. §4.2 keeps
     * the row for the audit trail; it is not part of the readable corpus.
     */
    public function test_a_superseded_published_version_is_not_listed(): void
    {
        $this->seedCorpus();

        $this->assertNotContains('Publie archive', $this->titlesVisibleTo($this->user('lecteur')));
    }

    public function test_redacteur_sees_published_plus_their_own_work_at_any_stage(): void
    {
        ['author' => $author] = $this->seedCorpus();

        // The seeded corpus is all authored by $author, who is a redacteur —
        // so they see their own drafts and pending items, plus the published
        // one, but still not another author's draft (asserted below).
        $this->assertSame(
            ['Archive', 'Attente metier', 'Attente qualite', 'Brouillon', 'Publie archive', 'Publie courant'],
            $this->titlesVisibleTo($author)
        );
    }

    /** The regression this scoping exists for. */
    public function test_redacteur_does_not_see_another_authors_draft(): void
    {
        $this->seedCorpus();

        $otherRedacteur = $this->user('redacteur');

        $this->assertSame(
            ['Publie courant'],
            $this->titlesVisibleTo($otherRedacteur)
        );
    }

    public function test_responsable_departement_sees_published_plus_the_metier_queue(): void
    {
        $this->seedCorpus();

        $this->assertSame(
            ['Attente metier', 'Publie courant'],
            $this->titlesVisibleTo($this->user('responsable_departement'))
        );
    }

    public function test_qualite_sees_published_plus_the_qualite_queue(): void
    {
        $this->seedCorpus();

        $this->assertSame(
            ['Attente qualite', 'Publie courant'],
            $this->titlesVisibleTo($this->user('qualite'))
        );
    }

    public function test_admin_sees_everything_including_archived(): void
    {
        $this->seedCorpus();

        $this->assertSame(
            ['Archive', 'Attente metier', 'Attente qualite', 'Brouillon', 'Publie archive', 'Publie courant'],
            $this->titlesVisibleTo($this->user('admin'))
        );
    }

    /** §6.1: the Gardien du Temple answers for the whole corpus. */
    public function test_data_owner_sees_everything_including_archived(): void
    {
        $this->seedCorpus();

        $this->assertSame(
            ['Archive', 'Attente metier', 'Attente qualite', 'Brouillon', 'Publie archive', 'Publie courant'],
            $this->titlesVisibleTo($this->user('data_owner'))
        );
    }

    /**
     * `access_role` is NOT NULL and defaults to 'lecteur' (see the
     * 2026_07_30_000005 migration), so "no role" is not a state a row can be
     * in — a user created without one is a lecteur, and lands on the narrowest
     * view rather than the widest. scopeToRole()'s `default` branch is
     * therefore reachable only through that default, which is the safe
     * direction; there is no unhandled-role hole behind it.
     */
    public function test_a_user_created_without_an_explicit_role_defaults_to_the_narrowest_view(): void
    {
        $this->seedCorpus();

        $user = User::factory()->create([
            'filiale_id' => $this->filiale->id,
            'matricule' => 'IDX-'.Str::random(10),
        ]);

        $this->assertSame('lecteur', $user->fresh()->access_role->value);
        $this->assertSame(['Publie courant'], $this->titlesVisibleTo($user));
    }
}

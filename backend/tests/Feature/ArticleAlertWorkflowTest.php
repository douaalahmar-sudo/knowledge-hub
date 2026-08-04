<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleAlert;
use App\Models\Filiale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The article alert loop — cahier des charges §7.2 (reporting) and §7.3 (the
 * ouverte -> en_cours -> cloturee treatment chain).
 *
 * Filiale isolation is NOT asserted here: this suite runs on sqlite (see
 * phpunit.xml), which has no row-level security, so a cross-filiale assertion
 * would pass for the wrong reason. That is proven separately against a real
 * PostgreSQL connection as the restricted role — see
 * ArticleAlertRowLevelSecurityTest.
 */
class ArticleAlertWorkflowTest extends TestCase
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
     * define one — every caller has to supply it (same as ClientIpWatermarkTest).
     */
    private function user(?string $accessRole = null): User
    {
        return User::factory()->create([
            'filiale_id' => $this->filiale->id,
            'access_role' => $accessRole,
            'matricule' => 'ALT-'.Str::random(10),
        ]);
    }

    private function article(string $status = 'published', ?User $author = null): Article
    {
        $author ??= $this->user('redacteur');

        return Article::create([
            'filiale_id' => $this->filiale->id,
            'title' => 'Procédure de test '.uniqid(),
            'slug' => 'procedure-de-test-'.uniqid(),
            'criticite' => 'note',
            'status' => $status,
            'author_id' => $author->id,
            'data_owner_id' => $author->id,
        ]);
    }

    /** @return array{0: ArticleAlert, 1: User} the alert and its reporter */
    private function openAlert(?Article $article = null): array
    {
        $reporter = $this->user('lecteur');
        $article ??= $this->article();

        $alert = ArticleAlert::create([
            'filiale_id' => $this->filiale->id,
            'article_id' => $article->id,
            'reported_by' => $reporter->id,
            'type' => 'obsolescence',
            'criticite' => 'moyenne',
            'description' => 'Le mode opératoire ne correspond plus au terrain.',
        ]);

        return [$alert, $reporter];
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'obsolescence',
            'criticite' => 'critique',
            'description' => 'La consigne de comptage ne correspond plus au terrain.',
        ], $overrides);
    }

    // -------------------------------------------------- §7.2 reporting

    /**
     * §7.2 puts this in the hands of "tout collaborateur", so the lowest role
     * in the system must be able to file one.
     */
    public function test_any_authenticated_collaborator_can_report_an_alert(): void
    {
        $article = $this->article();

        $response = $this->actingAs($this->user('lecteur'), 'sanctum')
            ->postJson("/api/v1/articles/{$article->id}/alerts", $this->validPayload());

        $response->assertStatus(201)
            ->assertJsonPath('status', 'ouverte')
            ->assertJsonPath('type', 'obsolescence')
            ->assertJsonPath('criticite', 'critique')
            ->assertJsonPath('article_id', $article->id);

        $this->assertDatabaseHas('article_alerts', [
            'article_id' => $article->id,
            'status' => 'ouverte',
            'taken_by' => null,
            'acknowledged_at' => null,
            'closed_at' => null,
        ]);
    }

    /** A new alert is always `ouverte` even if the client asks for otherwise. */
    public function test_status_cannot_be_set_by_the_client(): void
    {
        $article = $this->article();

        $this->actingAs($this->user('lecteur'), 'sanctum')
            ->postJson(
                "/api/v1/articles/{$article->id}/alerts",
                $this->validPayload(['status' => 'cloturee', 'taken_by' => 999])
            )
            ->assertStatus(201)
            ->assertJsonPath('status', 'ouverte')
            ->assertJsonPath('taken_by', null);
    }

    public function test_description_is_required_and_has_a_minimum_length(): void
    {
        $article = $this->article();
        $actor = $this->actingAs($this->user('lecteur'), 'sanctum');

        $actor->postJson("/api/v1/articles/{$article->id}/alerts", $this->validPayload(['description' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('description');

        // 'trop court' is exactly 10 characters, i.e. the boundary the rule
        // allows — nine is what must be refused.
        $actor->postJson("/api/v1/articles/{$article->id}/alerts", $this->validPayload(['description' => 'trop peu']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('description');
    }

    public function test_type_and_criticite_must_be_valid_enum_values(): void
    {
        $article = $this->article();
        $actor = $this->actingAs($this->user('lecteur'), 'sanctum');

        $actor->postJson("/api/v1/articles/{$article->id}/alerts", $this->validPayload(['type' => 'inexistant']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');

        // 'faible'/'moyenne'/'critique' here — deliberately NOT the article's
        // own golden_rule/note scale, which is a different axis.
        $actor->postJson("/api/v1/articles/{$article->id}/alerts", $this->validPayload(['criticite' => 'golden_rule']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('criticite');
    }

    /**
     * Mirrors ArticleController::show(): an article a lecteur cannot see is
     * simply not there for them, so they cannot file a report against it.
     */
    public function test_a_lecteur_cannot_report_against_an_article_they_cannot_see(): void
    {
        $draft = $this->article('draft');

        $this->actingAs($this->user('lecteur'), 'sanctum')
            ->postJson("/api/v1/articles/{$draft->id}/alerts", $this->validPayload())
            ->assertStatus(404);

        $this->assertDatabaseCount('article_alerts', 0);
    }

    /** A role that can see drafts is not blocked by the rule above. */
    public function test_a_non_lecteur_can_report_against_a_draft(): void
    {
        $draft = $this->article('draft');

        $this->actingAs($this->user('redacteur'), 'sanctum')
            ->postJson("/api/v1/articles/{$draft->id}/alerts", $this->validPayload())
            ->assertStatus(201);
    }

    // ------------------------------------------------------ listing / roles

    public function test_a_collaborator_only_sees_their_own_reports(): void
    {
        [$alert, $reporter] = $this->openAlert();
        $this->openAlert(); // somebody else's

        $response = $this->actingAs($reporter, 'sanctum')->getJson('/api/v1/alerts');

        $response->assertStatus(200)->assertJsonCount(1);
        $this->assertSame($alert->id, $response->json('0.id'));
    }

    /** The "Gardien du Temple" (§6.1) needs the whole queue, not just theirs. */
    public function test_a_data_owner_sees_every_alert_in_the_filiale(): void
    {
        $this->openAlert();
        $this->openAlert();

        $this->actingAs($this->user('data_owner'), 'sanctum')
            ->getJson('/api/v1/alerts')
            ->assertStatus(200)
            ->assertJsonCount(2);
    }

    public function test_an_admin_also_sees_every_alert(): void
    {
        $this->openAlert();
        $this->openAlert();

        $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/v1/alerts')
            ->assertStatus(200)
            ->assertJsonCount(2);
    }

    // ------------------------------------------- §7.3 transitions + gating

    public function test_a_data_owner_acknowledges_an_alert(): void
    {
        [$alert] = $this->openAlert();
        $owner = $this->user('data_owner');

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/alerts/{$alert->id}/acknowledge")
            ->assertStatus(200)
            ->assertJsonPath('status', 'en_cours')
            // `taken_by` is the nested user here, not the bigint: the takenBy
            // relation serializes under the same key as its foreign key. See
            // the SERIALIZATION NOTE on ArticleAlert::reportedBy().
            ->assertJsonPath('taken_by.id', $owner->id);

        $alert->refresh();
        $this->assertNotNull($alert->acknowledged_at);
        $this->assertNull($alert->closed_at);
    }

    public function test_roles_without_the_gate_cannot_acknowledge(): void
    {
        foreach (['lecteur', 'redacteur', 'qualite', 'responsable_departement'] as $role) {
            [$alert] = $this->openAlert();

            $this->actingAs($this->user($role), 'sanctum')
                ->postJson("/api/v1/alerts/{$alert->id}/acknowledge")
                ->assertStatus(403);

            $this->assertSame('ouverte', $alert->fresh()->status->value, "access_role={$role} moved the alert");
        }
    }

    public function test_roles_without_the_gate_cannot_close(): void
    {
        [$alert] = $this->openAlert();
        $this->actingAs($this->user('data_owner'), 'sanctum')
            ->postJson("/api/v1/alerts/{$alert->id}/acknowledge")->assertStatus(200);

        $this->actingAs($this->user('lecteur'), 'sanctum')
            ->postJson("/api/v1/alerts/{$alert->id}/close")
            ->assertStatus(403);

        $this->assertSame('en_cours', $alert->fresh()->status->value);
    }

    public function test_the_full_chain_ouverte_en_cours_cloturee(): void
    {
        [$alert] = $this->openAlert();
        $owner = $this->user('data_owner');

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/alerts/{$alert->id}/acknowledge")->assertStatus(200);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/alerts/{$alert->id}/close")
            ->assertStatus(200)
            ->assertJsonPath('status', 'cloturee')
            // Closing must not reassign the alert: who handled it is part of
            // the audit trail. Nested key — see the note in the test above.
            ->assertJsonPath('taken_by.id', $owner->id);

        $alert->refresh();
        $this->assertNotNull($alert->closed_at);
        $this->assertNotNull($alert->acknowledged_at);
    }

    /** Closing something nobody acknowledged would lose who handled it. */
    public function test_an_open_alert_cannot_be_closed_directly(): void
    {
        [$alert] = $this->openAlert();

        $this->actingAs($this->user('data_owner'), 'sanctum')
            ->postJson("/api/v1/alerts/{$alert->id}/close")
            ->assertStatus(422);

        $this->assertSame('ouverte', $alert->fresh()->status->value);
    }

    public function test_an_alert_cannot_be_acknowledged_twice(): void
    {
        [$alert] = $this->openAlert();
        $owner = $this->user('data_owner');

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/alerts/{$alert->id}/acknowledge")->assertStatus(200);

        $this->actingAs($this->user('data_owner'), 'sanctum')
            ->postJson("/api/v1/alerts/{$alert->id}/acknowledge")
            ->assertStatus(422);

        // The original handler is untouched by the refused second call.
        $this->assertSame($owner->id, $alert->fresh()->taken_by);
    }

    /** `cloturee` is terminal — reopening is a new report, not a transition. */
    public function test_a_closed_alert_is_terminal(): void
    {
        [$alert] = $this->openAlert();
        $owner = $this->user('data_owner');

        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/alerts/{$alert->id}/acknowledge");
        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/alerts/{$alert->id}/close");

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/alerts/{$alert->id}/close")->assertStatus(422);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/alerts/{$alert->id}/acknowledge")->assertStatus(422);

        $this->assertSame('cloturee', $alert->fresh()->status->value);
    }

    // ---------------------------------------- §7.3 Niveau 2 banner exposure

    /**
     * The article must advertise that a revision is under way, so the frontend
     * can show "Procédure en cours de révision opérationnelle" without a
     * second request. Only `en_cours` counts: a report nobody has picked up
     * yet is not a revision in progress, and a closed one is over.
     */
    public function test_article_exposes_is_under_revision_only_while_an_alert_is_en_cours(): void
    {
        $article = $this->article();
        [$alert] = $this->openAlert($article);
        $owner = $this->user('data_owner');

        // ouverte -> not yet a revision
        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/articles/{$article->id}")
            ->assertStatus(200)
            ->assertJsonPath('is_under_revision', false);

        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/alerts/{$alert->id}/acknowledge");

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/articles/{$article->id}")
            ->assertStatus(200)
            ->assertJsonPath('is_under_revision', true);

        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/alerts/{$alert->id}/close");

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/articles/{$article->id}")
            ->assertStatus(200)
            ->assertJsonPath('is_under_revision', false);
    }

    /** The same flag has to survive the list endpoint, which is where the
     *  banner is actually rendered for most users. */
    public function test_the_list_endpoint_reports_the_revision_flag_per_article(): void
    {
        $flagged = $this->article();
        $untouched = $this->article();
        [$alert] = $this->openAlert($flagged);
        $owner = $this->user('data_owner');

        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/alerts/{$alert->id}/acknowledge");

        $response = $this->actingAs($owner, 'sanctum')->getJson('/api/v1/articles');
        $response->assertStatus(200);

        $byId = collect($response->json())->keyBy('id');
        $this->assertTrue($byId[$flagged->id]['is_under_revision']);
        $this->assertFalse($byId[$untouched->id]['is_under_revision']);
    }
}

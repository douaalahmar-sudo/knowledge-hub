<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\SecurityAlertType;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Filiale;
use App\Models\SecurityAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * §10.4's data-exfiltration detector: "l'ouverture d'un volume anormal de
 * documents dans un intervalle réduit (ex: plus de 30 procédures en moins de 2
 * minutes)".
 *
 * The prior consultations are seeded straight into `audit_logs` rather than
 * driven through 30 HTTP requests: the detector's input IS the trail, so
 * writing the trail directly is the honest fixture, and it keeps each test to
 * one real request — the one that crosses the line.
 *
 * Filiale isolation and the admin-only read policy at the *table* level are not
 * asserted here (sqlite has no RLS) — see SecurityAlertRowLevelSecurityTest.
 * What is asserted here is that the alert fires when it should, does not when
 * it should not, and that the endpoint's Gate holds.
 */
class SecurityAnomalyDetectionTest extends TestCase
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

    private function user(?string $accessRole = 'lecteur'): User
    {
        return User::factory()->create([
            'filiale_id' => $this->filiale->id,
            'access_role' => $accessRole,
            'matricule' => 'ANO-'.Str::random(10),
        ]);
    }

    private function article(): Article
    {
        $author = $this->user('redacteur');

        return Article::create([
            'filiale_id' => $this->filiale->id,
            'title' => 'Article de test '.uniqid(),
            'slug' => 'article-de-test-'.uniqid(),
            'criticite' => 'note',
            'status' => 'published',
            'is_active_version' => true,
            'author_id' => $author->id,
            'data_owner_id' => $author->id,
        ]);
    }

    /**
     * Write $count consultation entries dated $at, without going through
     * AuditLogger — these are the history the detector reads, not events under
     * test, and routing them through the logger would trip the detector while
     * building the fixture.
     */
    private function seedConsultations(User $user, int $count, ?Carbon $at = null, ?string $action = null): void
    {
        $at ??= now();
        $action ??= AuditAction::ArticleViewed->value;

        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'filiale_id' => $this->filiale->id,
                'user_id' => $user->id,
                'action' => $action,
                'auditable_type' => Article::class,
                'auditable_id' => (string) Str::uuid(),
                'ip_address' => '196.203.44.12',
                'metadata' => null,
                'created_at' => $at,
            ];
        }

        DB::table('audit_logs')->insert($rows);
    }

    /** One real consultation — the request that may cross the threshold. */
    private function consult(User $user, ?Article $article = null): void
    {
        $article ??= $this->article();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/articles/{$article->id}")
            ->assertStatus(200);
    }

    // ------------------------------------------------------ crossing the line

    /**
     * The shipped default, unmodified: 30 consultations are acceptable ("plus
     * de 30"), and the 31st is the one that trips it.
     */
    public function test_the_thirty_first_consultation_in_the_window_raises_an_alert(): void
    {
        $user = $this->user();
        $article = $this->article();

        $this->seedConsultations($user, 29);
        $this->consult($user, $article);

        $this->assertSame(0, SecurityAlert::count(), 'An alert fired at 30 consultations.');

        $this->consult($user, $article);

        $alert = SecurityAlert::first();

        $this->assertNotNull($alert, 'The 31st consultation in the window raised no alert.');
        $this->assertSame($user->id, $alert->user_id);
        $this->assertSame($this->filiale->id, $alert->filiale_id);
        $this->assertSame(SecurityAlertType::ExcessiveDocumentAccess->value, $alert->alert_type);
        $this->assertSame(31, $alert->details['observed_count']);
        // The settings in force at the time, recorded per-alert because both
        // are configurable and may differ by the time anyone reads this.
        $this->assertSame(30, $alert->details['threshold']);
        $this->assertSame(120, $alert->details['window_seconds']);
        $this->assertSame('196.203.44.12', $alert->details['ip_address']);
    }

    public function test_normal_reading_stays_well_under_the_threshold(): void
    {
        $user = $this->user();
        $article = $this->article();

        // A busy but legitimate morning: several documents, no burst.
        $this->seedConsultations($user, 12);
        $this->consult($user, $article);

        $this->assertSame(0, SecurityAlert::count());
    }

    /** The threshold is configuration, not a constant — §10.4 gives it as "ex:". */
    public function test_the_threshold_is_configurable(): void
    {
        config(['security.anomaly.threshold' => 3]);

        $user = $this->user();
        $article = $this->article();

        $this->seedConsultations($user, 3);
        $this->consult($user, $article);

        $alert = SecurityAlert::first();

        $this->assertNotNull($alert, 'A lowered threshold did not take effect.');
        $this->assertSame(4, $alert->details['observed_count']);
        $this->assertSame(3, $alert->details['threshold']);
    }

    public function test_detection_can_be_switched_off(): void
    {
        config(['security.anomaly.enabled' => false]);

        $user = $this->user();
        $article = $this->article();

        $this->seedConsultations($user, 100);
        $this->consult($user, $article);

        $this->assertSame(0, SecurityAlert::count());
    }

    // ---------------------------------------------------- the rolling window

    public function test_consultations_older_than_the_window_do_not_count(): void
    {
        $user = $this->user();
        $article = $this->article();

        // Well past the 120-second window: yesterday's reading is not a burst.
        $this->seedConsultations($user, 50, now()->subSeconds(150));
        $this->consult($user, $article);

        $this->assertSame(0, SecurityAlert::count(), 'Stale consultations were counted as a burst.');
    }

    /**
     * The window is rolling, not a calendar bucket. Seeded 15 seconds ago but
     * in the PREVIOUS clock minute: a per-minute bucket would count 1 here and
     * see nothing wrong, which is exactly the boundary an aspiration would hide
     * behind.
     */
    public function test_the_window_is_rolling_rather_than_a_calendar_bucket(): void
    {
        $user = $this->user();
        $article = $this->article();

        Carbon::setTestNow(Carbon::parse('2026-08-04 12:01:10'));
        $this->seedConsultations($user, 30, Carbon::parse('2026-08-04 12:00:55'));

        $this->consult($user, $article);

        $this->assertSame(1, SecurityAlert::count(), 'A burst straddling a minute boundary was missed.');
    }

    /** The trailing edge moves too: the same 31 events stop counting as they age out. */
    public function test_the_window_trails_as_time_passes(): void
    {
        $user = $this->user();
        $article = $this->article();

        Carbon::setTestNow(Carbon::parse('2026-08-04 12:00:00'));
        $this->seedConsultations($user, 40, Carbon::parse('2026-08-04 11:59:30'));

        // Five minutes later the burst is history, and one more read is just
        // one more read.
        Carbon::setTestNow(Carbon::parse('2026-08-04 12:05:00'));
        $this->consult($user, $article);

        $this->assertSame(0, SecurityAlert::count());
    }

    // ----------------------------------------------------------- what counts

    public function test_document_file_retrievals_count_as_consultations(): void
    {
        $user = $this->user();
        $article = $this->article();

        $this->seedConsultations($user, 30, null, AuditAction::ArticleFileViewed->value);
        $this->consult($user, $article);

        $this->assertSame(1, SecurityAlert::count());
    }

    /**
     * A rédacteur moving articles through the workflow generates audit volume
     * without opening anything — counting that would make an ordinary editing
     * afternoon look like an exfiltration.
     */
    public function test_workflow_transitions_are_not_counted_as_consultations(): void
    {
        $user = $this->user();
        $article = $this->article();

        $this->seedConsultations($user, 60, null, AuditAction::ArticleSubmitted->value);
        $this->consult($user, $article);

        $this->assertSame(0, SecurityAlert::count());
    }

    public function test_another_users_consultations_do_not_count_towards_this_one(): void
    {
        $user = $this->user();
        $colleague = $this->user();
        $article = $this->article();

        $this->seedConsultations($colleague, 60);
        $this->consult($user, $article);

        $this->assertSame(0, SecurityAlert::count(), 'One account\'s reading tripped another\'s alarm.');
    }

    // -------------------------------------------------------- one per window

    /**
     * Past the threshold every further view would otherwise raise another
     * alert; a hundred rows describing one incident is how a real signal gets
     * buried.
     */
    public function test_one_alert_per_window_not_one_per_consultation(): void
    {
        $user = $this->user();
        $article = $this->article();

        $this->seedConsultations($user, 30);

        foreach (range(1, 5) as $ignored) {
            $this->consult($user, $article);
        }

        $this->assertSame(1, SecurityAlert::count(), 'The same burst raised repeated alerts.');
    }

    /** But a fresh burst after the window has passed is a fresh incident. */
    public function test_a_later_burst_raises_a_new_alert(): void
    {
        $user = $this->user();
        $article = $this->article();

        Carbon::setTestNow(Carbon::parse('2026-08-04 12:00:00'));
        $this->seedConsultations($user, 30, Carbon::parse('2026-08-04 11:59:30'));
        $this->consult($user, $article);

        $this->assertSame(1, SecurityAlert::count());

        Carbon::setTestNow(Carbon::parse('2026-08-04 12:30:00'));
        $this->seedConsultations($user, 30, Carbon::parse('2026-08-04 12:29:30'));
        $this->consult($user, $article);

        $this->assertSame(2, SecurityAlert::count(), 'A separate later burst was suppressed.');
    }

    // ------------------------------------------------------------ the trail

    /** The alert is itself a security-relevant event (§10.4). */
    public function test_the_alert_is_journalled_on_the_audit_trail(): void
    {
        $user = $this->user();
        $article = $this->article();

        $this->seedConsultations($user, 30);
        $this->consult($user, $article);

        $alert = SecurityAlert::first();
        $entry = AuditLog::where('action', AuditAction::SecurityAlertRaised->value)->first();

        $this->assertNotNull($entry, 'The alert never reached the audit trail.');
        $this->assertSame(SecurityAlert::class, $entry->auditable_type);
        $this->assertSame($alert->id, $entry->auditable_id);
        $this->assertSame($user->id, $entry->metadata['subject_user_id']);
        $this->assertSame(31, $entry->metadata['observed_count']);

        // And journalling the alert did not itself re-trip the detector.
        $this->assertSame(1, SecurityAlert::count());
    }

    // ------------------------------------------------------- read endpoint

    /**
     * These name a colleague as a possible exfiltration risk. Everyone except
     * admin is refused — including data_owner, who *can* read the audit trail.
     */
    public function test_only_an_admin_can_read_the_security_alerts(): void
    {
        foreach (['lecteur', 'redacteur', 'responsable_departement', 'qualite', 'data_owner'] as $role) {
            $response = $this->actingAs($this->user($role), 'sanctum')
                ->getJson('/api/v1/security-alerts');

            $response->assertStatus(403)
                ->assertJsonPath('message', 'Seul un administrateur peut consulter les alertes de sécurité.');

            $this->assertArrayNotHasKey('data', $response->json(), "A {$role} received alert data.");
        }
    }

    public function test_an_admin_sees_the_raised_alerts_with_their_subject(): void
    {
        $user = $this->user();
        $article = $this->article();

        $this->seedConsultations($user, 30);
        $this->consult($user, $article);

        $this->actingAs($this->user('admin'), 'sanctum')
            ->getJson('/api/v1/security-alerts')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.alert_type', SecurityAlertType::ExcessiveDocumentAccess->value)
            ->assertJsonPath('data.0.user_id', $user->id)
            ->assertJsonPath('data.0.user.matricule', $user->matricule)
            ->assertJsonPath('data.0.details.observed_count', 31);
    }

    public function test_the_alert_list_is_filterable(): void
    {
        $suspect = $this->user();
        $other = $this->user();
        $article = $this->article();

        $this->seedConsultations($suspect, 30);
        $this->consult($suspect, $article);

        $admin = $this->user('admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/security-alerts?user_id='.$suspect->id)
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/security-alerts?user_id='.$other->id)
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/security-alerts?alert_type=exfiltration_massive')
            ->assertStatus(422)
            ->assertJsonValidationErrors('alert_type');
    }
}

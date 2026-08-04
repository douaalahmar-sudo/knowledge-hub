<?php

namespace Tests\Feature;

use App\Enums\SecurityAlertType;
use App\Enums\UserRole;
use App\Support\Database\AccessRoleContext;
use App\Support\Database\FilialeContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The RLS policy set on `security_alerts` (§10.4), and — more importantly — the
 * privilege elevation SecurityAnomalyDetector depends on, proven against a real
 * PostgreSQL connection as the restricted `kh_app` role.
 *
 * The elevation is the part that cannot be checked anywhere else and would fail
 * silently if it broke. The detector runs inside the request of the user being
 * detected, who cannot read `audit_logs` at all; on sqlite, where the whole
 * suite runs, there is no policy to trip over, so SecurityAnomalyDetectionTest
 * would keep passing while §10.4 never fired a single alert in production.
 * These tests are the only thing standing between that outcome and shipping.
 */
class SecurityAlertRowLevelSecurityTest extends TestCase
{
    private const CONNECTION = 'pgsql_rls_test';

    private string $filialeA;

    private string $filialeB;

    private string $alertA;

    private string $alertB;

    private int $userA;

    private int $userB;

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

        $this->assertRoleCannotBypassRls();

        DB::connection(self::CONNECTION)->beginTransaction();

        $this->seedTwoFiliales();
    }

    protected function tearDown(): void
    {
        $connection = DB::connection(self::CONNECTION);

        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        FilialeContext::forget(self::CONNECTION);
        AccessRoleContext::forget(self::CONNECTION);

        parent::tearDown();
    }

    // ------------------------------------------------------ filiale isolation

    #[Test]
    public function an_admin_in_filiale_a_cannot_read_filiale_bs_alerts(): void
    {
        $this->actAs($this->filialeA, UserRole::Admin);

        $rows = DB::connection(self::CONNECTION)->select('select id, filiale_id from security_alerts');

        $this->assertCount(1, $rows, 'An admin saw another filiale\'s security alerts.');
        $this->assertSame($this->alertA, $rows[0]->id);
    }

    #[Test]
    public function filiale_a_cannot_plant_an_alert_in_filiale_b(): void
    {
        $this->actAs($this->filialeA, UserRole::Admin);

        $this->expectException(QueryException::class);

        // Fabricating an alert against someone in another filiale — discrediting
        // a colleague — is what the append policy's WITH CHECK stops.
        $this->insertAlert($this->filialeB, $this->userB);
    }

    // --------------------------------------------------------- the role split

    /**
     * Narrower than `audit_logs`, which admits data_owner too: an alert naming
     * a colleague as a possible exfiltration risk is a DSI matter.
     */
    #[Test]
    public function nobody_below_admin_can_read_the_alerts_including_data_owner(): void
    {
        $roles = [
            UserRole::Lecteur,
            UserRole::Redacteur,
            UserRole::ResponsableDepartement,
            UserRole::Qualite,
            UserRole::DataOwner,
        ];

        foreach ($roles as $role) {
            $this->actAs($this->filialeA, $role);

            $count = DB::connection(self::CONNECTION)->scalar('select count(*) from security_alerts');

            $this->assertSame(0, (int) $count, "A {$role->value} session read the security alerts.");
        }
    }

    /**
     * The subject of an alert must be able to write it — the detector runs as
     * them — and must never see it. Anything else and either the detector
     * cannot record, or the person being watched knows they are being watched.
     */
    #[Test]
    public function the_subject_of_an_alert_can_write_it_but_never_read_it(): void
    {
        $this->actAs($this->filialeA, UserRole::Lecteur);

        $id = $this->insertAlert($this->filialeA, $this->userA);

        $this->assertSame(0, (int) DB::connection(self::CONNECTION)
            ->scalar('select count(*) from security_alerts where id = ?', [$id]));

        $this->actAs($this->filialeA, UserRole::Admin);

        $this->assertSame(1, (int) DB::connection(self::CONNECTION)
            ->scalar('select count(*) from security_alerts where id = ?', [$id]));
    }

    #[Test]
    public function an_alert_cannot_be_rewritten_or_erased(): void
    {
        $this->actAs($this->filialeA, UserRole::Admin);

        $updated = DB::connection(self::CONNECTION)->update(
            'update security_alerts set acknowledged_at = now() where id = ?', [$this->alertA]
        );

        $deleted = DB::connection(self::CONNECTION)
            ->delete('delete from security_alerts where id = ?', [$this->alertA]);

        $this->assertSame(0, $updated);
        $this->assertSame(0, $deleted);

        // This is also the limitation the migration documents: acknowledging an
        // alert needs an admin-scoped UPDATE policy that does not exist yet.
        // The assertion is here so that gap is impossible to forget.
        $this->assertSame(1, (int) DB::connection(self::CONNECTION)
            ->scalar('select count(*) from security_alerts where acknowledged_at is null'));
    }

    // ------------------------------------------- the detector's elevation

    /**
     * The mechanism SecurityAnomalyDetector::inspect() relies on. Without the
     * elevation the count below is 0 and no alert can ever be raised for the
     * users who most need detecting — every ordinary reader in the Hub.
     */
    #[Test]
    public function the_detector_can_count_a_lecteurs_consultations_only_while_elevated(): void
    {
        $this->actAs($this->filialeA, UserRole::Lecteur);
        $this->seedConsultations($this->filialeA, $this->userA, 5);

        $connection = DB::connection(self::CONNECTION);

        $query = 'select count(*) from audit_logs where user_id = ? '
            ."and action in ('article.viewed','article.file_viewed')";

        // As themselves: blind, by design.
        $this->assertSame(0, (int) $connection->scalar($query, [$this->userA]));

        // Elevated for the duration of the closure — the detector's count.
        $counted = AccessRoleContext::runAs(
            UserRole::Admin->value,
            fn () => (int) $connection->scalar($query, [$this->userA]),
            self::CONNECTION
        );

        $this->assertSame(5, $counted, 'The elevated detector query could not see the trail it must count.');
    }

    /** The elevation must not outlive the closure, or it is a privilege leak. */
    #[Test]
    public function the_elevation_is_restored_afterwards_even_on_failure(): void
    {
        $this->actAs($this->filialeA, UserRole::Lecteur);

        try {
            AccessRoleContext::runAs(
                UserRole::Admin->value,
                fn () => throw new \RuntimeException('detector blew up'),
                self::CONNECTION
            );
        } catch (\RuntimeException) {
            // Expected — the point is what the session looks like afterwards.
        }

        $this->assertSame(
            UserRole::Lecteur->value,
            AccessRoleContext::current(self::CONNECTION),
            'A failed detection left the session elevated.'
        );

        // And the elevation really is gone at the policy level, not just in the
        // setting: the same read is blind again.
        $this->assertSame(0, (int) DB::connection(self::CONNECTION)
            ->scalar('select count(*) from security_alerts'));
    }

    // ---------------------------------------------------------------- helpers

    private function actAs(string $filialeId, UserRole $role): void
    {
        FilialeContext::set($filialeId, self::CONNECTION);
        AccessRoleContext::set($role->value, self::CONNECTION);
    }

    private function assertRoleCannotBypassRls(): void
    {
        $role = DB::connection(self::CONNECTION)->selectOne(
            'select rolsuper, rolbypassrls from pg_roles where rolname = current_user'
        );

        $this->assertFalse((bool) $role->rolsuper, 'The RLS test connects as a SUPERUSER, which bypasses every policy.');
        $this->assertFalse((bool) $role->rolbypassrls, 'The RLS test role has BYPASSRLS, which bypasses every policy.');
    }

    private function seedTwoFiliales(): void
    {
        $this->filialeA = $this->insertFiliale('Filiale A');
        $this->filialeB = $this->insertFiliale('Filiale B');

        $this->actAs($this->filialeB, UserRole::Lecteur);
        $this->userB = $this->insertUser($this->filialeB);
        $this->alertB = $this->insertAlert($this->filialeB, $this->userB);

        $this->actAs($this->filialeA, UserRole::Lecteur);
        $this->userA = $this->insertUser($this->filialeA);
        $this->alertA = $this->insertAlert($this->filialeA, $this->userA);
    }

    private function insertFiliale(string $name): string
    {
        $id = (string) Str::uuid();

        DB::connection(self::CONNECTION)->table('filiales')->insert([
            'id' => $id,
            'name' => $name.' '.Str::random(6),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertUser(string $filialeId): int
    {
        $suffix = Str::random(8);

        return DB::connection(self::CONNECTION)->table('users')->insertGetId([
            'name' => 'RLS Alert '.$suffix,
            'email' => "rls.secalert.{$suffix}@example.test",
            'matricule' => 'RLSS-'.$suffix,
            'password' => bcrypt('password123'),
            'filiale_id' => $filialeId,
            'access_role' => UserRole::Lecteur->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertAlert(string $filialeId, int $userId): string
    {
        $id = (string) Str::uuid();

        DB::connection(self::CONNECTION)->table('security_alerts')->insert([
            'id' => $id,
            'filiale_id' => $filialeId,
            'user_id' => $userId,
            'alert_type' => SecurityAlertType::ExcessiveDocumentAccess->value,
            'details' => json_encode(['observed_count' => 31, 'threshold' => 30]),
            'created_at' => now(),
        ]);

        return $id;
    }

    private function seedConsultations(string $filialeId, int $userId, int $count): void
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'filiale_id' => $filialeId,
                'user_id' => $userId,
                'action' => 'article.viewed',
                'auditable_type' => 'App\Models\Article',
                'auditable_id' => (string) Str::uuid(),
                'ip_address' => '196.203.44.12',
                'created_at' => now(),
            ];
        }

        DB::connection(self::CONNECTION)->table('audit_logs')->insert($rows);
    }
}

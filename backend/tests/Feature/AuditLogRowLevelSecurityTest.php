<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Support\Database\AccessRoleContext;
use App\Support\Database\FilialeContext;
use App\Support\Database\RowLevelSecurity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The RLS policy set on `audit_logs` (§10.4), proven the same way it is proven
 * for `articles` and `article_alerts`: raw SQL over a real PostgreSQL
 * connection opened as the restricted `kh_app` role, with no Eloquent scope, no
 * `where filiale_id = ?` and no model in the way.
 *
 * This table has a different — and stricter — shape than the others, so there
 * are three separate properties to prove, not one:
 *
 *   1. Filiale isolation, as everywhere else.
 *   2. SELECT is additionally restricted to admin/data_owner, while INSERT is
 *      open to every role. A lecteur must be able to write the record of their
 *      own consultation and must not be able to read anybody's.
 *   3. No UPDATE and no DELETE for anyone at all — the append-only property
 *      that makes the trail evidence instead of a mutable table.
 *
 * None of this can be asserted in AuditLogTest: that suite runs on sqlite,
 * which has no row-level security, so every assertion here would pass there for
 * the wrong reason.
 */
class AuditLogRowLevelSecurityTest extends TestCase
{
    private const CONNECTION = 'pgsql_rls_test';

    /**
     * DDL connection for the mutation check — policies belong to the table
     * owner, and `kh_app` deliberately cannot create or drop them.
     *
     * Configured at runtime rather than reusing `pgsql_admin` from
     * config/database.php: that connection reads DB_DATABASE, which phpunit.xml
     * pins to `:memory:` for the sqlite suite, so under PHPUnit it points at a
     * database that does not exist. This one is derived from the same
     * RLS_TEST_DB_* variables as the restricted connection above, so both sides
     * of the mutation check are guaranteed to be talking about the same
     * database.
     */
    private const OWNER_CONNECTION = 'pgsql_rls_owner';

    private string $filialeA;

    private string $filialeB;

    private string $entryA;

    private string $entryB;

    private int $userA;

    /** Filiale B's user, captured while acting as B — see the alerts test. */
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

        // set_config(..., is_local => false) survives a rollback; clear both or
        // the pooled connection carries a filiale — or worse, a role — into the
        // next test.
        FilialeContext::forget(self::CONNECTION);
        AccessRoleContext::forget(self::CONNECTION);

        parent::tearDown();
    }

    // ------------------------------------------------------ 1. filiale isolation

    #[Test]
    public function an_admin_in_filiale_a_cannot_read_filiale_bs_audit_trail(): void
    {
        $this->actAs($this->filialeA, UserRole::Admin);

        // Exactly the query AuditLogController::index() issues — no filiale
        // predicate anywhere.
        $rows = DB::connection(self::CONNECTION)->select('select id, filiale_id from audit_logs');

        $this->assertCount(1, $rows, 'An admin in filiale A saw another filiale\'s audit trail.');
        $this->assertSame($this->entryA, $rows[0]->id);
    }

    #[Test]
    public function filiale_bs_entry_is_invisible_even_when_addressed_by_its_primary_key(): void
    {
        $this->actAs($this->filialeA, UserRole::Admin);

        $count = DB::connection(self::CONNECTION)
            ->scalar('select count(*) from audit_logs where id = ?', [$this->entryB]);

        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function filiale_a_cannot_file_an_entry_into_filiale_b(): void
    {
        $this->actAs($this->filialeA, UserRole::Admin);

        $this->expectException(QueryException::class);

        // The append policy's WITH CHECK clause rejects this outright. Planting
        // a false entry in another filiale's trail is the attack this stops.
        $this->insertEntry($this->filialeB, $this->userB, 'article.viewed');
    }

    #[Test]
    public function a_session_with_no_filiale_context_sees_no_entries(): void
    {
        FilialeContext::forget(self::CONNECTION);
        AccessRoleContext::set(UserRole::Admin->value, self::CONNECTION);

        $count = DB::connection(self::CONNECTION)->scalar('select count(*) from audit_logs');

        $this->assertSame(0, (int) $count, 'Audit entries leaked to a session with no filiale.');
    }

    // ------------------------------------------------------- 2. the role split

    /**
     * The requirement that makes this table different from every other one: a
     * security log is not user-facing data. Reaching the table directly, in the
     * caller's own filiale, with a perfectly valid session — and still seeing
     * nothing.
     */
    #[Test]
    public function an_ordinary_user_cannot_read_the_audit_trail_of_their_own_filiale(): void
    {
        foreach ([UserRole::Lecteur, UserRole::Redacteur, UserRole::ResponsableDepartement, UserRole::Qualite] as $role) {
            $this->actAs($this->filialeA, $role);

            $count = DB::connection(self::CONNECTION)->scalar('select count(*) from audit_logs');

            $this->assertSame(0, (int) $count, "A {$role->value} session read the audit log.");
        }
    }

    #[Test]
    public function a_session_with_no_access_role_reads_nothing(): void
    {
        FilialeContext::set($this->filialeA, self::CONNECTION);
        AccessRoleContext::forget(self::CONNECTION);

        $count = DB::connection(self::CONNECTION)->scalar('select count(*) from audit_logs');

        // Fails closed, exactly like an unset filiale: a queue worker or console
        // command that has not declared who it is sees no security log.
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function both_privileged_roles_can_read_their_filiales_trail(): void
    {
        foreach ([UserRole::Admin, UserRole::DataOwner] as $role) {
            $this->actAs($this->filialeA, $role);

            $count = DB::connection(self::CONNECTION)->scalar('select count(*) from audit_logs');

            $this->assertSame(1, (int) $count, "A {$role->value} could not read the audit log.");
        }
    }

    /**
     * The inverse of the rule above, and the reason SELECT and INSERT are
     * separate policies: if appending were privileged too, the log would only
     * ever contain the actions of the people allowed to read it.
     */
    #[Test]
    public function an_ordinary_user_can_still_append_the_record_of_their_own_consultation(): void
    {
        $this->actAs($this->filialeA, UserRole::Lecteur);

        $id = $this->insertEntry($this->filialeA, $this->userA, 'article.viewed');

        // The writer cannot read back what they just wrote — correct, and worth
        // pinning: AuditLogger must never depend on reading its own insert.
        $this->assertSame(0, (int) DB::connection(self::CONNECTION)
            ->scalar('select count(*) from audit_logs where id = ?', [$id]));

        // An admin in the same filiale sees it, so the row is genuinely there.
        $this->actAs($this->filialeA, UserRole::Admin);

        $this->assertSame(1, (int) DB::connection(self::CONNECTION)
            ->scalar('select count(*) from audit_logs where id = ?', [$id]));
    }

    // -------------------------------------------------------- 3. append-only

    /**
     * No UPDATE or DELETE policy exists, and under FORCE RLS a command with no
     * permissive policy matches no rows. Nobody can rewrite or erase history
     * through SQL — not an admin, not the application role.
     */
    #[Test]
    public function nobody_can_rewrite_or_erase_an_entry(): void
    {
        $this->actAs($this->filialeA, UserRole::Admin);

        $updated = DB::connection(self::CONNECTION)->update(
            "update audit_logs set action = 'article.viewed', user_id = null where id = ?",
            [$this->entryA]
        );

        $deleted = DB::connection(self::CONNECTION)
            ->delete('delete from audit_logs where id = ?', [$this->entryA]);

        $this->assertSame(0, $updated, 'An audit entry was rewritten.');
        $this->assertSame(0, $deleted, 'An audit entry was deleted.');

        // And the row is genuinely untouched.
        $row = DB::connection(self::CONNECTION)
            ->selectOne('select action, user_id from audit_logs where id = ?', [$this->entryA]);

        $this->assertSame('article.file_viewed', $row->action);
        $this->assertSame($this->userA, (int) $row->user_id);
    }

    // ----------------------------------------------------- 4. mutation check

    /**
     * Proof that the role half of the read policy is load-bearing rather than
     * incidental. "A lecteur sees no rows" is otherwise equally consistent with
     * the policy working and with the seeding being broken — the failure mode
     * these tests exist to catch is precisely one where everything looks fine.
     *
     * The mutation is to REPLACE the policy with the filiale-only one every
     * other table gets, not to drop it: dropping the sole SELECT policy leaves
     * the command with no permissive policy at all, which denies everyone and
     * would make this test pass while proving nothing. Substituting the naive
     * policy is the mistake a real refactor would actually make — reaching for
     * RowLevelSecurity::enable() because that is what the other twelve tables
     * call — and it is the one that leaks.
     *
     * The seeding transaction is rolled back first: policy DDL takes an ACCESS
     * EXCLUSIVE lock and the open transaction's inserts hold a conflicting one
     * on the same table, so it would block until the transaction ended. The
     * fixture is therefore re-created through the owner connection, which is a
     * superuser and bypasses RLS — the only place in this file that is true,
     * and the reason every assertion below is still made through the
     * restricted connection.
     */
    #[Test]
    public function substituting_the_ordinary_filiale_policy_opens_the_exact_leak(): void
    {
        $connection = DB::connection(self::CONNECTION);
        $owner = $this->ownerConnection();

        $connection->rollBack();

        $marker = $this->insertEntryAsOwner($this->filialeA, 'audit.mutation_probe');

        // Two nested finallys, not one: the probe fixture is committed, so a
        // failed assertion anywhere below must still both restore the policy
        // and remove the rows. An earlier version cleaned up on the success
        // path only, and a single failing run left an orphaned audit entry in
        // the development database that no DELETE policy allows removing.
        try {
            try {
                $owner->statement('DROP POLICY '.RowLevelSecurity::LOG_READ_POLICY.' ON audit_logs');
                $owner->statement(
                    'CREATE POLICY '.RowLevelSecurity::LOG_READ_POLICY.' ON audit_logs FOR SELECT '
                    .'USING (filiale_id = '.RowLevelSecurity::contextExpression().'::uuid)'
                );

                $this->actAs($this->filialeA, UserRole::Lecteur);

                $this->assertSame(
                    1,
                    (int) $connection->scalar('select count(*) from audit_logs where id = ?', [$marker]),
                    'A filiale-only read policy did not leak the audit trail to a lecteur — the role '
                    .'assertions above are not proving what they claim to prove.'
                );
            } finally {
                // Restored through the same helper the migration calls, so what
                // goes back is the shipped policy, not a hand-written copy.
                RowLevelSecurity::enableSecurityLog(
                    'audit_logs',
                    [UserRole::Admin->value, UserRole::DataOwner->value],
                    self::OWNER_CONNECTION
                );
            }

            // Re-verified after restoring: same session, same query, sealed.
            $this->actAs($this->filialeA, UserRole::Lecteur);

            $this->assertSame(0, (int) $connection->scalar(
                'select count(*) from audit_logs where id = ?', [$marker]
            ), 'The restored policy did not seal the leak.');

            $this->actAs($this->filialeA, UserRole::Admin);

            $this->assertSame(1, (int) $connection->scalar(
                'select count(*) from audit_logs where id = ?', [$marker]
            ), 'The restored policy also locked out the roles that are supposed to read.');
        } finally {
            $this->cleanUpProbe($owner, $marker);
        }
    }

    /**
     * The same check for the append-only property, whose proof is an *absence*
     * — no UPDATE policy exists — and absences are the easiest thing to assert
     * accidentally. Adding one makes the entries rewritable; removing it again
     * makes them evidence.
     */
    #[Test]
    public function adding_an_update_policy_makes_the_trail_rewritable(): void
    {
        $connection = DB::connection(self::CONNECTION);
        $owner = $this->ownerConnection();

        $connection->rollBack();

        $marker = $this->insertEntryAsOwner($this->filialeA, 'audit.mutation_probe');

        $this->actAs($this->filialeA, UserRole::Admin);

        // Same two-level cleanup as the read probe above, for the same reason.
        try {
            try {
                $owner->statement(
                    'CREATE POLICY audit_mutation_probe ON audit_logs FOR UPDATE USING (true) WITH CHECK (true)'
                );

                $updated = $connection->update(
                    "update audit_logs set action = 'article.viewed' where id = ?", [$marker]
                );

                $this->assertSame(
                    1,
                    $updated,
                    'An UPDATE policy did not make the row writable — the immutability assertion '
                    .'above is not proving what it claims to prove.'
                );
            } finally {
                $owner->statement('DROP POLICY IF EXISTS audit_mutation_probe ON audit_logs');
            }

            $this->assertSame(0, $connection->update(
                "update audit_logs set action = 'article.file_viewed' where id = ?", [$marker]
            ), 'The trail stayed writable after the probe policy was removed.');
        } finally {
            $this->cleanUpProbe($owner, $marker);
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The table owner's connection, configured on first use from the same
     * RLS_TEST_DB_* variables as the restricted one. Skips the calling test
     * rather than failing it when the owner credentials are not available —
     * the nine assertions above still run, they just lose their mutation proof.
     */
    private function ownerConnection(): \Illuminate\Database\Connection
    {
        $restricted = config('database.connections.'.self::CONNECTION);

        config([
            'database.connections.'.self::OWNER_CONNECTION => array_merge($restricted, [
                'username' => env('RLS_TEST_OWNER_USERNAME', env('DB_ADMIN_USERNAME', env('DB_USERNAME', 'postgres'))),
                'password' => env('RLS_TEST_OWNER_PASSWORD', env('DB_ADMIN_PASSWORD', env('DB_PASSWORD', ''))),
            ]),
        ]);

        DB::purge(self::OWNER_CONNECTION);

        try {
            $connection = DB::connection(self::OWNER_CONNECTION);
            $connection->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped(
                'The table owner connection is unavailable ('.$e->getMessage().'), so the policy '
                .'cannot be dropped and restored. Set RLS_TEST_OWNER_USERNAME / '
                .'RLS_TEST_OWNER_PASSWORD to the role that owns `audit_logs`.'
            );
        }

        return $connection;
    }

    /**
     * The mutation probes commit their fixture, and `audit_logs` refuses DELETE
     * to `kh_app` by design, so cleanup necessarily goes through the owner.
     */
    private function cleanUpProbe(\Illuminate\Database\Connection $owner, string $marker): void
    {
        $owner->delete('delete from audit_logs where id = ?', [$marker]);
        $owner->delete('delete from users where id = ?', [$this->probeUserId]);
        $owner->delete('delete from filiales where id = ?', [$this->probeFilialeId]);
    }

    /** Publish both settings, the way SetTenantContext does per request. */
    private function actAs(string $filialeId, UserRole $role): void
    {
        FilialeContext::set($filialeId, self::CONNECTION);
        AccessRoleContext::set($role->value, self::CONNECTION);
    }

    /**
     * The whole scheme rests on the application role not being exempt from RLS:
     * PostgreSQL skips every policy for a SUPERUSER or BYPASSRLS role, silently.
     */
    private function assertRoleCannotBypassRls(): void
    {
        $role = DB::connection(self::CONNECTION)->selectOne(
            'select rolsuper, rolbypassrls from pg_roles where rolname = current_user'
        );

        $this->assertFalse((bool) $role->rolsuper, 'The RLS test connects as a SUPERUSER, which bypasses every policy.');
        $this->assertFalse((bool) $role->rolbypassrls, 'The RLS test role has BYPASSRLS, which bypasses every policy.');
    }

    /** One filiale, one user and one audit entry on each side. */
    private function seedTwoFiliales(): void
    {
        $this->filialeA = $this->insertFiliale('Filiale A');
        $this->filialeB = $this->insertFiliale('Filiale B');

        // Each row is written while acting as its own filiale — the only way
        // the append policy's WITH CHECK clause allows it. The role only has to
        // be one that may insert, which is all of them.
        $this->actAs($this->filialeB, UserRole::Lecteur);
        $this->userB = $this->insertUser($this->filialeB);
        $this->entryB = $this->insertEntry($this->filialeB, $this->userB, 'article.viewed');

        $this->actAs($this->filialeA, UserRole::Lecteur);
        $this->userA = $this->insertUser($this->filialeA);
        $this->entryA = $this->insertEntry($this->filialeA, $this->userA, 'article.file_viewed');
    }

    private function insertFiliale(string $name): string
    {
        $id = (string) Str::uuid();

        // `filiales` is not RLS-protected: it is the lookup table the policies
        // resolve against.
        DB::connection(self::CONNECTION)->table('filiales')->insert([
            'id' => $id,
            'name' => $name.' '.Str::random(6),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertUser(string $filialeId, ?string $connection = null): int
    {
        $suffix = Str::random(8);

        return DB::connection($connection ?? self::CONNECTION)->table('users')->insertGetId([
            'name' => 'RLS Audit '.$suffix,
            'email' => "rls.audit.{$suffix}@example.test",
            'matricule' => 'RLSD-'.$suffix,
            'password' => bcrypt('password123'),
            'filiale_id' => $filialeId,
            'access_role' => UserRole::Lecteur->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertEntry(string $filialeId, int $userId, string $action, ?string $connection = null): string
    {
        $id = (string) Str::uuid();

        DB::connection($connection ?? self::CONNECTION)->table('audit_logs')->insert([
            'id' => $id,
            'filiale_id' => $filialeId,
            'user_id' => $userId,
            'action' => $action,
            'auditable_type' => 'App\Models\Article',
            'auditable_id' => (string) Str::uuid(),
            'ip_address' => '196.203.44.12',
            'metadata' => json_encode(['format' => 'pdf']),
            'created_at' => now(),
        ]);

        return $id;
    }

    /** Rows created for a mutation probe, cleaned up by cleanUpProbe(). */
    private int $probeUserId;

    private string $probeFilialeId;

    /**
     * Committed fixture for a mutation check, written as the owner: the
     * restricted connection's transaction — and with it everything seedTwoFiliales()
     * created — has been rolled back by the time this runs, so the filiale has
     * to be re-created under the same id the assertions act as.
     */
    private function insertEntryAsOwner(string $filialeId, string $action): string
    {
        $this->probeFilialeId = $filialeId;

        DB::connection(self::OWNER_CONNECTION)->table('filiales')->insertOrIgnore([
            'id' => $filialeId,
            'name' => 'Filiale mutation '.Str::random(6),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->probeUserId = $this->insertUser($filialeId, self::OWNER_CONNECTION);

        return $this->insertEntry($filialeId, $this->probeUserId, $action, self::OWNER_CONNECTION);
    }
}

<?php

namespace Tests\Feature;

use App\Support\Database\FilialeContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The filiale_isolation policy on `article_alerts`, proven the same way it is
 * proven for `articles` (see FilialeRowLevelSecurityTest): raw SQL over a real
 * PostgreSQL connection opened as the restricted `kh_app` role, with no
 * Eloquent scope, no `where filiale_id = ?` and no model in the way.
 *
 * This matters more for alerts than for most tables: a signalement is a
 * collaborator naming a defect in their own filiale's procedures, and
 * ArticleAlertController deliberately applies no filiale filter of its own —
 * it relies entirely on this policy. If the policy were missing, the
 * "data_owner sees every alert" branch of index() would hand a Process Owner
 * every other filiale's reports, and the application-level tests would still
 * pass, since they run on sqlite where RLS does not exist.
 */
class ArticleAlertRowLevelSecurityTest extends TestCase
{
    private const CONNECTION = 'pgsql_rls_test';

    private string $filialeA;

    private string $filialeB;

    private string $alertA;

    private string $alertB;

    /**
     * Filiale B's article and reporter, captured during seeding while acting as
     * B. They cannot be looked up later from a filiale A session — the policy
     * hides them from that very query — and switching context to fetch them
     * would defeat the point of the insert test below.
     */
    private string $articleB;

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

        // set_config(..., is_local => false) survives a rollback; clear it so the
        // pooled connection does not carry a filiale into the next test.
        FilialeContext::forget(self::CONNECTION);

        parent::tearDown();
    }

    #[Test]
    public function a_process_owner_in_filiale_a_cannot_read_filiale_bs_alerts(): void
    {
        FilialeContext::set($this->filialeA, self::CONNECTION);

        // This is exactly the query ArticleAlertController::index() issues for a
        // data_owner — no filiale predicate anywhere.
        $rows = DB::connection(self::CONNECTION)->select('select id, filiale_id from article_alerts');

        $this->assertCount(1, $rows, 'A session in filiale A saw alerts outside its filiale.');
        $this->assertSame($this->alertA, $rows[0]->id);
        $this->assertSame($this->filialeA, $rows[0]->filiale_id);
    }

    #[Test]
    public function filiale_bs_alert_is_invisible_even_when_addressed_by_its_primary_key(): void
    {
        FilialeContext::set($this->filialeA, self::CONNECTION);

        // Route-model binding on /alerts/{alert} resolves by primary key, so
        // this is the shape that would leak if the policy were absent.
        $count = DB::connection(self::CONNECTION)
            ->scalar('select count(*) from article_alerts where id = ?', [$this->alertB]);

        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function filiale_a_cannot_acknowledge_or_delete_filiale_bs_alert(): void
    {
        FilialeContext::set($this->filialeA, self::CONNECTION);

        // The write half of acknowledge(): claiming somebody else's alert.
        $updated = DB::connection(self::CONNECTION)->update(
            "update article_alerts set status = 'en_cours' where id = ?",
            [$this->alertB]
        );

        $deleted = DB::connection(self::CONNECTION)
            ->delete('delete from article_alerts where id = ?', [$this->alertB]);

        $this->assertSame(0, $updated, 'An UPDATE reached across filiales.');
        $this->assertSame(0, $deleted, 'A DELETE reached across filiales.');

        // And the row is genuinely untouched, as seen from filiale B.
        FilialeContext::set($this->filialeB, self::CONNECTION);

        $status = DB::connection(self::CONNECTION)
            ->scalar('select status from article_alerts where id = ?', [$this->alertB]);

        $this->assertSame('ouverte', $status);
    }

    #[Test]
    public function filiale_a_cannot_file_an_alert_into_filiale_b(): void
    {
        FilialeContext::set($this->filialeA, self::CONNECTION);

        $this->expectException(QueryException::class);

        // The policy's WITH CHECK clause rejects this insert outright. Both ids
        // come from seeding, so the session stays in filiale A throughout.
        $this->insertAlert($this->articleB, $this->userB, $this->filialeB);
    }

    #[Test]
    public function a_session_with_no_filiale_context_sees_no_alerts(): void
    {
        FilialeContext::forget(self::CONNECTION);

        $count = DB::connection(self::CONNECTION)->scalar('select count(*) from article_alerts');

        $this->assertSame(0, (int) $count, 'Alerts leaked to a session with no filiale context.');
    }

    /**
     * The whole scheme rests on the application role not being exempt from RLS.
     * PostgreSQL skips every policy for a SUPERUSER or BYPASSRLS role, silently,
     * which would make every assertion above pass for the wrong reason.
     */
    private function assertRoleCannotBypassRls(): void
    {
        $role = DB::connection(self::CONNECTION)->selectOne(
            'select rolsuper, rolbypassrls from pg_roles where rolname = current_user'
        );

        $this->assertFalse((bool) $role->rolsuper, 'The RLS test connects as a SUPERUSER, which bypasses every policy.');
        $this->assertFalse((bool) $role->rolbypassrls, 'The RLS test role has BYPASSRLS, which bypasses every policy.');
    }

    /** One filiale, one user, one article and one alert on each side. */
    private function seedTwoFiliales(): void
    {
        $this->filialeA = $this->insertFiliale('Filiale A');
        $this->filialeB = $this->insertFiliale('Filiale B');

        // Each row is written while acting as its own filiale — the only way the
        // WITH CHECK clause allows it.
        FilialeContext::set($this->filialeB, self::CONNECTION);
        $this->userB = $this->insertUser($this->filialeB);
        $this->articleB = $this->insertArticle($this->userB, $this->filialeB, 'Article de la filiale B');
        $this->alertB = $this->insertAlert($this->articleB, $this->userB, $this->filialeB);

        FilialeContext::set($this->filialeA, self::CONNECTION);
        $userA = $this->insertUser($this->filialeA);
        $this->alertA = $this->insertAlert(
            $this->insertArticle($userA, $this->filialeA, 'Article de la filiale A'),
            $userA,
            $this->filialeA
        );
    }

    private function insertFiliale(string $name): string
    {
        $id = (string) Str::uuid();

        // `filiales` itself is not RLS-protected: it is the lookup table the
        // policies resolve against, and a session must be able to see its own.
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
            'email' => "rls.alert.{$suffix}@example.test",
            'matricule' => 'RLSA-'.$suffix,
            'password' => bcrypt('password123'),
            'filiale_id' => $filialeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertArticle(int $authorId, string $filialeId, string $title): string
    {
        $id = (string) Str::uuid();

        DB::connection(self::CONNECTION)->table('articles')->insert([
            'id' => $id,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::random(8),
            'criticite' => 'note',
            'status' => 'published',
            'published_at' => now(),
            'filiale_id' => $filialeId,
            'author_id' => $authorId,
            'data_owner_id' => $authorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertAlert(string $articleId, int $reportedBy, string $filialeId): string
    {
        $id = (string) Str::uuid();

        DB::connection(self::CONNECTION)->table('article_alerts')->insert([
            'id' => $id,
            'filiale_id' => $filialeId,
            'article_id' => $articleId,
            'reported_by' => $reportedBy,
            'type' => 'obsolescence',
            'criticite' => 'moyenne',
            'description' => 'Écart constaté sur le terrain.',
            'status' => 'ouverte',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

}

<?php

namespace Tests\Feature;

use App\Support\Database\FilialeContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Proves that filiale isolation is enforced by PostgreSQL, not by the
 * application: every assertion below goes through a raw SQL query, with no
 * Eloquent scope, no `where filiale_id = ?`, and no model in the way.
 *
 * The rest of the suite runs on in-memory sqlite (see phpunit.xml), which has
 * no RLS at all — so this test opens a real PostgreSQL connection as the
 * restricted `kh_app` role and skips itself when that is unavailable.
 */
class FilialeRowLevelSecurityTest extends TestCase
{
    private const CONNECTION = 'pgsql_rls_test';

    private string $filialeA;

    private string $filialeB;

    private string $articleA;

    private string $articleB;

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

        // Everything below happens inside one transaction that is rolled back in
        // tearDown, so the test leaves no rows behind.
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
    public function a_user_from_filiale_a_cannot_read_a_row_belonging_to_filiale_b(): void
    {
        FilialeContext::set($this->filialeA, self::CONNECTION);

        // Raw query, no scope, no filter — whatever comes back is what the
        // database decided this session is allowed to see.
        $rows = DB::connection(self::CONNECTION)->select('select id, filiale_id from articles');

        $this->assertCount(1, $rows, 'Session in filiale A saw rows outside its filiale.');
        $this->assertSame($this->articleA, $rows[0]->id);
        $this->assertSame($this->filialeA, $rows[0]->filiale_id);
    }

    #[Test]
    public function filiale_bs_row_is_invisible_even_when_addressed_by_its_primary_key(): void
    {
        FilialeContext::set($this->filialeA, self::CONNECTION);

        // Knowing the exact id changes nothing: the policy is applied before the
        // WHERE clause ever matches.
        $count = DB::connection(self::CONNECTION)
            ->scalar('select count(*) from articles where id = ?', [$this->articleB]);

        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function filiale_a_cannot_modify_or_delete_filiale_bs_row(): void
    {
        FilialeContext::set($this->filialeA, self::CONNECTION);

        $updated = DB::connection(self::CONNECTION)
            ->update('update articles set title = ? where id = ?', ['hijacked', $this->articleB]);

        $deleted = DB::connection(self::CONNECTION)
            ->delete('delete from articles where id = ?', [$this->articleB]);

        $this->assertSame(0, $updated, 'An UPDATE reached across filiales.');
        $this->assertSame(0, $deleted, 'A DELETE reached across filiales.');

        // And the row is genuinely untouched, as seen from filiale B.
        FilialeContext::set($this->filialeB, self::CONNECTION);

        $title = DB::connection(self::CONNECTION)
            ->scalar('select title from articles where id = ?', [$this->articleB]);

        $this->assertSame('Article de la filiale B', $title);
    }

    #[Test]
    public function filiale_a_cannot_write_a_row_into_filiale_b(): void
    {
        FilialeContext::set($this->filialeA, self::CONNECTION);

        $this->expectException(QueryException::class);

        // The policy's WITH CHECK clause rejects this insert outright.
        $this->insertArticle($this->articleAuthorFor($this->filialeA), $this->filialeB, 'Smuggled');
    }

    #[Test]
    public function a_session_with_no_filiale_context_sees_nothing(): void
    {
        FilialeContext::forget(self::CONNECTION);

        $count = DB::connection(self::CONNECTION)->scalar('select count(*) from articles');

        $this->assertSame(0, (int) $count, 'Articles leaked to a session with no filiale context.');
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

    /**
     * One filiale and one article on each side of the fence.
     */
    private function seedTwoFiliales(): void
    {
        $this->filialeA = $this->insertFiliale('Filiale A');
        $this->filialeB = $this->insertFiliale('Filiale B');

        // Each row is written while acting as its own filiale — the only way the
        // WITH CHECK clause allows it.
        FilialeContext::set($this->filialeB, self::CONNECTION);
        $this->articleB = $this->insertArticle(
            $this->insertUser($this->filialeB),
            $this->filialeB,
            'Article de la filiale B'
        );

        FilialeContext::set($this->filialeA, self::CONNECTION);
        $this->articleA = $this->insertArticle(
            $this->insertUser($this->filialeA),
            $this->filialeA,
            'Article de la filiale A'
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
            'name' => 'RLS Test '.$suffix,
            'email' => "rls.{$suffix}@example.test",
            'matricule' => 'RLS-'.$suffix,
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
            'content' => '<p>Contenu de test.</p>',
            'category' => 'news_announcements',
            'status' => 'published',
            'published_at' => now(),
            'filiale_id' => $filialeId,
            'author_id' => $authorId,
            'reading_time_minutes' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function articleAuthorFor(string $filialeId): int
    {
        return (int) DB::connection(self::CONNECTION)
            ->scalar('select author_id from articles where filiale_id = ?', [$filialeId]);
    }
}

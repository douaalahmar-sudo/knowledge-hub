<?php

namespace Tests\Feature;

use App\Models\Procedure;
use App\Models\Role;
use App\Models\Filiale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TriptychUploadTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/procedures/upload-triptych';

    private Filiale $filiale;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->filiale = Filiale::create(['name' => 'Store 101']);
    }

    private function processOwner(): User
    {
        $role = Role::firstOrCreate(['name' => 'process_owner']);

        return User::factory()->create([
            'matricule' => 'PO-'.fake()->unique()->numberBetween(1000, 9999),
            'filiale_id' => $this->filiale->id,
            'role_id' => $role->id,
        ]);
    }

    private function procedure(User $owner): Procedure
    {
        return Procedure::create([
            'filiale_id' => $this->filiale->id,
            'created_by' => $owner->id,
            'reference_code' => 'SOP-PR-001',
            'name' => 'Ouverture de caisse',
            'module' => 'Operations',
            'status' => 'Validé',
        ]);
    }

    public function test_it_stores_all_three_triptych_assets(): void
    {
        $user = $this->processOwner();

        $response = $this->actingAs($user, 'sanctum')->postJson(self::ENDPOINT, [
            'pdf_file' => UploadedFile::fake()->create('sop.pdf', 512, 'application/pdf'),
            'video_file' => UploadedFile::fake()->create('demo.mp4', 2048, 'video/mp4'),
            'infographic_file' => UploadedFile::fake()->image('poster.png', 800, 600),
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'paths' => ['pdf_path', 'video_path', 'infographic_path'], 'urls']);

        foreach ($response->json('paths') as $path) {
            Storage::disk('public')->assertExists($path);
        }

        $this->assertStringStartsWith('triptych/pdf/', $response->json('paths.pdf_path'));
        $this->assertStringStartsWith('triptych/video/', $response->json('paths.video_path'));
        $this->assertStringStartsWith('triptych/infographic/', $response->json('paths.infographic_path'));
    }

    public function test_it_persists_paths_onto_the_procedure_when_id_is_supplied(): void
    {
        $user = $this->processOwner();
        $procedure = $this->procedure($user);

        $response = $this->actingAs($user, 'sanctum')->postJson(self::ENDPOINT, [
            'procedure_id' => $procedure->id,
            'pdf_file' => UploadedFile::fake()->create('sop.pdf', 512, 'application/pdf'),
        ]);

        $response->assertStatus(201);

        $procedure->refresh();
        $this->assertSame($response->json('paths.pdf_path'), $procedure->pdf_path);
        $this->assertNull($procedure->video_path);
        $this->assertSame('1.0', $procedure->version);
        $this->assertTrue($procedure->is_active);
    }

    public function test_it_deletes_the_superseded_asset_on_replacement(): void
    {
        $user = $this->processOwner();
        $procedure = $this->procedure($user);

        $first = $this->actingAs($user, 'sanctum')->postJson(self::ENDPOINT, [
            'procedure_id' => $procedure->id,
            'pdf_file' => UploadedFile::fake()->create('v1.pdf', 100, 'application/pdf'),
        ])->json('paths.pdf_path');

        $this->actingAs($user, 'sanctum')->postJson(self::ENDPOINT, [
            'procedure_id' => $procedure->id,
            'pdf_file' => UploadedFile::fake()->create('v2.pdf', 100, 'application/pdf'),
        ])->assertStatus(201);

        Storage::disk('public')->assertMissing($first);
    }

    public function test_it_rejects_a_disallowed_mime_type(): void
    {
        $user = $this->processOwner();

        $this->actingAs($user, 'sanctum')->postJson(self::ENDPOINT, [
            'pdf_file' => UploadedFile::fake()->create('payload.php', 10, 'application/x-httpd-php'),
        ])->assertStatus(422)->assertJsonValidationErrors('pdf_file');
    }

    public function test_it_rejects_an_oversized_video(): void
    {
        $user = $this->processOwner();

        $this->actingAs($user, 'sanctum')->postJson(self::ENDPOINT, [
            'video_file' => UploadedFile::fake()->create('huge.mp4', 101 * 1024, 'video/mp4'),
        ])->assertStatus(422)->assertJsonValidationErrors('video_file');
    }

    public function test_it_requires_at_least_one_file(): void
    {
        $user = $this->processOwner();

        $this->actingAs($user, 'sanctum')->postJson(self::ENDPOINT, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pdf_file');
    }

    public function test_it_is_closed_to_users_without_manage_procedures(): void
    {
        $role = Role::firstOrCreate(['name' => 'operator']);
        $operator = User::factory()->create([
            'matricule' => 'OP-001',
            'filiale_id' => $this->filiale->id,
            'role_id' => $role->id,
        ]);

        $this->actingAs($operator, 'sanctum')->postJson(self::ENDPOINT, [
            'pdf_file' => UploadedFile::fake()->create('sop.pdf', 10, 'application/pdf'),
        ])->assertStatus(403);
    }

    public function test_reference_code_is_globally_unique(): void
    {
        $user = $this->processOwner();
        $this->procedure($user);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Procedure::create([
            'filiale_id' => $this->filiale->id,
            'created_by' => $user->id,
            'reference_code' => 'SOP-PR-001',
            'name' => 'Doublon',
            'module' => 'Operations',
        ]);
    }
}

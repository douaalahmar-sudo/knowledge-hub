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

/**
 * Contract tests for the /api/v1/procedures surface the Angular triptych
 * form + 3-tab viewer consume. These assert the response *shape* the frontend
 * binds to, so a backend rename cannot silently break the UI.
 */
class ProcedureV1ApiTest extends TestCase
{
    use RefreshDatabase;

    private Filiale $filiale;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->filiale = Filiale::create(['name' => 'Store 101']);
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'matricule' => strtoupper(substr($role, 0, 3)).'-'.fake()->unique()->numberBetween(1000, 9999),
            'filiale_id' => $this->filiale->id,
            'role_id' => Role::firstOrCreate(['name' => $role])->id,
        ]);
    }

    public function test_it_creates_a_procedure_with_the_full_metadata_taxonomy(): void
    {
        $owner = $this->user('process_owner');

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/procedures', [
            'reference_code' => 'SOP-PR-001',
            'name' => 'Ouverture de caisse',
            'module' => 'Operations',
            'category' => 'Procédure standard',
            'department' => 'Caisse',
            'description' => 'Étapes d’ouverture du poste de caisse.',
            'status' => 'Validé',
            'version' => '2.0',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('category', 'Procédure standard')
            ->assertJsonPath('department', 'Caisse')
            ->assertJsonPath('description', 'Étapes d’ouverture du poste de caisse.')
            ->assertJsonPath('version', '2.0');

        $this->assertDatabaseHas('procedures', [
            'reference_code' => 'SOP-PR-001',
            'department' => 'Caisse',
        ]);
    }

    public function test_show_appends_triptych_urls_the_viewer_binds_to(): void
    {
        $owner = $this->user('process_owner');

        $procedure = Procedure::create([
            'filiale_id' => $this->filiale->id,
            'created_by' => $owner->id,
            'reference_code' => 'SOP-PR-002',
            'name' => 'Réception marchandises',
            'module' => 'Logistique',
        ]);

        // Attach only two of the three formats — the viewer must be able to tell
        // which tab to disable.
        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/procedures/upload-triptych', [
            'procedure_id' => $procedure->id,
            'pdf_file' => UploadedFile::fake()->create('sop.pdf', 128, 'application/pdf'),
            'infographic_file' => UploadedFile::fake()->image('schema.png', 900, 600),
        ])->assertStatus(201);

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/procedures/{$procedure->id}");

        $response->assertStatus(200)
            ->assertJsonStructure(['triptych_urls' => ['pdf', 'video', 'infographic']])
            ->assertJsonPath('triptych_urls.video', null);

        $this->assertStringContainsString('triptych/pdf/', $response->json('triptych_urls.pdf'));
        $this->assertStringContainsString('triptych/infographic/', $response->json('triptych_urls.infographic'));
    }

    public function test_patch_updates_metadata_without_creating_a_version(): void
    {
        $owner = $this->user('process_owner');

        $procedure = Procedure::create([
            'filiale_id' => $this->filiale->id,
            'created_by' => $owner->id,
            'reference_code' => 'SOP-PR-003',
            'name' => 'Ancien titre',
            'module' => 'Stock',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/procedures/{$procedure->id}", [
                'name' => 'Nouveau titre',
                'department' => 'Entrepôt',
            ])
            ->assertStatus(200)
            ->assertJsonPath('name', 'Nouveau titre')
            ->assertJsonPath('department', 'Entrepôt');

        $this->assertSame(0, $procedure->versions()->count());
    }

    public function test_patch_allows_keeping_the_same_reference_code(): void
    {
        $owner = $this->user('process_owner');

        $procedure = Procedure::create([
            'filiale_id' => $this->filiale->id,
            'created_by' => $owner->id,
            'reference_code' => 'SOP-PR-004',
            'name' => 'Inventaire',
            'module' => 'Stock',
        ]);

        // The unique rule must ignore the row being edited, or every save that
        // resubmits the unchanged reference would 422.
        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/procedures/{$procedure->id}", [
                'reference_code' => 'SOP-PR-004',
                'name' => 'Inventaire tournant',
            ])
            ->assertStatus(200)
            ->assertJsonPath('name', 'Inventaire tournant');
    }

    public function test_patch_still_rejects_a_reference_code_taken_by_another_procedure(): void
    {
        $owner = $this->user('process_owner');

        Procedure::create([
            'filiale_id' => $this->filiale->id,
            'created_by' => $owner->id,
            'reference_code' => 'SOP-PR-005',
            'name' => 'Existante',
            'module' => 'Stock',
        ]);

        $other = Procedure::create([
            'filiale_id' => $this->filiale->id,
            'created_by' => $owner->id,
            'reference_code' => 'SOP-PR-006',
            'name' => 'Autre',
            'module' => 'Stock',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/procedures/{$other->id}", ['reference_code' => 'SOP-PR-005'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reference_code');
    }

    public function test_reading_is_open_but_writing_requires_manage_procedures(): void
    {
        $operator = $this->user('operator');

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/procedures')
            ->assertStatus(200);

        $this->actingAs($operator, 'sanctum')
            ->postJson('/api/v1/procedures', [
                'reference_code' => 'SOP-PR-007',
                'name' => 'Interdit',
                'module' => 'Operations',
            ])
            ->assertStatus(403);
    }
}

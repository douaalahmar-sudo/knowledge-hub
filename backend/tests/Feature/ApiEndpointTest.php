<?php

namespace Tests\Feature;

use App\Models\Filiale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiEndpointTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test user authentication and Sanctum token issue.
     */
    public function test_user_can_login_and_receive_token(): void
    {
        $filiale = Filiale::create(['name' => 'Test Filiale']);
        $user = User::factory()->create([
            'email'     => 'test@flesk.com',
            'matricule' => 'TST-001',
            'password'  => bcrypt('password123'),
            'filiale_id' => $filiale->id,
        ]);

        $response = $this->postJson('/api/login', [
            'email'    => 'test@flesk.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure(['access_token', 'token_type']);
    }

    /**
     * Test protected endpoints return 401 for unauthenticated requests.
     */
    public function test_unauthenticated_requests_are_blocked(): void
    {
        $response = $this->getJson('/api/procedures');
        $response->assertStatus(401);
    }

    /**
     * Test authenticated access to workflow and kaizen endpoints.
     */
    public function test_authenticated_user_can_access_business_domain_routes(): void
    {
        $filiale = Filiale::create(['name' => 'Test Filiale']);
        $user = User::factory()->create([
            'matricule' => 'TST-002',
            'filiale_id' => $filiale->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
                         ->getJson('/api/kaizen-reports');

        $response->assertStatus(200)
                 ->assertJson(['success' => true]);
    }
}
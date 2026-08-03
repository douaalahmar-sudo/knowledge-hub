<?php

namespace Tests\Feature;

use App\Models\Filiale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/v1/auth/me must report the *client's* IP, not the load balancer's.
 *
 * This is what feeds the article viewer's watermark (cahier des charges §10.3:
 * nom | matricule | adresse IP | horodatage). In production the app sits behind
 * Cloudflare and Render's router, so without the trustProxies() config in
 * bootstrap/app.php every reader would be watermarked with the same internal
 * proxy address — the failure this suite exists to catch.
 */
class ClientIpWatermarkTest extends TestCase
{
    use RefreshDatabase;

    private function reader(): User
    {
        $filiale = Filiale::create(['name' => 'Test Filiale']);

        return User::factory()->create([
            'matricule'  => 'IP-001',
            'filiale_id' => $filiale->id,
        ]);
    }

    /**
     * The production shape: the request arrives at the app from a proxy
     * (REMOTE_ADDR is the proxy) carrying the real client in X-Forwarded-For.
     */
    public function test_me_reports_the_forwarded_client_ip_not_the_proxy(): void
    {
        $response = $this->actingAs($this->reader(), 'sanctum')
            ->withServerVariables(['REMOTE_ADDR' => '10.201.0.7'])
            ->getJson('/api/v1/auth/me', ['X-Forwarded-For' => '196.203.44.12']);

        $response->assertStatus(200)
            ->assertJsonPath('client_ip', '196.203.44.12');
    }

    /**
     * The real deployed shape: browser -> Cloudflare edge -> Render router, so
     * X-Forwarded-For is a chain and only the left-most entry is the reader.
     *
     * This case is the reason bootstrap/app.php trusts a full-range CIDR list
     * rather than Laravel's `at: '*'`. `'*'` trusts the immediate caller only,
     * which strips the Render hop and then reports the Cloudflare edge address
     * (172.71.x here) as the client — a proxy IP on every watermark, which is
     * exactly the outcome the config is meant to prevent. Assert the reader.
     */
    public function test_me_takes_the_originating_client_from_a_proxy_chain(): void
    {
        $response = $this->actingAs($this->reader(), 'sanctum')
            ->withServerVariables(['REMOTE_ADDR' => '10.201.0.7'])
            ->getJson('/api/v1/auth/me', [
                'X-Forwarded-For' => '196.203.44.12, 172.71.18.3',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('client_ip', '196.203.44.12');
    }

    /**
     * Two readers behind the same proxy chain must not collapse onto one
     * address — the property that makes the watermark worth rendering at all.
     */
    public function test_two_readers_behind_the_same_proxy_get_distinct_ips(): void
    {
        $reader = $this->reader();

        $first = $this->actingAs($reader, 'sanctum')
            ->withServerVariables(['REMOTE_ADDR' => '10.201.0.7'])
            ->getJson('/api/v1/auth/me', ['X-Forwarded-For' => '196.203.44.12, 172.71.18.3']);

        $second = $this->actingAs($reader, 'sanctum')
            ->withServerVariables(['REMOTE_ADDR' => '10.201.0.7'])
            ->getJson('/api/v1/auth/me', ['X-Forwarded-For' => '41.226.11.80, 172.71.18.3']);

        $this->assertNotSame(
            $first->json('client_ip'),
            $second->json('client_ip'),
            'Both readers resolved to the same address — the proxy chain is not being unwound.'
        );
        $this->assertSame('41.226.11.80', $second->json('client_ip'));
    }

    /** Local/direct traffic has no forwarding header and must still resolve. */
    public function test_me_falls_back_to_the_direct_remote_address(): void
    {
        $response = $this->actingAs($this->reader(), 'sanctum')
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
            ->assertJsonPath('client_ip', '203.0.113.9');
    }

    /** Request context is not session data: it must not leak into the token payload. */
    public function test_login_payload_does_not_carry_a_client_ip(): void
    {
        $filiale = Filiale::create(['name' => 'Test Filiale']);
        User::factory()->create([
            'email'      => 'ip-check@flesk.com',
            'matricule'  => 'IP-002',
            'password'   => bcrypt('password123'),
            'filiale_id' => $filiale->id,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email'    => 'ip-check@flesk.com',
            'password' => 'password123',
        ])->assertStatus(200)->assertJsonMissingPath('client_ip');
    }
}

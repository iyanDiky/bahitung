<?php

namespace Tests\Feature;

use App\Repositories\Contracts\DatabaseHealthRepository;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_database_failure_returns_service_unavailable_without_exception_details(): void
    {
        $this->mock(DatabaseHealthRepository::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isAvailable')->once()->andThrow(new RuntimeException('private database credentials'));
        });

        $this->getJson('/api/v1/health')->assertStatus(503)->assertExactJson([
            'data' => ['status' => 'unavailable', 'service' => 'bahitung-api', 'database' => 'unavailable'],
        ])->assertDontSee('private database credentials');
    }

    public function test_unavailable_database_returns_service_unavailable(): void
    {
        $this->mock(DatabaseHealthRepository::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isAvailable')->once()->andReturn(false);
        });

        $this->getJson('/api/v1/health')->assertStatus(503)->assertJsonPath('data.database', 'unavailable');
    }

    public function test_local_frontend_origin_is_allowed(): void
    {
        $this->options('/api/v1/health', [], [
            'Origin' => 'http://localhost:5173',
            'Access-Control-Request-Method' => 'GET',
        ])->assertSuccessful()->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }

    public function test_unknown_origin_is_not_allowed(): void
    {
        $this->options('/api/v1/health', [], [
            'Origin' => 'https://untrusted.example',
            'Access-Control-Request-Method' => 'GET',
        ])->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_unknown_api_route_returns_json_without_accept_header(): void
    {
        $this->get('/api/v1/missing')->assertNotFound()->assertHeader('Content-Type', 'application/json');
    }
}

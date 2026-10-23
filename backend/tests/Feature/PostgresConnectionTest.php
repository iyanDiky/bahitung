<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PostgresConnectionTest extends TestCase
{
    public function test_connection_uses_isolated_postgres_database_with_migrations(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('bahitung_testing', DB::selectOne('SELECT current_database() AS name')->name);
        $this->assertSame('UTC', DB::selectOne('SHOW timezone')->TimeZone);
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('migrations'));
    }

    public function test_health_endpoint_checks_real_postgres_connection(): void
    {
        $response = $this->getJson('/api/v1/health')->assertOk()->assertExactJson([
            'data' => ['status' => 'ok', 'service' => 'bahitung-api', 'database' => 'ok'],
        ]);

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }
}

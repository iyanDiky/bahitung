<?php

namespace Tests\Feature;

use App\Models\Household;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WalletConcurrencyTest extends TestCase
{
    #[TestWith([false])]
    #[TestWith([true])]
    public function test_concurrent_creation_with_same_key_is_serialized(bool $differentPayload): void
    {
        $this->assertSame('bahitung_testing', DB::connection()->getDatabaseName());
        $schema = 'wallet_race_'.bin2hex(random_bytes(8));
        DB::statement('CREATE SCHEMA '.$schema);
        $originalPath = config('database.connections.pgsql.search_path');
        $processes = [];
        try {
            config(['database.connections.pgsql.search_path' => $schema]);
            DB::purge('pgsql');
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
            $household = Household::factory()->create();
            $key = (string) Str::uuid();
            $code = <<<'WORKER'
                require 'vendor/autoload.php';
                $app = require 'bootstrap/app.php';
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                config(['database.connections.pgsql.search_path' => getenv('BAHITUNG_TEST_SCHEMA')]);
                Illuminate\Support\Facades\DB::purge('pgsql');
                $user = App\Models\User::findOrFail((int) getenv('BAHITUNG_TEST_USER'));
                echo "ready\n";
                flush();
                try {
                    $result = app(App\Services\WalletService::class)->create($user, (int) getenv('BAHITUNG_TEST_HOUSEHOLD'), [
                        'name' => getenv('BAHITUNG_TEST_WALLET'), 'type' => 'cash', 'opening_amount' => '125.10', 'idempotency_key' => getenv('BAHITUNG_TEST_KEY'),
                    ]);
                    echo json_encode(['status' => 201, 'body' => $result]);
                } catch (App\Exceptions\IdempotencyConflictException $exception) {
                    echo json_encode(['status' => 409]);
                }
                WORKER;
            DB::beginTransaction();
            DB::table('households')->where('id', $household->id)->lockForUpdate()->first();
            foreach (['Cash', $differentPayload ? 'Other' : 'Cash'] as $name) {
                $process = new Process([PHP_BINARY, '-r', $code], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'bahitung_testing',
                    'DB_HOST' => config('database.connections.pgsql.host'), 'DB_PORT' => (string) config('database.connections.pgsql.port'),
                    'DB_USERNAME' => config('database.connections.pgsql.username'), 'DB_PASSWORD' => config('database.connections.pgsql.password'),
                    'BAHITUNG_TEST_SCHEMA' => $schema, 'BAHITUNG_TEST_USER' => (string) $household->created_by_user_id,
                    'BAHITUNG_TEST_HOUSEHOLD' => (string) $household->id, 'BAHITUNG_TEST_KEY' => $key, 'BAHITUNG_TEST_WALLET' => $name,
                ]);
                $process->setTimeout(20);
                $process->start();
                $processes[] = $process;
            }
            foreach ($processes as $process) {
                $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'ready')));
            }
            DB::commit();
            $results = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $results[] = json_decode(trim(str_replace("ready\n", '', $process->getOutput())), true, flags: JSON_THROW_ON_ERROR);
            }
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame($differentPayload ? [201, 409] : [201, 201], $statuses);
            if (! $differentPayload) {
                $this->assertEquals($results[0]['body'], $results[1]['body']);
            }
            foreach (['wallets', 'wallet_balances', 'operations', 'balance_movements', 'idempotency_receipts', 'audit_logs'] as $table) {
                $this->assertSame(1, DB::table($table)->count());
            }
            $this->assertSame('125.10', DB::table('wallet_balances')->value('balance'));
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            config(['database.connections.pgsql.search_path' => $originalPath]);
            DB::purge('pgsql');
            DB::statement('DROP SCHEMA '.$schema.' CASCADE');
        }
    }
}

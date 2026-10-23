<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\User;
use App\Services\GoalService;
use App\Services\OperationPostingService;
use App\Services\WalletService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class GoalConcurrencyTest extends TestCase
{
    #[TestWith([false])]
    #[TestWith([true])]
    public function test_concurrent_goal_withdrawals_and_retries_are_serialized(bool $duplicate): void
    {
        $this->assertSame('bahitung_testing', DB::connection()->getDatabaseName());
        $schema = 'goal_race_'.bin2hex(random_bytes(8));
        DB::statement('CREATE SCHEMA '.$schema);
        $originalPath = config('database.connections.pgsql.search_path');
        $processes = [];
        try {
            config(['database.connections.pgsql.search_path' => $schema]);
            DB::purge('pgsql');
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
            $household = Household::factory()->create();
            $owner = User::findOrFail($household->created_by_user_id);
            $source = app(WalletService::class)->create($owner, $household->id, ['name' => 'Source', 'type' => 'cash', 'opening_amount' => '100000.00', 'opening_on' => '2026-01-01', 'idempotency_key' => (string) Str::uuid()])['data']['id'];
            $goal = app(GoalService::class)->save($owner, $household->id, ['name' => 'Target', 'target_amount' => '100000.00'])['id'];
            app(OperationPostingService::class)->createGoal($owner, $household->id, $goal, ['wallet_id' => $source, 'amount' => '100000.00', 'occurred_on' => '2026-01-02', 'idempotency_key' => (string) Str::uuid()], false);
            $payload = ['destination_wallet_id' => $source, 'amount' => '80000.00', 'occurred_on' => '2026-01-03'];
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
                    $result = app(App\Services\OperationPostingService::class)->createGoal($user, (int) getenv('BAHITUNG_TEST_HOUSEHOLD'), (int) getenv('BAHITUNG_TEST_GOAL'), [...json_decode(getenv('BAHITUNG_TEST_PAYLOAD'), true), 'idempotency_key' => getenv('BAHITUNG_TEST_KEY')], true);
                    echo json_encode(['status' => 201, 'body' => $result]);
                } catch (App\Exceptions\FinancialException $exception) {
                    echo json_encode(['status' => 422, 'code' => $exception->errorCode]);
                }
                WORKER;
            DB::beginTransaction();
            DB::table('households')->where('id', $household->id)->lockForUpdate()->first();
            foreach ([0, 1] as $index) {
                $process = new Process([PHP_BINARY, '-r', $code], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'bahitung_testing',
                    'DB_HOST' => config('database.connections.pgsql.host'), 'DB_PORT' => (string) config('database.connections.pgsql.port'),
                    'DB_USERNAME' => config('database.connections.pgsql.username'), 'DB_PASSWORD' => config('database.connections.pgsql.password'),
                    'BAHITUNG_TEST_GOAL' => (string) $goal, 'BAHITUNG_TEST_SCHEMA' => $schema, 'BAHITUNG_TEST_USER' => (string) $household->created_by_user_id,
                    'BAHITUNG_TEST_HOUSEHOLD' => (string) $household->id, 'BAHITUNG_TEST_KEY' => $duplicate || $index === 0 ? $key : (string) Str::uuid(), 'BAHITUNG_TEST_PAYLOAD' => json_encode($payload),
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
            $this->assertSame($duplicate ? [201, 201] : [201, 422], $statuses);
            if ($duplicate) {
                $this->assertEquals($results[0]['body'], $results[1]['body']);
            }
            $this->assertSame(3, DB::table('operations')->count());
            $this->assertSame(3, DB::table('idempotency_receipts')->count());
            $this->assertSame(5, DB::table('balance_movements')->count());
            $this->assertSame('80000.00', DB::table('wallet_balances')->where('wallet_id', $source)->value('balance'));
            $this->assertSame('20000.00', DB::table('goal_balances')->where('goal_id', $goal)->value('balance'));
            $this->assertSame(0, Artisan::call('goals:reconcile'));
            $this->assertSame(0, Artisan::call('wallets:reconcile'));
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

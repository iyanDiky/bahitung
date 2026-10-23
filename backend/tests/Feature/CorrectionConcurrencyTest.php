<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\User;
use App\Services\CategoryService;
use App\Services\OperationPostingService;
use App\Services\WalletService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CorrectionConcurrencyTest extends TestCase
{
    #[TestWith(['replay'])]
    #[TestWith(['different_keys'])]
    #[TestWith(['correct_vs_void'])]
    public function test_competing_corrections_never_reverse_twice(string $scenario): void
    {
        $this->assertSame('bahitung_testing', DB::connection()->getDatabaseName());
        $schema = 'correction_race_'.bin2hex(random_bytes(8));
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
            $destination = app(WalletService::class)->create($owner, $household->id, ['name' => 'Destination', 'type' => 'cash', 'idempotency_key' => (string) Str::uuid()])['data']['id'];
            $category = app(CategoryService::class)->save($owner, $household->id, ['name' => 'Expense', 'type' => 'expense'])->id;
            $payload = ['type' => 'expense', 'wallet_id' => $source, 'amount' => '80000.00', 'category_id' => $category, 'occurred_on' => '2026-01-02'];
            $operation = app(OperationPostingService::class)->create($owner, $household->id, [...$payload, 'idempotency_key' => (string) Str::uuid()])['data']['id'];
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
                    $correct = getenv('BAHITUNG_TEST_CORRECT') === '1';
                    $attributes = ['reason' => 'Race test', 'idempotency_key' => getenv('BAHITUNG_TEST_KEY')];
                    if ($correct) { $attributes['replacement'] = [...json_decode(getenv('BAHITUNG_TEST_PAYLOAD'), true), 'amount' => '60000.00']; }
                    $result = app(App\Services\CorrectionService::class)->apply($user, (int) getenv('BAHITUNG_TEST_HOUSEHOLD'), (int) getenv('BAHITUNG_TEST_OPERATION'), $attributes, $correct);
                    echo json_encode(['status' => $correct ? 201 : 200, 'body' => $result]);
                } catch (App\Exceptions\FinancialException $exception) {
                    echo json_encode(['status' => $exception->getStatusCode(), 'code' => $exception->errorCode]);
                }
                WORKER;
            DB::beginTransaction();
            DB::table('households')->where('id', $household->id)->lockForUpdate()->first();
            foreach ([0, 1] as $index) {
                $process = new Process([PHP_BINARY, '-r', $code], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'bahitung_testing',
                    'DB_HOST' => config('database.connections.pgsql.host'), 'DB_PORT' => (string) config('database.connections.pgsql.port'),
                    'DB_USERNAME' => config('database.connections.pgsql.username'), 'DB_PASSWORD' => config('database.connections.pgsql.password'),
                    'BAHITUNG_TEST_SCHEMA' => $schema, 'BAHITUNG_TEST_USER' => (string) $household->created_by_user_id,
                    'BAHITUNG_TEST_HOUSEHOLD' => (string) $household->id, 'BAHITUNG_TEST_KEY' => $scenario === 'replay' || $index === 0 ? $key : (string) Str::uuid(), 'BAHITUNG_TEST_PAYLOAD' => json_encode($payload),
                    'BAHITUNG_TEST_OPERATION' => (string) $operation, 'BAHITUNG_TEST_CORRECT' => $scenario === 'correct_vs_void' && $index === 0 ? '1' : '0',
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
            if ($scenario === 'replay') {
                $this->assertSame([200, 200], $statuses);
                $this->assertEquals($results[0]['body'], $results[1]['body']);
            } else {
                $this->assertSame(409, $statuses[1]);
                $this->assertContains($statuses[0], [200, 201]);
                $failed = array_values(array_filter($results, fn (array $result): bool => $result['status'] === 409));
                $this->assertSame('ALREADY_REVERSED', $failed[0]['code']);
            }
            $corrected = in_array(201, $statuses, true);
            $this->assertSame(1, DB::table('operation_reversals')->count());
            $this->assertSame(1, DB::table('balance_movements')->where('entry_kind', 'reversal')->count());
            $this->assertSame($corrected ? 3 : 2, DB::table('operations')->count());
            $this->assertSame(4, DB::table('idempotency_receipts')->count());
            $this->assertSame($corrected ? '40000.00' : '100000.00', DB::table('wallet_balances')->where('wallet_id', $source)->value('balance'));
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

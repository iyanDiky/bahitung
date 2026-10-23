<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\User;
use App\Services\BudgetService;
use App\Services\CategoryService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BudgetConcurrencyTest extends TestCase
{
    #[TestWith([false])]
    #[TestWith([true])]
    public function test_concurrent_copy_or_creation_cannot_duplicate_target_month(bool $competingCreate): void
    {
        $this->assertSame('bahitung_testing', DB::connection()->getDatabaseName());
        $schema = 'budget_race_'.bin2hex(random_bytes(8));
        DB::statement('CREATE SCHEMA '.$schema);
        $originalPath = config('database.connections.pgsql.search_path');
        $processes = [];
        try {
            config(['database.connections.pgsql.search_path' => $schema]);
            DB::purge('pgsql');
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
            $household = Household::factory()->create();
            $owner = User::findOrFail($household->created_by_user_id);
            $category = app(CategoryService::class)->save($owner, $household->id, ['name' => 'Expense', 'type' => 'expense'])->id;
            app(BudgetService::class)->save($owner, $household->id, ['month_start' => '2026-01-01', 'lines' => [['category_id' => $category, 'limit_amount' => '100000.00']]]);
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
                    $service = app(App\Services\BudgetService::class);
                    $result = getenv('BAHITUNG_TEST_CREATE') === '1'
                        ? $service->save($user, (int) getenv('BAHITUNG_TEST_HOUSEHOLD'), ['month_start' => '2026-02-01', 'lines' => [['category_id' => (int) getenv('BAHITUNG_TEST_CATEGORY'), 'limit_amount' => '999.00']]])
                        : $service->copy($user, (int) getenv('BAHITUNG_TEST_HOUSEHOLD'), ['source_month' => '2026-01-01', 'target_month' => '2026-02-01']);
                    echo json_encode(['status' => 201, 'body' => $result]);
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
                    'BAHITUNG_TEST_HOUSEHOLD' => (string) $household->id, 'BAHITUNG_TEST_CATEGORY' => (string) $category, 'BAHITUNG_TEST_CREATE' => $competingCreate && $index === 1 ? '1' : '0',
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
            $this->assertSame([201, 409], $statuses);
            $failed = array_values(array_filter($results, fn (array $result): bool => $result['status'] === 409));
            $this->assertSame('BUDGET_ALREADY_EXISTS', $failed[0]['code']);
            $this->assertSame(2, DB::table('budgets')->count());
            $this->assertSame(2, DB::table('budget_lines')->count());
            $this->assertSame(1, DB::table('budgets')->where('month_start', '2026-02-01')->count());
            $this->assertSame(3, DB::table('audit_logs')->count());
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

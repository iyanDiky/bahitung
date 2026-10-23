<?php

namespace App\Console\Commands;

use App\Repositories\Contracts\GoalRepository;
use Illuminate\Console\Command;

class ReconcileGoalBalances extends Command
{
    protected $signature = 'goals:reconcile {--household= : Batasi ke ID keluarga}';

    protected $description = 'Bandingkan proyeksi saldo dengan seluruh ledger tanpa mengubah data';

    public function handle(GoalRepository $goals): int
    {
        $id = $this->option('household');
        if ($id !== null && (! ctype_digit($id) || (int) $id < 1)) {
            $this->error('ID keluarga harus bilangan bulat positif.');

            return self::INVALID;
        }
        $mismatches = $goals->mismatches($id === null ? null : (int) $id);
        if ($mismatches === []) {
            $this->info('Saldo target sesuai ledger.');

            return self::SUCCESS;
        }
        $this->table(['Household', 'Goal', 'Projection', 'Ledger'], array_map(fn ($row) => (array) $row, $mismatches));
        $this->error('Ditemukan selisih saldo. Tidak ada data yang diubah.');

        return self::FAILURE;
    }
}

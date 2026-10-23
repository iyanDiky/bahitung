<?php

namespace App\Console\Commands;

use App\Repositories\Contracts\WalletRepository;
use Illuminate\Console\Command;

class ReconcileWalletBalances extends Command
{
    protected $signature = 'wallets:reconcile {--household= : Batasi ke ID keluarga}';

    protected $description = 'Bandingkan proyeksi saldo dengan seluruh ledger tanpa mengubah data';

    public function handle(WalletRepository $wallets): int
    {
        $id = $this->option('household');
        if ($id !== null && (! ctype_digit($id) || (int) $id < 1)) {
            $this->error('ID keluarga harus bilangan bulat positif.');

            return self::INVALID;
        }
        $mismatches = $wallets->mismatches($id === null ? null : (int) $id);
        if ($mismatches === []) {
            $this->info('Saldo dompet sesuai ledger.');

            return self::SUCCESS;
        }
        $this->table(['Household', 'Wallet', 'Projection', 'Ledger'], array_map(fn ($row) => (array) $row, $mismatches));
        $this->error('Ditemukan selisih saldo. Tidak ada data yang diubah.');

        return self::FAILURE;
    }
}

<?php

namespace App\Repositories\Contracts;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Wallet;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface WalletRepository
{
    public function list(Household $household, array $filters): LengthAwarePaginator;

    public function find(Household $household, int $id): Wallet;

    public function create(Household $household, HouseholdMember $actor, array $attributes): Wallet;

    public function opening(Wallet $wallet, HouseholdMember $actor, string $amount, string $date, string $key, string $hash): void;

    public function update(Wallet $wallet, array $attributes): void;

    public function movements(Wallet $wallet, array $filters): LengthAwarePaginator;

    public function receipt(Household $household, string $key): ?object;

    public function remember(Household $household, string $key, string $hash, array $response): void;

    public function audit(Wallet $wallet, HouseholdMember $actor, string $action, ?array $before): void;

    public function mismatches(?int $householdId): array;
}

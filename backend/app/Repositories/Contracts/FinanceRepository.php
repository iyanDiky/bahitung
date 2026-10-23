<?php

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface FinanceRepository
{
    public function categories(int $household, array $filters): LengthAwarePaginator;

    public function category(int $household, int $id): object;

    public function categoryNameExists(int $household, string $type, string $name, ?int $except = null): bool;

    public function saveCategory(int $household, array $attributes, ?int $id = null): object;

    public function audit(int $household, int $actor, string $action, ?array $before, array $after): void;

    public function lockWallets(int $household, array $ids): array;

    public function historicalMinimum(int $household, int $wallet, string $date): string;

    public function post(int $household, int $actor, array $payload, string $key, string $hash, array $changes, array $goalChanges = []): array;

    public function remember(int $household, string $key, string $hash, array $response, string $endpoint = 'operations.store', int $status = 201): void;

    public function operations(int $household, array $filters): LengthAwarePaginator;

    public function operation(int $household, int $id): array;

    public function timelineBounds(int $household, int $wallet, array $adjustments, bool $goal = false): object;

    public function reverse(int $household, int $actor, array $original, string $reason): void;

    public function updateBalances(int $household, array $balances): void;
}

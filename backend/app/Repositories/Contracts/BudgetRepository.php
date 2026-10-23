<?php

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface BudgetRepository
{
    public function list(int $household, array $filters): LengthAwarePaginator;

    public function find(int $household, int $id): array;

    public function month(int $household, string $month): ?array;

    public function save(int $household, int $actor, string $month, array $lines, ?int $id = null, ?int $source = null): array;

    public function expenses(int $household, string $month): array;
}

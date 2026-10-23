<?php

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface GoalRepository
{
    public function list(int $household, array $filters): LengthAwarePaginator;

    public function find(int $household, int $id): array;

    public function save(int $household, int $actor, array $attributes, ?int $id = null): array;

    public function lock(int $household, array $ids): array;

    public function updateBalances(int $household, array $balances): void;

    public function movements(int $household, int $id, array $filters): LengthAwarePaginator;

    public function mismatches(?int $household): array;
}

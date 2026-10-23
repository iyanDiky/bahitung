<?php

namespace App\Repositories\Contracts;

use App\Models\User;

interface AuthRepository
{
    public function create(array $attributes): User;

    public function findByEmail(string $email, bool $lock = false): ?User;
}

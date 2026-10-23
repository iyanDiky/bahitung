<?php

namespace App\Repositories;

use App\Models\User;
use App\Repositories\Contracts\AuthRepository;

class EloquentAuthRepository implements AuthRepository
{
    public function create(array $attributes): User
    {
        return User::create($attributes);
    }

    public function findByEmail(string $email, bool $lock = false): ?User
    {
        return User::where('email', $email)->when($lock, fn ($query) => $query->lockForUpdate())->first();
    }
}

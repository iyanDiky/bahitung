<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\Contracts\AuthRepository;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    public function __construct(private AuthRepository $users) {}

    public function register(array $attributes, bool $issueToken = true): array
    {
        try {
            return DB::transaction(function () use ($attributes, $issueToken): array {
                $user = $this->users->create(collect($attributes)->only(['name', 'email', 'password'])->all());

                DB::afterCommit(fn () => event(new Registered($user)));

                return $issueToken ? $this->issueToken($user) : ['user' => $user];
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['email' => ['Email sudah terdaftar.']]);
        }
    }

    public function login(array $credentials, bool $issueToken = true): array
    {
        return DB::transaction(function () use ($credentials, $issueToken): array {
            $user = $this->users->findByEmail($credentials['email'], lock: true);
            $hash = $user?->password ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            $valid = Hash::check($credentials['password'], $hash);
            if (! $user || ! $valid) {
                throw new AuthenticationException('Email atau kata sandi salah.');
            }
            if (Hash::needsRehash($user->password)) {
                $user->forceFill(['password' => Hash::make($credentials['password'])])->save();
            }

            return $issueToken ? $this->issueToken($user) : ['user' => $user];
        });
    }

    public function logout(User $user): void
    {
        if ($user->currentAccessToken() instanceof PersonalAccessToken) {
            $user->currentAccessToken()->delete();
        }
    }

    private function issueToken(User $user): array
    {
        $expires = now()->addMinutes((int) config('sanctum.expiration'));
        $token = $user->createToken('bahitung-api', ['*'], $expires);

        return ['user' => $user, 'token' => $token->plainTextToken, 'token_type' => 'Bearer', 'expires_at' => $expires->toISOString()];
    }
}

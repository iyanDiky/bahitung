<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\Contracts\AuthRepository;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountService
{
    public function __construct(private AuthRepository $users) {}

    public function sendVerification(User $user): void
    {
        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }
    }

    public function verify(EmailVerificationRequest $request): void
    {
        $request->fulfill();
    }

    public function forgot(string $email): void
    {
        DB::transaction(function () use ($email): void {
            $this->users->findByEmail($email, lock: true);
            Password::sendResetLink(['email' => $email]);
        });
    }

    public function reset(array $credentials): void
    {
        DB::transaction(function () use ($credentials): void {
            $this->users->findByEmail($credentials['email'], lock: true);
            $status = Password::reset($credentials, function (User $user, string $password): void {
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                $user->tokens()->delete();
                DB::afterCommit(fn () => event(new PasswordReset($user)));
            });
            if ($status !== Password::PASSWORD_RESET) {
                throw ValidationException::withMessages(['token' => ['Tautan reset tidak valid atau sudah kedaluwarsa.']]);
            }
        });
    }
}

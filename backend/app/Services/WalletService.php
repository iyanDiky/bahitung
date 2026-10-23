<?php

namespace App\Services;

use App\Exceptions\IdempotencyConflictException;
use App\Models\Household;
use App\Models\User;
use App\Models\Wallet;
use App\Repositories\Contracts\HouseholdRepository;
use App\Repositories\Contracts\WalletRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class WalletService
{
    public function __construct(private HouseholdRepository $households, private WalletRepository $wallets) {}

    private function accessible(User $user, int $id, bool $lock = false, bool $write = false): Household
    {
        $household = $this->households->find($id, lock: $lock);
        Gate::forUser($user)->authorize($write ? 'manage-wallets' : 'view-wallets', $household);

        return $household;
    }

    public function list(User $user, int $householdId, array $filters): LengthAwarePaginator
    {
        return $this->wallets->list($this->accessible($user, $householdId), $filters);
    }

    public function show(User $user, int $householdId, int $walletId): Wallet
    {
        return $this->wallets->find($this->accessible($user, $householdId), $walletId);
    }

    public function movements(User $user, int $householdId, int $walletId, array $filters): LengthAwarePaginator
    {
        return $this->wallets->movements($this->show($user, $householdId, $walletId), $filters);
    }

    public function create(User $user, int $householdId, array $attributes): array
    {
        return DB::transaction(function () use ($user, $householdId, $attributes): array {
            $household = $this->accessible($user, $householdId, lock: true, write: true);
            $key = strtolower($attributes['idempotency_key']);
            $payload = ['name' => $attributes['name'], 'type' => $attributes['type'], 'opening_amount' => $attributes['opening_amount'] ?? '0.00', 'opening_on' => $attributes['opening_on'] ?? null];
            $hash = hash('sha256', json_encode(['endpoint' => 'wallets.store', 'payload' => $payload], JSON_THROW_ON_ERROR));
            $receipt = $this->wallets->receipt($household, $key);
            if ($receipt) {
                if ($receipt->endpoint !== 'wallets.store' || ! hash_equals($receipt->payload_hash, $hash)) {
                    throw new IdempotencyConflictException;
                }

                return json_decode($receipt->response_body, true, flags: JSON_THROW_ON_ERROR);
            }
            $today = now($household->timezone)->format('Y-m-d');
            $date = $payload['opening_on'] ?? $today;
            if ($date > $today) {
                throw ValidationException::withMessages(['opening_on' => ['Tanggal saldo awal tidak boleh di masa depan.']]);
            }
            $actor = $this->households->membership($user, $household);
            $wallet = $this->wallets->create($household, $actor, $payload);
            if ($payload['opening_amount'] !== '0.00') {
                $this->wallets->opening($wallet, $actor, $payload['opening_amount'], $date, $key, $hash);
            }
            $wallet = $this->wallets->find($household, $wallet->id);
            $this->wallets->audit($wallet, $actor, 'wallet.created', null);
            $response = ['data' => $wallet->toArray(), 'warnings' => []];
            $this->wallets->remember($household, $key, $hash, $response);

            return $response;
        }, attempts: 3);
    }

    public function update(User $user, int $householdId, int $walletId, array $attributes): Wallet
    {
        return DB::transaction(function () use ($user, $householdId, $walletId, $attributes): Wallet {
            $household = $this->accessible($user, $householdId, lock: true, write: true);
            $wallet = $this->wallets->find($household, $walletId);
            $before = $wallet->only(['id', 'name', 'type', 'is_archived', 'balance']);
            $this->wallets->update($wallet, array_intersect_key($attributes, array_flip(['name', 'type', 'is_archived'])));
            $wallet = $this->wallets->find($household, $walletId);
            $this->wallets->audit($wallet, $this->households->membership($user, $household), 'wallet.updated', $before);

            return $wallet;
        }, attempts: 3);
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GoalMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        $withdraw = $this->routeIs('goals.withdrawals');

        return ['amount' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,16})\.[0-9]{2}\z/', 'not_in:0.00'], 'occurred_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:0001-01-01'], 'note' => ['nullable', 'string', 'max:2000'], 'idempotency_key' => ['required', 'uuid'], 'wallet_id' => [Rule::requiredIf(! $withdraw), Rule::prohibitedIf($withdraw), 'integer', 'min:1'], 'destination_wallet_id' => [Rule::requiredIf($withdraw), Rule::prohibitedIf(! $withdraw), 'integer', 'min:1'], 'goal_id' => ['prohibited'], 'type' => ['prohibited'], 'balance' => ['prohibited'], 'household_id' => ['prohibited'], 'category_id' => ['prohibited'], 'fee_amount' => ['prohibited'], 'movements' => ['prohibited'], 'created_by_member_id' => ['prohibited']];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOperationRequest extends FormRequest
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
        return [...self::payloadRules($this->all()), 'idempotency_key' => ['required', 'uuid']];
    }

    public static function payloadRules(array $input, string $prefix = '', bool $allowOpening = false): array
    {
        $goal = $allowOpening && in_array($input['type'] ?? null, ['goal_contribution', 'goal_withdrawal'], true);
        $withdraw = $goal && $input['type'] === 'goal_withdrawal';
        $transfer = ($input['type'] ?? null) === 'transfer';
        $opening = $allowOpening && ($input['type'] ?? null) === 'opening';
        $money = ['string', 'regex:/\A(?:0|[1-9][0-9]{0,16})\.[0-9]{2}\z/'];
        $rules = [
            'type' => ['required', $allowOpening ? 'in:opening,income,expense,transfer,goal_contribution,goal_withdrawal' : 'in:income,expense,transfer'],
            'amount' => ['required', ...$money, 'not_in:0.00'],
            'occurred_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:0001-01-01'],
            'note' => ['nullable', 'string', 'max:2000'],
            'wallet_id' => [Rule::requiredIf(! $transfer && ! $withdraw), Rule::prohibitedIf($transfer || $withdraw), 'integer', 'min:1'],
            'category_id' => [Rule::requiredIf(! $transfer && ! $opening && ! $goal), Rule::prohibitedIf($transfer || $opening || $goal), 'integer', 'min:1'],
            'source_wallet_id' => [Rule::requiredIf($transfer), Rule::prohibitedIf(! $transfer), 'integer', 'min:1'],
            'goal_id' => [Rule::requiredIf($goal), Rule::prohibitedIf(! $goal), 'integer', 'min:1'],
            'destination_wallet_id' => [Rule::requiredIf($transfer || $withdraw), Rule::prohibitedIf(! $transfer && ! $withdraw), 'integer', 'min:1', 'different:'.$prefix.'source_wallet_id'],
            'fee_amount' => ['sometimes', 'required', Rule::prohibitedIf(! $transfer), ...$money],
            'fee_category_id' => [Rule::requiredIf($transfer && isset($input['fee_amount']) && $input['fee_amount'] !== '0.00'), Rule::prohibitedIf(! $transfer), 'nullable', 'integer', 'min:1'],
            'household_id' => ['prohibited'], 'created_by_member_id' => ['prohibited'], 'status' => ['prohibited'], 'balance' => ['prohibited'], 'movements' => ['prohibited'], 'correction_of_id' => ['prohibited'],
        ];
        $prefixed = [];
        foreach ($rules as $field => $rule) {
            $prefixed[$prefix.$field] = $rule;
        }

        return $prefixed;
    }
}

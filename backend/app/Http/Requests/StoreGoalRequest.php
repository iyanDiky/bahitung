<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:120'], 'target_amount' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,16})\.[0-9]{2}\z/', 'not_in:0.00'], 'target_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:0001-01-01'], 'balance' => ['prohibited'], 'household_id' => ['prohibited'], 'created_by_member_id' => ['prohibited'], 'is_archived' => ['prohibited']];
    }
}

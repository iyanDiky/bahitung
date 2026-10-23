<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public static function monthRules(): array
    {
        return ['required', 'date_format:Y-m-d', 'after_or_equal:0001-01-01', 'regex:/\A[0-9]{4}-(?:0[1-9]|1[0-2])-01\z/'];
    }

    public function rules(): array
    {
        return ['month_start' => self::monthRules(), 'lines' => ['required', 'array', 'min:1', 'max:500'], 'lines.*' => ['required', 'array:category_id,limit_amount'], 'lines.*.category_id' => ['required', 'integer', 'min:1', 'distinct'], 'lines.*.limit_amount' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,16})\.[0-9]{2}\z/', 'not_in:0.00'], 'household_id' => ['prohibited'], 'created_by_member_id' => ['prohibited'], 'copied_from_budget_id' => ['prohibited']];
    }
}

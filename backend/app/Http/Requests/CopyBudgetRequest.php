<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CopyBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['source_month' => StoreBudgetRequest::monthRules(), 'target_month' => [...StoreBudgetRequest::monthRules(), 'after:source_month'], 'household_id' => ['prohibited'], 'lines' => ['prohibited']];
    }
}

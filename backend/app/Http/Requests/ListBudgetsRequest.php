<?php

namespace App\Http\Requests;

class ListBudgetsRequest extends PageRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'from' => ['sometimes', ...StoreBudgetRequest::monthRules()], 'to' => ['sometimes', ...StoreBudgetRequest::monthRules(), ...($this->filled('from') ? ['after_or_equal:from'] : [])]];
    }
}

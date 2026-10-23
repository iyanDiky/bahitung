<?php

namespace App\Http\Requests;

class UpdateBudgetRequest extends StoreBudgetRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'month_start' => ['prohibited']];
    }
}

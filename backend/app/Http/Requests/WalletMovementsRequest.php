<?php

namespace App\Http\Requests;

class WalletMovementsRequest extends PageRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])]];
    }
}

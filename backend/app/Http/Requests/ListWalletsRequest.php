<?php

namespace App\Http\Requests;

class ListWalletsRequest extends PageRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'status' => ['sometimes', 'in:all,active,archived'], 'q' => ['sometimes', 'nullable', 'string', 'max:120']];
    }
}

<?php

namespace App\Http\Requests;

class ListCategoriesRequest extends PageRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'type' => ['sometimes', 'in:income,expense'], 'status' => ['sometimes', 'in:all,active,archived']];
    }
}

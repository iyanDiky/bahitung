<?php

namespace App\Http\Requests;

class UpdateGoalRequest extends StoreGoalRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['name'] = ['sometimes', ...$rules['name']];
        $rules['target_amount'] = ['sometimes', ...$rules['target_amount']];
        $rules['is_archived'] = ['sometimes', 'required', 'boolean'];

        return $rules;
    }
}

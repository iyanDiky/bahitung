<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['sometimes', 'required', 'string', 'max:120'], 'is_archived' => ['sometimes', 'required', 'boolean'], 'type' => ['prohibited'], 'household_id' => ['prohibited']];
    }
}

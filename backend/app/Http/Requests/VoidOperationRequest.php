<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VoidOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'uuid'], 'replacement' => ['prohibited']];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['sometimes', 'required', 'string', 'max:120'], 'type' => ['sometimes', 'required', 'in:cash,bank,ewallet,other'],
            'is_archived' => ['sometimes', 'required', 'boolean'], 'balance' => ['prohibited'], 'opening_amount' => ['prohibited'], 'opening_on' => ['prohibited'],
            'household_id' => ['prohibited'], 'created_by_member_id' => ['prohibited']];
    }

    public function messages(): array
    {
        return ['prohibited' => ':attribute tidak dapat diubah melalui pengaturan dompet.'];
    }
}

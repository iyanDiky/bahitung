<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWalletRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:120'], 'type' => ['required', 'in:cash,bank,ewallet,other'],
            'opening_amount' => ['sometimes', 'required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,16})\.[0-9]{2}\z/'],
            'opening_on' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:0001-01-01'], 'idempotency_key' => ['required', 'uuid'],
            'balance' => ['prohibited'], 'household_id' => ['prohibited'], 'created_by_member_id' => ['prohibited'], 'is_archived' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['opening_amount.string' => 'Nominal harus berupa string desimal, contoh "150000.00".', 'opening_amount.regex' => 'Nominal harus nonnegatif dengan dua angka desimal dan maksimal 17 digit sebelum titik.', 'idempotency_key.required' => 'Header Idempotency-Key wajib diisi.', 'idempotency_key.uuid' => 'Idempotency-Key harus berupa UUID.', 'prohibited' => ':attribute tidak boleh ditentukan melalui endpoint ini.'];
    }
}

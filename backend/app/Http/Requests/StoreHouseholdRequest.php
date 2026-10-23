<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreHouseholdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:150'], 'timezone' => ['sometimes', 'required', 'string', 'max:64', 'timezone'], 'currency_code' => ['prohibited'], 'created_by_user_id' => ['prohibited'], 'role' => ['prohibited'], 'archived_at' => ['prohibited']];
    }

    public function messages(): array
    {
        return ['name.required' => 'Nama keluarga wajib diisi.', 'timezone.timezone' => 'Zona waktu tidak valid.', 'prohibited' => ':attribute tidak boleh diubah melalui endpoint ini.'];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class UpdateMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return ['role' => ['required_without:status', 'in:admin,member,viewer'], 'status' => ['required_without:role', 'in:active,suspended,removed'], 'reason' => ['required', 'string', 'max:500']];
    }

    public function messages(): array
    {
        return ['required' => ':attribute wajib diisi.', 'in' => ':attribute tidak valid.', 'password.min' => 'Kata sandi minimal 12 karakter.', 'password.confirmed' => 'Konfirmasi kata sandi tidak cocok.'];
    }
}

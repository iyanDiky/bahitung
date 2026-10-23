<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class InviteMemberRequest extends FormRequest
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
        return ['email' => ['required', 'string', 'email', 'max:255'], 'role' => ['required', 'in:member,viewer']];
    }

    public function messages(): array
    {
        return ['required' => ':attribute wajib diisi.', 'in' => ':attribute tidak valid.', 'password.min' => 'Kata sandi minimal 12 karakter.', 'password.confirmed' => 'Konfirmasi kata sandi tidak cocok.'];
    }
}
